<?php

declare(strict_types=1);

namespace Modules\AI\Tools;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Modules\AI\Contracts\Tool;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Actions\ExchangeRateResolver;
use Modules\Ledger\Models\Account;

final class GetAccountBalances implements Tool
{
    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly ExchangeRateResolver $rates,
    ) {}

    public function name(): string
    {
        return 'get_account_balances';
    }

    public function description(): string
    {
        return 'Current balance of every active account, plus the total in the workspace base currency.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass];
    }

    public function run(array $arguments): array
    {
        $base = Currency::of($this->context->baseCurrency());
        $total = Money::zero($base);
        $accounts = [];

        foreach (Account::query()->whereNull('archived_at')->orderBy('sort_order')->get() as $account) {
            $currency = Currency::of((string) $account->currency);
            $balance = Money::of((int) $account->current_balance, $currency);

            $total = $total->plus(
                $currency->equals($base)
                    ? $balance
                    : $balance->convertTo($base, $this->rates->rate($currency, $base)),
            );

            $accounts[] = [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type,
                'currency' => $currency->code,
                'balance' => $balance->minorUnits,
            ];
        }

        return [
            'currency' => $base->code,
            'accounts' => $accounts,
            'total_in_base' => $total->minorUnits,
        ];
    }
}
