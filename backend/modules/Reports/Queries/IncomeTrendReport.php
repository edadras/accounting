<?php

declare(strict_types=1);

namespace Modules\Reports\Queries;

use Modules\Ledger\Models\Transaction;

final class IncomeTrendReport extends TrendReport
{
    protected function transactionType(): string
    {
        return Transaction::TYPE_INCOME;
    }

    protected function name(): string
    {
        return 'income-trend';
    }
}
