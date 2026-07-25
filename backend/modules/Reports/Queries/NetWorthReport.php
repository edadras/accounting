<?php

declare(strict_types=1);

namespace Modules\Reports\Queries;

use App\Core\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Actions\ExchangeRateResolver;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Entry;
use Modules\Reports\Support\Bucket;
use Modules\Reports\Support\DateRange;
use Modules\Reports\Support\MoneyView;

/**
 * What everything is worth right now, plus how it got there.
 *
 * `total` is the sum of every account balance converted to the workspace base
 * currency — nothing else. Transactions are not consulted for it: the balances
 * are the answer, and deriving the same number a second way would only create
 * a chance for the two to disagree.
 *
 * The series is built from entries because a balance has no history: each point
 * is opening balance plus every posting up to and including that day, converted
 * at today's rate. Converting the whole series at one rate is deliberate — it
 * shows how holdings moved, not how the FX market did.
 */
final class NetWorthReport extends Report
{
    public function __construct(
        WorkspaceContext $context,
        private readonly ExchangeRateResolver $rates,
    ) {
        parent::__construct($context);
    }

    /** @return array<string, mixed> */
    public function handle(DateRange $range, Bucket $bucket = Bucket::Month): array
    {
        $base = $this->baseCurrency();
        $accounts = Account::query()->orderBy('sort_order')->orderBy('name')->get();

        $total = 0;
        $breakdown = [];

        foreach ($accounts as $account) {
            $converted = $this->toBase($account->balance());
            $total += $converted->minorUnits;

            $breakdown[] = [
                'account_id' => $account->id,
                'name' => $account->name,
                'type' => $account->type,
                'balance' => MoneyView::of($account->balance()),
                'base_balance' => MoneyView::of($converted),
                'archived' => $account->isArchived(),
            ];
        }

        return [
            'report' => 'net-worth',
            'meta' => $this->meta($range, $bucket),
            'total' => MoneyView::of(Money::of($total, $base)),
            'accounts' => $breakdown,
            'series' => $this->series($range, $bucket, $accounts),
        ];
    }

    /**
     * @param  EloquentCollection<int, Account>  $accounts
     * @return list<array<string, mixed>>
     */
    private function series(DateRange $range, Bucket $bucket, EloquentCollection $accounts): array
    {
        $periods = $bucket->periodsFor($range);

        if ($periods === [] || $accounts->isEmpty()) {
            return [];
        }

        $base = $this->baseCurrency();

        /** @var array<string, int> $running */
        $running = [];

        foreach ($accounts as $account) {
            $running[$account->id] = (int) $account->opening_balance;
        }

        // Not lower-bounded: a point on the series is a balance, so everything
        // that ever happened before it has to be in it.
        $deltas = Entry::query()
            ->whereIn('account_id', $accounts->pluck('id')->all())
            ->where('occurred_at', '<', $range->endExclusive)
            ->selectRaw("account_id, DATE(occurred_at) as day, SUM(CASE WHEN direction = 'debit' THEN amount ELSE -amount END) as delta")
            ->groupByRaw('account_id, DATE(occurred_at)')
            ->get()
            ->map(static fn (Entry $row): object => (object) [
                'account_id' => (string) $row->getAttribute('account_id'),
                'day' => CarbonImmutable::parse((string) $row->getAttribute('day')),
                'delta' => (int) $row->getAttribute('delta'),
            ])
            ->sortBy(static fn (object $row): string => $row->day->format('Y-m-d'))
            ->values();

        $cursor = 0;
        $points = [];

        foreach ($periods as $period) {
            while (($row = $deltas->get($cursor)) !== null && $row->day < $period->endExclusive) {
                $running[$row->account_id] = ($running[$row->account_id] ?? 0) + $row->delta;
                $cursor++;
            }

            $value = 0;

            foreach ($accounts as $account) {
                $value += $this->toBase(
                    Money::of($running[$account->id], $account->currency)
                )->minorUnits;
            }

            $points[] = [
                ...$period->toArray(),
                'net_worth' => MoneyView::of(Money::of($value, $base)),
            ];
        }

        return $points;
    }

    private function toBase(Money $money): Money
    {
        $base = $this->baseCurrency();

        return $money->convertTo($base, $this->rates->rate($money->currency, $base));
    }
}
