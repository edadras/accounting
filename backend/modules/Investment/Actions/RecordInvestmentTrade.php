<?php

declare(strict_types=1);

namespace Modules\Investment\Actions;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\WorkspaceContext;
use Modules\Investment\Exceptions\InvestmentException;
use Modules\Investment\Models\Investment;
use Modules\Investment\Models\InvestmentTransaction;
use Modules\Investment\Support\Quantity;
use Modules\Ledger\Actions\ExchangeRateResolver;

/**
 * Applies a trade to a position and writes the history row behind it.
 *
 * The rules that matter:
 *   - A buy blends into the weighted-average cost. Lots are not tracked
 *     separately, so the average is the only record of what the units cost.
 *   - A sell realises profit against that average and leaves the average
 *     alone. Moving it would rewrite the cost of units the user still holds
 *     and quietly change every past report.
 *   - Selling more than is held is refused rather than clamped: a negative
 *     position is not a thing the user can own.
 *
 * Like the ledger, sign lives in the action, not in the amounts: `base_amount`
 * is always the magnitude of money that moved.
 */
final readonly class RecordInvestmentTrade
{
    public function __construct(
        private WorkspaceContext $context,
        private ExchangeRateResolver $rates,
    ) {}

    /**
     * @param  array{
     *   id?: string,
     *   investment_id: string,
     *   action: string,
     *   quantity?: string|int|null,
     *   price?: int,
     *   fee?: int,
     *   currency?: string|null,
     *   fx_rate?: float|string|null,
     *   occurred_at?: \DateTimeInterface|string|null,
     *   notes?: string|null,
     *   transaction_id?: string|null,
     *   idempotency_key?: string|null,
     * }  $data
     */
    public function handle(array $data): InvestmentTransaction
    {
        $workspace = $this->context->require();
        $action = (string) $data['action'];

        if (! in_array($action, InvestmentTransaction::ACTIONS, true)) {
            throw InvestmentException::unknownAction($action);
        }

        $idempotencyKey = $data['idempotency_key'] ?? null;

        if ($idempotencyKey !== null) {
            $existing = InvestmentTransaction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $investment = Investment::query()->findOr(
            $data['investment_id'],
            callback: fn () => throw InvestmentException::investmentNotFound($data['investment_id']),
        );

        $currency = Currency::of($data['currency'] ?? $investment->currency);

        if (! $currency->equals($investment->priceCurrency())) {
            throw InvestmentException::currencyMismatch($currency->code, $investment->currency);
        }

        $price = (int) ($data['price'] ?? 0);
        $fee = (int) ($data['fee'] ?? 0);

        if ($price < 0) {
            throw InvestmentException::negativePrice();
        }

        if ($fee < 0) {
            throw InvestmentException::negativeFee();
        }

        $quantity = $this->resolveQuantity($data['quantity'] ?? null, $action, $investment);
        $outcome = $this->apply($action, $investment, $quantity, $price, $fee);

        $baseCurrency = Currency::of($workspace->base_currency);
        // isset() is already false for a null fx_rate, so this covers both the
        // absent key and an explicit null.
        $rate = isset($data['fx_rate'])
            ? (string) $data['fx_rate']
            : $this->rates->rate($currency, $baseCurrency);
        $baseAmount = Money::of($outcome['net'], $currency)->convertTo($baseCurrency, $rate);

        return DB::transaction(function () use (
            $data, $action, $investment, $quantity, $price, $fee, $currency,
            $rate, $baseAmount, $baseCurrency, $outcome, $idempotencyKey
        ): InvestmentTransaction {
            $investment->forceFill([
                'quantity' => Quantity::format($outcome['quantity']),
                'avg_buy_price' => $outcome['avg_buy_price'],
                'realized_profit' => (int) $investment->realized_profit + $outcome['realized'],
            ])->save();

            $row = new InvestmentTransaction;

            if (! empty($data['id'])) {
                // Client-generated ULID: an offline trade keeps the identity it
                // was created with.
                $row->id = $data['id'];
            }

            $row->fill([
                'investment_id' => $investment->id,
                'action' => $action,
                'quantity' => Quantity::format($quantity),
                'price' => $price,
                'fee' => $fee,
                'currency' => $currency->code,
                'fx_rate' => $rate,
                'base_amount' => $baseAmount->minorUnits,
                'base_currency' => $baseCurrency->code,
                'realized_profit' => $outcome['realized'],
                'occurred_at' => $this->resolveOccurredAt($data['occurred_at'] ?? null),
                'notes' => $data['notes'] ?? null,
                'transaction_id' => $data['transaction_id'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ]);

            $row->save();

            $saved = $row->fresh(['investment']);

            if ($saved === null) {
                // The trade we just wrote is gone; the in-memory copy would
                // report a position the holding no longer reflects.
                throw InvestmentException::transactionNotFound($row->id);
            }

            return $saved;
        });
    }

    /**
     * @return array{quantity:int, avg_buy_price:int, realized:int, net:int}
     */
    private function apply(string $action, Investment $investment, int $quantity, int $price, int $fee): array
    {
        $held = $investment->quantityUnits();
        $average = (int) $investment->avg_buy_price;

        return match ($action) {
            InvestmentTransaction::BUY => [
                'quantity' => $held + $quantity,

                // Fees are a cost of trading, not part of what a unit is worth,
                // so they stay out of the average and show up in realised
                // profit when the units are sold.
                'avg_buy_price' => Quantity::weightedAveragePrice($held, $average, $quantity, $price),
                'realized' => 0,
                'net' => Quantity::valueOf($quantity, $price) + $fee,
            ],

            InvestmentTransaction::SELL => $this->applySell($held, $average, $quantity, $price, $fee),

            InvestmentTransaction::DIVIDEND => [
                'quantity' => $held,
                'avg_buy_price' => $average,
                'realized' => Quantity::valueOf($quantity, $price) - $fee,
                'net' => Quantity::valueOf($quantity, $price) - $fee,
            ],

            InvestmentTransaction::FEE => [
                'quantity' => $held,
                'avg_buy_price' => $average,
                'realized' => -$fee,
                'net' => $fee,
            ],

            InvestmentTransaction::SPLIT => $this->applySplit($held, $average, $quantity),

            default => throw InvestmentException::unknownAction($action),
        };
    }

    /** @return array{quantity:int, avg_buy_price:int, realized:int, net:int} */
    private function applySell(int $held, int $average, int $quantity, int $price, int $fee): array
    {
        if ($quantity > $held) {
            throw InvestmentException::insufficientQuantity(
                Quantity::format($held),
                Quantity::format($quantity),
            );
        }

        return [
            'quantity' => $held - $quantity,

            // Unchanged on purpose: the units still held cost what they cost.
            'avg_buy_price' => $average,

            // (sell price − average cost) × units sold, then fees. One rounding
            // step rather than two, so proceeds and cost cannot disagree by a
            // stray minor unit.
            'realized' => Quantity::valueOf($quantity, $price - $average) - $fee,
            'net' => Quantity::valueOf($quantity, $price) - $fee,
        ];
    }

    /**
     * A split changes how the position is sliced, never what it cost. The new
     * average is derived from the preserved cost basis rather than divided by
     * the ratio, so a 3-for-1 split cannot lose a minor unit.
     *
     * @return array{quantity:int, avg_buy_price:int, realized:int, net:int}
     */
    private function applySplit(int $held, int $average, int $ratio): array
    {
        if ($ratio <= 0) {
            throw InvestmentException::invalidSplitRatio(Quantity::format($ratio));
        }

        $quantity = Quantity::divideRoundHalfUp($held * $ratio, Quantity::FACTOR);

        return [
            'quantity' => $quantity,
            'avg_buy_price' => $quantity === 0
                ? $average
                : Quantity::divideRoundHalfUp($held * $average, $quantity),
            'realized' => 0,
            'net' => 0,
        ];
    }

    private function resolveQuantity(string|int|null $value, string $action, Investment $investment): int
    {
        // A dividend is paid on whatever is held unless the caller says
        // otherwise, which is the common case and saves the client a lookup.
        if ($value === null && $action === InvestmentTransaction::DIVIDEND) {
            return $investment->quantityUnits();
        }

        if ($value === null && $action === InvestmentTransaction::FEE) {
            return 0;
        }

        $quantity = Quantity::parse((string) ($value ?? '0'));

        if ($quantity <= 0 && $action !== InvestmentTransaction::FEE) {
            throw InvestmentException::nonPositiveQuantity();
        }

        return $quantity;
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
