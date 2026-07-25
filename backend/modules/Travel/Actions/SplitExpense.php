<?php

declare(strict_types=1);

namespace Modules\Travel\Actions;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Ledger\Actions\ExchangeRateResolver;
use Modules\Ledger\Models\Category;
use Modules\Travel\Exceptions\TravelException;
use Modules\Travel\Models\SplitExpense as SplitExpenseRecord;
use Modules\Travel\Models\SplitShare;
use Modules\Travel\Models\Trip;
use Modules\Travel\Models\TripMember;

/**
 * Records a trip expense and splits it across the members it was for.
 *
 * The one invariant: the shares sum to the expense exactly, in the currency it
 * was paid in *and* in the trip's base currency. A cent that disappears here
 * reappears at settlement as a debt nobody can pay off, so every mode routes
 * through Money's allocation helpers rather than dividing and rounding.
 *
 * @phpstan-type SplitExpensePayload array{
 *   id?: string,
 *   payer_member_id: string,
 *   amount: int,
 *   currency?: string|null,
 *   fx_rate?: float|string|null,
 *   category_id?: string|null,
 *   occurred_at?: \DateTimeInterface|string|null,
 *   description?: string|null,
 *   latitude?: float|null,
 *   longitude?: float|null,
 *   mode?: string,
 *   participants?: list<array{member_id: string, percent?: string|float|int, weight?: int, amount?: int}>,
 * }
 */
final readonly class SplitExpense
{
    public function __construct(
        private ExchangeRateResolver $rates,
    ) {}

    /**
     * @param  SplitExpensePayload  $data
     */
    public function handle(Trip $trip, array $data): SplitExpenseRecord
    {
        $members = $trip->members()->get()->keyBy('id');
        $mode = $data['mode'] ?? SplitShare::MODE_EQUAL;

        if (! in_array($mode, SplitShare::MODES, true)) {
            throw TravelException::unknownSplitMode($mode);
        }

        $payer = $members->get($data['payer_member_id'])
            ?? throw TravelException::memberNotInTrip($data['payer_member_id']);

        $currency = Currency::of($data['currency'] ?? $trip->base_currency);
        $amount = new Money((int) $data['amount'], $currency);

        if ($amount->isNegative()) {
            throw TravelException::negativeAmount();
        }

        if ($amount->isZero()) {
            throw TravelException::zeroAmount();
        }

        $baseCurrency = $trip->baseCurrency();
        // isset() is already false for a null fx_rate, so this covers both the
        // absent key and an explicit null.
        $rate = isset($data['fx_rate'])
            ? (string) $data['fx_rate']
            : $this->rates->rate($currency, $baseCurrency);
        $baseAmount = $amount->convertTo($baseCurrency, $rate);

        $participants = $this->participants($data['participants'] ?? null, $members, $mode);
        $allocation = $this->allocate($mode, $amount, $baseAmount, $participants);

        return DB::transaction(function () use (
            $data, $trip, $payer, $amount, $baseAmount, $rate, $mode, $allocation
        ): SplitExpenseRecord {
            $expense = new SplitExpenseRecord;

            if (! empty($data['id'])) {
                // Client-generated ULID: an expense created offline keeps the
                // identity it was born with.
                $expense->id = $data['id'];
            }

            $expense->fill([
                'trip_id' => $trip->id,
                'payer_member_id' => $payer->id,
                'amount' => $amount->minorUnits,
                'currency' => $amount->currency->code,
                'fx_rate' => $rate,
                'base_amount' => $baseAmount->minorUnits,
                'category_id' => $this->resolveCategoryId($data['category_id'] ?? null),
                'occurred_at' => $this->resolveOccurredAt($data['occurred_at'] ?? null),
                'description' => $data['description'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
            ]);

            $expense->save();

            foreach ($allocation as $memberId => [$share, $baseShare]) {
                SplitShare::query()->create([
                    'split_expense_id' => $expense->id,
                    'member_id' => $memberId,
                    'share_amount' => $share->minorUnits,
                    'base_share_amount' => $baseShare->minorUnits,
                    'mode' => $mode,
                ]);
            }

            $saved = $expense->fresh(['shares', 'payer', 'trip']);

            if ($saved === null) {
                // The expense we just wrote is gone; returning the in-memory
                // copy would hand back shares the trip no longer holds.
                throw TravelException::expenseNotFound($expense->id);
            }

            return $saved;
        });
    }

    /**
     * @param  list<array<string, mixed>>|null  $given
     * @param  Collection<string, TripMember>  $members
     * @return list<array<string, mixed>>
     */
    private function participants(?array $given, Collection $members, string $mode): array
    {
        if ($given === null || $given === []) {
            if ($mode === SplitShare::MODE_PERCENT || $mode === SplitShare::MODE_EXACT) {
                // There is no sensible default for these: the caller has to say
                // what each person's percentage or amount is.
                throw TravelException::noParticipants();
            }

            $given = $members->map(fn (TripMember $member) => ['member_id' => $member->id])->values()->all();
        }

        if ($given === []) {
            throw TravelException::noParticipants();
        }

        $seen = [];

        foreach ($given as $participant) {
            $memberId = (string) ($participant['member_id'] ?? '');

            if (! $members->has($memberId)) {
                throw TravelException::memberNotInTrip($memberId);
            }

            if (isset($seen[$memberId])) {
                throw TravelException::duplicateParticipant($memberId);
            }

            $seen[$memberId] = true;
        }

        return array_values($given);
    }

    /**
     * Splits the expense, returning member id → [share, base share].
     *
     * Both allocations are driven by the same weights, so a member's slice of
     * the base amount is their slice of the expense — not their share
     * converted on its own, which would round every share independently and
     * leave the totals disagreeing.
     *
     * @param  list<array<string, mixed>>  $participants
     * @return array<string, array{0: Money, 1: Money}>
     */
    private function allocate(string $mode, Money $amount, Money $baseAmount, array $participants): array
    {
        $ids = array_map(fn (array $p) => (string) $p['member_id'], $participants);

        [$shares, $baseShares] = match ($mode) {
            SplitShare::MODE_EQUAL => [
                $amount->allocateEvenly(count($participants)),
                $baseAmount->allocateEvenly(count($participants)),
            ],
            SplitShare::MODE_PERCENT => $this->byWeights(
                $amount, $baseAmount, $this->percentWeights($participants)
            ),
            SplitShare::MODE_WEIGHT => $this->byWeights(
                $amount, $baseAmount, $this->explicitWeights($participants)
            ),
            SplitShare::MODE_EXACT => $this->exact($amount, $baseAmount, $participants),
            // handle() rejects unknown modes, but without this arm a new mode
            // added to SplitShare::MODES would fail here with an
            // UnhandledMatchError instead of the module's own refusal.
            default => throw TravelException::unknownSplitMode($mode),
        };

        $allocation = [];

        foreach ($ids as $index => $memberId) {
            $allocation[$memberId] = [$shares[$index], $baseShares[$index]];
        }

        return $allocation;
    }

    /**
     * @param  list<int>  $weights
     * @return array{0: list<Money>, 1: list<Money>}
     */
    private function byWeights(Money $amount, Money $baseAmount, array $weights): array
    {
        if (array_sum($weights) <= 0) {
            throw TravelException::nonPositiveWeights();
        }

        return [$amount->allocateByWeights($weights), $baseAmount->allocateByWeights($weights)];
    }

    /**
     * Percentages become basis points so the "sums to 100" check is an integer
     * comparison; 33.33 + 33.33 + 33.34 as floats does not reliably equal 100.
     *
     * @param  list<array<string, mixed>>  $participants
     * @return list<int>
     */
    private function percentWeights(array $participants): array
    {
        $weights = [];

        foreach ($participants as $participant) {
            $weights[] = $this->basisPoints((string) ($participant['percent'] ?? '0'));
        }

        $total = array_sum($weights);

        if ($total !== 10_000) {
            $decimal = number_format($total / 100, 2, '.', '');

            throw TravelException::percentagesDoNotSum($decimal);
        }

        return $weights;
    }

    /**
     * @param  list<array<string, mixed>>  $participants
     * @return list<int>
     */
    private function explicitWeights(array $participants): array
    {
        return array_map(
            fn (array $participant) => max(0, (int) ($participant['weight'] ?? 1)),
            $participants,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $participants
     * @return array{0: list<Money>, 1: list<Money>}
     */
    private function exact(Money $amount, Money $baseAmount, array $participants): array
    {
        $shares = [];

        foreach ($participants as $participant) {
            $share = (int) ($participant['amount'] ?? 0);

            if ($share < 0) {
                throw TravelException::negativeShare((string) $participant['member_id']);
            }

            $shares[] = $share;
        }

        $total = array_sum($shares);

        if ($total !== $amount->minorUnits) {
            throw TravelException::exactSharesDoNotSum($total, $amount->minorUnits);
        }

        return [
            array_map(fn (int $share) => new Money($share, $amount->currency), $shares),
            // The base amount is split in the same proportions rather than
            // converting each share, so the two totals cannot drift apart.
            $baseAmount->allocateByWeights($shares),
        ];
    }

    private function basisPoints(string $percent): int
    {
        $percent = str_replace([' ', ',', '_', '%'], '', trim($percent));

        if (! preg_match('/^(\d*)(?:\.(\d*))?$/', $percent, $matches)) {
            throw TravelException::percentagesDoNotSum($percent);
        }

        $whole = $matches[1] === '' ? '0' : $matches[1];
        $fraction = $matches[2] ?? '';

        if (strlen($fraction) > 2) {
            throw TravelException::percentagesDoNotSum($percent);
        }

        return (int) ($whole.str_pad($fraction, 2, '0'));
    }

    private function resolveCategoryId(?string $categoryId): ?string
    {
        if ($categoryId === null) {
            return null;
        }

        // Reading it through the model applies the workspace scope, so a
        // category from another workspace reads as missing.
        return Category::query()->whereKey($categoryId)->value('id');
    }

    private function resolveOccurredAt(mixed $value): \DateTimeInterface
    {
        return match (true) {
            $value === null => now(),
            $value instanceof \DateTimeInterface => $value,
            default => new \DateTimeImmutable((string) $value),
        };
    }
}
