<?php

declare(strict_types=1);

namespace Modules\Reports\Queries;

use Modules\Ledger\Models\Transaction;

final class ExpenseTrendReport extends TrendReport
{
    protected function transactionType(): string
    {
        return Transaction::TYPE_EXPENSE;
    }

    protected function name(): string
    {
        return 'expense-trend';
    }
}
