<?php

declare(strict_types=1);

namespace Modules\AI\Tools;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Contracts\Tool;
use Modules\Core\Support\WorkspaceContext;
use Modules\Investment\Models\Investment;
use Modules\Ledger\Actions\ExchangeRateResolver;

/**
 * "کدام سرمایه سود بیشتری داده؟" — one of the v1 target questions in
 * docs/08-ai-layer.md §5.
 *
 * Every figure comes off the Investment model rather than being recomputed
 * here, so the number the chat quotes is the number the investments screen
 * shows. The one thing this tool adds is the base currency: a portfolio of
 * gold in IRR and a stock in USD has no meaningful total until both are
 * expressed in the same unit, and the model cannot be trusted to convert.
 *
 * Realised and unrealised profit stay separate all the way to the answer.
 * Merging them would let a position that was sold at a profit years ago hide
 * the fact that what is still held is under water.
 */
final class GetInvestmentPerformance implements Tool
{
    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly ExchangeRateResolver $rates,
    ) {}

    public function name(): string
    {
        return 'get_investment_performance';
    }

    public function description(): string
    {
        return 'Every investment position with its cost, current value, realised and unrealised profit and ROI, plus the portfolio total in the base currency.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kind' => [
                    'type' => 'string',
                    'enum' => Investment::KINDS,
                    'description' => 'Restrict to one asset class.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => (int) config('ai.chat.max_rows', 50),
                    'description' => 'How many positions to return, best performing first.',
                ],
            ],
        ];
    }

    public function run(array $arguments): array
    {
        $base = Currency::of($this->context->baseCurrency());

        // The Investment module is optional in a deployment, exactly as Budget
        // and Banking are for the other tools.
        if (! Schema::hasTable('investments')) {
            return $this->empty($base);
        }

        $kind = (string) ($arguments['kind'] ?? '');

        $query = Investment::query()->orderBy('name');

        if (in_array($kind, Investment::KINDS, true)) {
            $query->ofKind($kind);
        }

        $positions = [];
        $costBasis = Money::zero($base);
        $currentValue = Money::zero($base);
        $realized = Money::zero($base);
        $unrealized = Money::zero($base);

        foreach ($query->get() as $investment) {
            $currency = $investment->priceCurrency();

            $positionCost = $this->toBase($investment->costBasis(), $base);
            $positionValue = $this->toBase($investment->currentValue(), $base);
            $positionRealized = $this->toBase($investment->realizedProfit(), $base);
            $positionUnrealized = $this->toBase($investment->unrealizedProfit(), $base);

            $costBasis = $costBasis->plus($positionCost);
            $currentValue = $currentValue->plus($positionValue);
            $realized = $realized->plus($positionRealized);
            $unrealized = $unrealized->plus($positionUnrealized);

            $positions[] = [
                'id' => $investment->id,
                'name' => $investment->name,
                'kind' => $investment->kind,
                'symbol' => $investment->symbol,
                'quantity' => (string) $investment->quantity,
                'currency' => $currency->code,
                'avg_buy_price' => $investment->avgBuyPrice()->minorUnits,

                // Null rather than the fallback price, so an answer can say a
                // position has never been priced instead of implying it has
                // simply not moved.
                'current_price' => $investment->currentPrice()?->minorUnits,
                'priced_at' => $investment->priced_at?->toDateString(),
                'cost_basis' => $investment->costBasis()->minorUnits,
                'current_value' => $investment->currentValue()->minorUnits,
                'realized_profit' => $investment->realizedProfit()->minorUnits,
                'unrealized_profit' => $investment->unrealizedProfit()->minorUnits,
                'total_profit' => $investment->totalProfit()->minorUnits,
                'roi_percent' => $investment->roi(),
                'base_currency' => $base->code,
                'cost_basis_in_base' => $positionCost->minorUnits,
                'current_value_in_base' => $positionValue->minorUnits,
                'realized_profit_in_base' => $positionRealized->minorUnits,
                'unrealized_profit_in_base' => $positionUnrealized->minorUnits,
                'total_profit_in_base' => $positionRealized->plus($positionUnrealized)->minorUnits,
            ];
        }

        // "Which one did best" is the question being asked, so the answer
        // arrives already in that order.
        usort($positions, static fn (array $a, array $b): int => $b['total_profit_in_base'] <=> $a['total_profit_in_base']);

        $limit = $this->limit($arguments['limit'] ?? null, count($positions));
        $totalProfit = $realized->plus($unrealized);

        return [
            'currency' => $base->code,
            'count' => count($positions),
            'positions' => array_slice($positions, 0, $limit),
            'portfolio' => [
                'cost_basis' => $costBasis->minorUnits,
                'current_value' => $currentValue->minorUnits,
                'realized_profit' => $realized->minorUnits,
                'unrealized_profit' => $unrealized->minorUnits,
                'total_profit' => $totalProfit->minorUnits,
                'roi_percent' => $costBasis->minorUnits === 0
                    ? 0.0
                    : round($totalProfit->minorUnits / $costBasis->minorUnits * 100, 6),
            ],
            'best' => $positions[0]['name'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function empty(Currency $base): array
    {
        return [
            'currency' => $base->code,
            'count' => 0,
            'positions' => [],
            'portfolio' => [
                'cost_basis' => 0,
                'current_value' => 0,
                'realized_profit' => 0,
                'unrealized_profit' => 0,
                'total_profit' => 0,
                'roi_percent' => 0.0,
            ],
            'best' => null,
        ];
    }

    private function toBase(Money $amount, Currency $base): Money
    {
        return $amount->currency->equals($base)
            ? $amount
            : $amount->convertTo($base, $this->rates->rate($amount->currency, $base));
    }

    private function limit(mixed $value, int $fallback): int
    {
        $max = (int) config('ai.chat.max_rows', 50);

        return max(1, min($max, is_numeric($value) ? (int) $value : max(1, $fallback)));
    }
}
