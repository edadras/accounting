<?php

declare(strict_types=1);

namespace Modules\Business\Queries;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Modules\Business\Exceptions\BusinessException;
use Modules\Business\Models\Invoice;
use Modules\Business\Models\Project;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Models\Transaction;

/**
 * What a project earned and what it cost.
 *
 * Two sources feed it, and the reason they do not double-count is worth saying
 * plainly:
 *
 *   * Invoices attached to the project — a sale is income, a purchase is a
 *     cost — counted at their full value whether or not they have been paid,
 *     because a project's profitability is not a cash-flow statement.
 *   * Ledger transactions tagged with the project — fuel, wages, a cash sale —
 *     that were never invoiced.
 *
 * The ledger postings created *by* invoice payments carry the project tag too,
 * so they are excluded here by their id: they are the same money as the invoice
 * that produced them, and counting both would report twice the profit.
 *
 * Everything is totalled in the workspace's base currency, because a project
 * billed in two currencies has no meaningful single-currency total otherwise.
 */
final readonly class ProjectProfitability
{
    public function __construct(private WorkspaceContext $context) {}

    /** @return array<string, mixed> */
    public function forProject(Project|string $project): array
    {
        if (! $project instanceof Project) {
            $id = $project;
            $project = Project::query()->findOr($id, callback: fn () => throw BusinessException::projectNotFound($id));
        }

        $base = Currency::of($this->context->require()->base_currency);

        [$invoicedIncome, $invoicedExpense] = $this->fromInvoices($project, $base);
        [$ledgerIncome, $ledgerExpense] = $this->fromLedger($project, $base);

        $income = $invoicedIncome->plus($ledgerIncome);
        $expense = $invoicedExpense->plus($ledgerExpense);

        return [
            'project_id' => $project->id,
            'project_name' => $project->name,
            'status' => $project->status,
            'currency' => $base->code,
            'invoiced_income' => $invoicedIncome,
            'invoiced_expense' => $invoicedExpense,
            'ledger_income' => $ledgerIncome,
            'ledger_expense' => $ledgerExpense,
            'income' => $income,
            'expense' => $expense,
            'profit' => $income->minus($expense),
            'budget' => $project->budget(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function forAll(): array
    {
        return Project::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project) => $this->forProject($project))
            ->all();
    }

    /** @return array{0: Money, 1: Money} */
    private function fromInvoices(Project $project, Currency $base): array
    {
        $totals = Invoice::query()
            ->countable()
            ->where('project_id', $project->id)
            ->selectRaw('
                COALESCE(SUM(CASE WHEN direction = ? THEN base_total ELSE 0 END), 0) AS income,
                COALESCE(SUM(CASE WHEN direction = ? THEN base_total ELSE 0 END), 0) AS expense
            ', [Invoice::DIRECTION_SALE, Invoice::DIRECTION_PURCHASE])
            ->first();

        return [
            Money::of((int) ($totals->income ?? 0), $base),
            Money::of((int) ($totals->expense ?? 0), $base),
        ];
    }

    /** @return array{0: Money, 1: Money} */
    private function fromLedger(Project $project, Currency $base): array
    {
        $totals = Transaction::query()
            ->whereJsonContains('tags', $project->tag())
            ->whereIn('type', [Transaction::TYPE_INCOME, Transaction::TYPE_EXPENSE])
            ->whereNotIn('id', function ($query): void {
                $query->select('transaction_id')
                    ->from('payments')
                    ->whereNotNull('transaction_id')
                    ->whereNull('deleted_at');
            })
            ->selectRaw('
                COALESCE(SUM(CASE WHEN type = ? THEN base_amount ELSE 0 END), 0) AS income,
                COALESCE(SUM(CASE WHEN type = ? THEN base_amount ELSE 0 END), 0) AS expense
            ', [Transaction::TYPE_INCOME, Transaction::TYPE_EXPENSE])
            ->first();

        return [
            Money::of((int) ($totals->income ?? 0), $base),
            Money::of((int) ($totals->expense ?? 0), $base),
        ];
    }
}
