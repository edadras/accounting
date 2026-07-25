<?php

declare(strict_types=1);

namespace Tests\Feature\Business;

use DateTimeImmutable;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Business\Actions\BuildInvoice;
use Modules\Business\Actions\CreateInvoice;
use Modules\Business\Actions\NumberInvoice;
use Modules\Business\Actions\RecordInvoicePayment;
use Modules\Business\Actions\VoidInvoice;
use Modules\Business\Exceptions\BusinessException;
use Modules\Business\Models\Contact;
use Modules\Business\Models\Invoice;
use Modules\Business\Models\Payment;
use Modules\Business\Models\Project;
use Modules\Business\Providers\BusinessServiceProvider;
use Modules\Business\Queries\ProjectProfitability;
use Modules\Business\Support\InvoiceLine;
use Modules\Core\Models\Workspace;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Models\Transaction;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\LedgerTestCase;

/**
 * The invariants of invoicing.
 *
 * An invoice that disagrees with itself is worse than a crash: it gets printed
 * and sent to a customer. So the arithmetic is checked against awkward numbers
 * — quantities that do not divide, rates that differ per line, discounts with a
 * remainder — rather than against numbers chosen to come out round.
 */
final class BusinessTest extends LedgerTestCase
{
    use RefreshDatabase;

    /**
     * The module registers itself for the test run.
     *
     * Wiring a module into bootstrap/providers.php is the deploying
     * application's decision; these tests must prove the module works either
     * way, so they never depend on that file having been edited.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if (! $app->providerIsLoaded(BusinessServiceProvider::class)) {
            $app->register(BusinessServiceProvider::class);
        }

        return $app;
    }

    // ---------------------------------------------------------------- totals

    #[Test]
    public function invoice_totals_reconcile_for_numbers_that_do_not_divide_evenly(): void
    {
        $workspace = $this->businessWorkspace('awkward@example.test');

        $totals = $this->inWorkspace($workspace, fn () => app(BuildInvoice::class)->handle([
            'currency' => 'TRY',
            'discount' => 1000, // ₺10.00 across three lines: 333.33… each
            'items' => [
                ['description' => 'Consulting day', 'quantity' => '3', 'unit_price' => 33333, 'tax_rate' => '9'],
                ['description' => 'Cable, per metre', 'quantity' => '7', 'unit_price' => 1499, 'tax_rate' => '18'],
                ['description' => 'Licence', 'quantity' => '1.5', 'unit_price' => 99999, 'tax_rate' => '0'],
            ],
        ]));

        $lineTotals = array_map(fn (InvoiceLine $line) => $line->lineTotal->minorUnits, $totals->lines);

        // 1.5 × 99999 = 149998.5 and must round half-up, not truncate.
        $this->assertSame([99999, 10493, 149999], $lineTotals);

        $this->assertSame(
            array_sum($lineTotals),
            $totals->subtotal->minorUnits,
            'The subtotal must be exactly the sum of the line totals.',
        );

        $this->assertSame(
            $totals->total->minorUnits,
            $totals->subtotal->minorUnits - $totals->discount->minorUnits + $totals->tax->minorUnits,
            'subtotal - discount + tax must equal the total, exactly.',
        );

        // The invoice-wide discount is allocated to the last minor unit.
        $this->assertSame(1000, $totals->discount->minorUnits);
        $this->assertSame(
            1000,
            array_sum(array_map(fn (InvoiceLine $line) => $line->discount->minorUnits, $totals->lines)),
            'The allocated line discounts must add back up to the invoice discount.',
        );

        $this->assertSame(260491, $totals->subtotal->minorUnits);
        $this->assertSame(10847, $totals->tax->minorUnits);
        $this->assertSame(270338, $totals->total->minorUnits);
    }

    #[Test]
    public function every_line_is_taxed_at_its_own_rate(): void
    {
        $workspace = $this->businessWorkspace('rates@example.test');

        $totals = $this->inWorkspace($workspace, fn () => app(BuildInvoice::class)->handle([
            'currency' => 'TRY',
            'items' => [
                ['description' => 'Standard rate', 'quantity' => '1', 'unit_price' => 100000, 'tax_rate' => '18'],
                ['description' => 'Reduced rate', 'quantity' => '1', 'unit_price' => 100000, 'tax_rate' => '9'],
                ['description' => 'Exempt', 'quantity' => '1', 'unit_price' => 100000, 'tax_rate' => '0'],
            ],
        ]));

        $taxes = array_map(fn (InvoiceLine $line) => $line->tax->minorUnits, $totals->lines);

        $this->assertSame([18000, 9000, 0], $taxes);
        $this->assertSame(27000, $totals->tax->minorUnits);

        // Taxing the grand total at a single blended rate would give 27000 too
        // by accident here; what matters is that each line kept its own rate.
        $this->assertSame(300000, $totals->subtotal->minorUnits);
        $this->assertSame(327000, $totals->total->minorUnits);
    }

    #[Test]
    public function tax_is_charged_per_line_and_not_on_the_rounded_grand_total(): void
    {
        $workspace = $this->businessWorkspace('perline@example.test');

        $totals = $this->inWorkspace($workspace, fn () => app(BuildInvoice::class)->handle([
            'currency' => 'TRY',
            'items' => [
                // Each line's 9% lands on a half-unit and must round up on its
                // own; 3 × round(0.045) is not round(3 × 0.045).
                ['description' => 'A', 'quantity' => '1', 'unit_price' => 50, 'tax_rate' => '9'],
                ['description' => 'B', 'quantity' => '1', 'unit_price' => 50, 'tax_rate' => '9'],
                ['description' => 'C', 'quantity' => '1', 'unit_price' => 50, 'tax_rate' => '9'],
            ],
        ]));

        $this->assertSame([5, 5, 5], array_map(fn (InvoiceLine $l) => $l->tax->minorUnits, $totals->lines));
        $this->assertSame(15, $totals->tax->minorUnits);
        $this->assertSame(165, $totals->total->minorUnits);

        $this->assertSame(
            $totals->total->minorUnits,
            $totals->subtotal->minorUnits - $totals->discount->minorUnits + $totals->tax->minorUnits,
        );
    }

    #[Test]
    public function a_quantity_that_arrives_from_json_as_a_float_is_priced_exactly(): void
    {
        // JSON has no decimal type, so 2.5 and 30.0 unavoidably arrive as
        // floats; they must be normalised without losing or inventing a digit.
        $workspace = $this->businessWorkspace('floats@example.test');

        $totals = $this->inWorkspace($workspace, fn () => app(BuildInvoice::class)->handle([
            'currency' => 'TRY',
            'items' => [
                ['description' => 'Hours', 'quantity' => 2.5, 'unit_price' => 12345, 'tax_rate' => 0],
                ['description' => 'Units', 'quantity' => 30.0, 'unit_price' => 999, 'tax_rate' => 0],
            ],
        ]));

        $this->assertSame(['2.5', '30'], array_map(fn (InvoiceLine $l) => $l->quantity, $totals->lines));
        $this->assertSame([30863, 29970], array_map(fn (InvoiceLine $l) => $l->lineTotal->minorUnits, $totals->lines));
        $this->assertSame(60833, $totals->subtotal->minorUnits);
    }

    #[Test]
    public function a_stored_invoice_agrees_with_the_lines_stored_beneath_it(): void
    {
        $workspace = $this->businessWorkspace('stored@example.test');

        $invoice = $this->inWorkspace($workspace, fn () => app(CreateInvoice::class)->handle([
            'direction' => 'sale',
            'currency' => 'TRY',
            'issue_date' => '2026-03-01',
            'discount' => 777,
            'items' => [
                ['description' => 'Design', 'quantity' => '3', 'unit_price' => 45000, 'tax_rate' => '18'],
                ['description' => 'Print', 'quantity' => '11', 'unit_price' => 1234, 'tax_rate' => '9', 'discount' => 130],
            ],
        ]));

        $this->inWorkspace($workspace, function () use ($invoice): void {
            $stored = Invoice::query()->with('items')->findOrFail($invoice->id);

            $this->assertSame(
                (int) $stored->items->sum('line_total'),
                $stored->subtotal,
                'The stored subtotal must equal the stored lines.',
            );

            $this->assertSame(
                (int) $stored->items->sum('discount'),
                $stored->discount,
                'Every discount taken must be visible on a line.',
            );

            $this->assertSame((int) $stored->items->sum('tax'), $stored->tax);
            $this->assertSame($stored->subtotal - $stored->discount + $stored->tax, $stored->total);
        });
    }

    #[Test]
    public function a_discount_larger_than_the_invoice_is_refused(): void
    {
        $workspace = $this->businessWorkspace('greedy@example.test');

        $this->expectException(BusinessException::class);

        $this->inWorkspace($workspace, fn () => app(BuildInvoice::class)->handle([
            'currency' => 'TRY',
            'discount' => 500000,
            'items' => [['description' => 'One', 'quantity' => '1', 'unit_price' => 1000, 'tax_rate' => '0']],
        ]));
    }

    // ------------------------------------------------------------- numbering

    #[Test]
    public function invoice_numbers_never_repeat_inside_a_workspace(): void
    {
        $workspace = $this->businessWorkspace('numbering@example.test');

        $numbers = $this->inWorkspace($workspace, function (): array {
            $action = app(NumberInvoice::class);
            $issuedOn = new DateTimeImmutable('2026-03-01');

            return array_map(fn () => $action->handle('sale', $issuedOn), range(1, 25));
        });

        $this->assertSame('INV-2026-0001', $numbers[0]);
        $this->assertSame('INV-2026-0025', $numbers[24]);
        $this->assertCount(25, array_unique($numbers), 'Two invoices were handed the same number.');
    }

    #[Test]
    public function a_number_already_taken_by_hand_is_stepped_over(): void
    {
        $workspace = $this->businessWorkspace('manual@example.test');

        $this->inWorkspace($workspace, function (): void {
            app(CreateInvoice::class)->handle([
                'direction' => 'sale',
                'currency' => 'TRY',
                'number' => 'INV-2026-0001',
                'issue_date' => '2026-03-01',
                'items' => [['description' => 'Typed by hand', 'quantity' => '1', 'unit_price' => 1000]],
            ]);

            $next = app(NumberInvoice::class)->handle('sale', new DateTimeImmutable('2026-03-01'));

            $this->assertSame('INV-2026-0002', $next);
        });
    }

    #[Test]
    public function two_workspaces_can_both_hold_invoice_number_0001(): void
    {
        $first = $this->businessWorkspace('first@example.test');
        $second = $this->businessWorkspace('second@example.test');

        $a = $this->inWorkspace($first, fn () => $this->makeInvoice(100000));
        $b = $this->inWorkspace($second, fn () => $this->makeInvoice(100000));

        $this->assertSame('INV-2026-0001', $a->number);
        $this->assertSame('INV-2026-0001', $b->number);
        $this->assertNotSame($a->id, $b->id);
        $this->assertNotSame($a->workspace_id, $b->workspace_id);
    }

    #[Test]
    public function a_purchase_invoice_numbers_from_its_own_series(): void
    {
        $workspace = $this->businessWorkspace('purchase-series@example.test');

        $invoice = $this->inWorkspace($workspace, fn () => $this->makeInvoice(50000, direction: 'purchase'));

        $this->assertSame('BIL-2026-0001', $invoice->number);
    }

    // -------------------------------------------------------------- payments

    #[Test]
    public function a_partial_payment_leaves_the_invoice_partial_with_the_right_balance(): void
    {
        $workspace = $this->businessWorkspace('partial@example.test');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 0);
        $invoice = $this->inWorkspace($workspace, fn () => $this->makeInvoice(100000));

        $this->inWorkspace($workspace, fn () => app(RecordInvoicePayment::class)->handle($invoice, [
            'account_id' => $account->id,
            'amount' => 30000,
            'method' => 'bank',
        ]));

        $this->inWorkspace($workspace, function () use ($invoice): void {
            $fresh = Invoice::query()->findOrFail($invoice->id);

            $this->assertSame(Invoice::STATUS_PARTIAL, $fresh->status);
            $this->assertSame(30000, $fresh->paid()->minorUnits);
            $this->assertSame(70000, $fresh->outstanding()->minorUnits);
        });
    }

    #[Test]
    public function paying_the_exact_remainder_marks_the_invoice_paid(): void
    {
        $workspace = $this->businessWorkspace('remainder@example.test');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 0);
        $invoice = $this->inWorkspace($workspace, fn () => $this->makeInvoice(100000));

        $this->inWorkspace($workspace, function () use ($invoice, $account): void {
            $record = app(RecordInvoicePayment::class);

            $record->handle($invoice, ['account_id' => $account->id, 'amount' => 30000]);
            $record->handle($invoice, ['account_id' => $account->id, 'amount' => 70000]);
        });

        $this->inWorkspace($workspace, function () use ($invoice): void {
            $fresh = Invoice::query()->findOrFail($invoice->id);

            $this->assertSame(Invoice::STATUS_PAID, $fresh->status);
            $this->assertSame(100000, $fresh->paid()->minorUnits);
            $this->assertSame(0, $fresh->outstanding()->minorUnits);
        });
    }

    #[Test]
    public function paying_more_than_the_outstanding_balance_is_refused(): void
    {
        $workspace = $this->businessWorkspace('overpay@example.test');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 0);
        $invoice = $this->inWorkspace($workspace, fn () => $this->makeInvoice(100000));

        $this->inWorkspace($workspace, fn () => app(RecordInvoicePayment::class)->handle($invoice, [
            'account_id' => $account->id,
            'amount' => 60000,
        ]));

        try {
            $this->inWorkspace($workspace, fn () => app(RecordInvoicePayment::class)->handle($invoice, [
                'account_id' => $account->id,
                'amount' => 40001,
            ]));

            $this->fail('Overpaying an invoice must be refused.');
        } catch (BusinessException $e) {
            $this->assertSame('payment_exceeds_balance', $e->errorCode);
        }

        $this->inWorkspace($workspace, function () use ($invoice): void {
            $fresh = Invoice::query()->findOrFail($invoice->id);

            $this->assertSame(Invoice::STATUS_PARTIAL, $fresh->status);
            $this->assertSame(60000, $fresh->paid()->minorUnits, 'The refused payment must not have been recorded.');
            $this->assertSame(1, Payment::query()->where('invoice_id', $invoice->id)->count());
        });
    }

    #[Test]
    public function paying_an_invoice_that_is_already_settled_is_refused(): void
    {
        $workspace = $this->businessWorkspace('settled@example.test');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 0);
        $invoice = $this->inWorkspace($workspace, fn () => $this->makeInvoice(25000));

        $this->inWorkspace($workspace, fn () => app(RecordInvoicePayment::class)->handle($invoice, [
            'account_id' => $account->id,
            'amount' => 25000,
        ]));

        $this->expectException(BusinessException::class);

        $this->inWorkspace($workspace, fn () => app(RecordInvoicePayment::class)->handle($invoice, [
            'account_id' => $account->id,
            'amount' => 1,
        ]));
    }

    #[Test]
    public function a_sale_payment_posts_exactly_one_ledger_transaction_and_raises_the_balance(): void
    {
        $workspace = $this->businessWorkspace('ledger-sale@example.test');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 500000);
        $invoice = $this->inWorkspace($workspace, fn () => $this->makeInvoice(120000));

        $payment = $this->inWorkspace($workspace, fn () => app(RecordInvoicePayment::class)->handle($invoice, [
            'account_id' => $account->id,
            'amount' => 120000,
        ]));

        $this->inWorkspace($workspace, function () use ($payment, $invoice): void {
            $this->assertSame(1, Transaction::query()->count(), 'A payment must post one transaction, no more.');

            $transaction = Transaction::query()->with('entries')->findOrFail($payment->transaction_id);

            $this->assertSame(Transaction::TYPE_INCOME, $transaction->type);
            $this->assertSame(120000, $transaction->amount);
            $this->assertSame($invoice->number, $transaction->reference);
            $this->assertCount(1, $transaction->entries);
            $this->assertSame('debit', $transaction->entries->first()->direction);
        });

        $this->assertSame(620000, $account->fresh()->current_balance);
    }

    #[Test]
    public function a_purchase_payment_posts_an_expense_and_lowers_the_balance(): void
    {
        $workspace = $this->businessWorkspace('ledger-purchase@example.test');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 500000);
        $invoice = $this->inWorkspace($workspace, fn () => $this->makeInvoice(120000, direction: 'purchase'));

        $payment = $this->inWorkspace($workspace, fn () => app(RecordInvoicePayment::class)->handle($invoice, [
            'account_id' => $account->id,
            'amount' => 120000,
        ]));

        $this->inWorkspace($workspace, function () use ($payment): void {
            $transaction = Transaction::query()->with('entries')->findOrFail($payment->transaction_id);

            $this->assertSame(Transaction::TYPE_EXPENSE, $transaction->type);
            $this->assertSame('credit', $transaction->entries->first()->direction);
        });

        $this->assertSame(380000, $account->fresh()->current_balance);
    }

    #[Test]
    public function a_retried_payment_carrying_the_same_id_settles_the_invoice_once(): void
    {
        $workspace = $this->businessWorkspace('retry@example.test');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 0);
        $invoice = $this->inWorkspace($workspace, fn () => $this->makeInvoice(80000));

        $payload = [
            'id' => (string) Str::ulid(),
            'account_id' => $account->id,
            'amount' => 80000,
        ];

        [$first, $second] = $this->inWorkspace($workspace, function () use ($invoice, $payload): array {
            $record = app(RecordInvoicePayment::class);

            return [$record->handle($invoice, $payload), $record->handle($invoice, $payload)];
        });

        $this->assertSame($first->id, $second->id);
        $this->assertSame(80000, $account->fresh()->current_balance);
        $this->assertSame(1, $this->inWorkspace($workspace, fn () => Payment::query()->count()));
    }

    #[Test]
    public function a_voided_invoice_cannot_be_paid(): void
    {
        $workspace = $this->businessWorkspace('void@example.test');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 0);
        $invoice = $this->inWorkspace($workspace, fn () => $this->makeInvoice(10000));

        $this->inWorkspace($workspace, fn () => app(VoidInvoice::class)->handle($invoice));

        $this->expectException(BusinessException::class);

        $this->inWorkspace($workspace, fn () => app(RecordInvoicePayment::class)->handle($invoice, [
            'account_id' => $account->id,
            'amount' => 10000,
        ]));
    }

    // --------------------------------------------------------- profitability

    #[Test]
    public function project_profitability_nets_invoices_against_tagged_spending(): void
    {
        $workspace = $this->businessWorkspace('project@example.test');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 1_000_000);
        $category = $this->makeCategory($workspace, 'Materials');

        $report = $this->inWorkspace($workspace, function () use ($account, $category) {
            $project = Project::query()->create([
                'name' => 'Kadıköy fit-out',
                'currency' => 'TRY',
                'budget_amount' => 900000,
                'status' => Project::STATUS_ACTIVE,
            ]);

            $sale = app(CreateInvoice::class)->handle([
                'direction' => 'sale',
                'currency' => 'TRY',
                'project_id' => $project->id,
                'issue_date' => '2026-03-01',
                'items' => [['description' => 'Fit-out', 'quantity' => '1', 'unit_price' => 800000]],
            ]);

            app(CreateInvoice::class)->handle([
                'direction' => 'purchase',
                'currency' => 'TRY',
                'project_id' => $project->id,
                'issue_date' => '2026-03-02',
                'items' => [['description' => 'Timber', 'quantity' => '1', 'unit_price' => 200000]],
            ]);

            // Spending recorded straight into the ledger, never invoiced.
            app(RecordTransaction::class)->handle([
                'type' => 'expense',
                'account_id' => $account->id,
                'category_id' => $category->id,
                'amount' => 50000,
                'currency' => 'TRY',
                'tags' => [$project->tag()],
                'description' => 'Site taxi',
            ]);

            // Paying the sale invoice must not count as income a second time.
            app(RecordInvoicePayment::class)->handle($sale, [
                'account_id' => $account->id,
                'amount' => 800000,
            ]);

            return app(ProjectProfitability::class)->forProject($project);
        });

        $this->assertSame(800000, $report['income']->minorUnits, 'The paid invoice must be counted once, not twice.');
        $this->assertSame(250000, $report['expense']->minorUnits);
        $this->assertSame(550000, $report['profit']->minorUnits);
        $this->assertSame(800000, $report['invoiced_income']->minorUnits);
        $this->assertSame(50000, $report['ledger_expense']->minorUnits);
        $this->assertSame('TRY', $report['currency']);
    }

    #[Test]
    public function a_voided_invoice_stops_counting_toward_a_project(): void
    {
        $workspace = $this->businessWorkspace('void-project@example.test');

        $report = $this->inWorkspace($workspace, function () {
            $project = Project::query()->create(['name' => 'Cancelled job', 'currency' => 'TRY']);

            $invoice = app(CreateInvoice::class)->handle([
                'direction' => 'sale',
                'currency' => 'TRY',
                'project_id' => $project->id,
                'issue_date' => '2026-03-01',
                'items' => [['description' => 'Work', 'quantity' => '1', 'unit_price' => 500000]],
            ]);

            app(VoidInvoice::class)->handle($invoice);

            return app(ProjectProfitability::class)->forProject($project);
        });

        $this->assertSame(0, $report['income']->minorUnits);
        $this->assertSame(0, $report['profit']->minorUnits);
    }

    // ------------------------------------------------------------- isolation

    #[Test]
    public function invoices_and_contacts_never_leak_across_workspaces(): void
    {
        $victim = $this->makeUser('victim-biz@example.test');
        $victimWorkspace = $this->makeWorkspace($victim, 'Victim Ltd');

        $invoice = $this->inWorkspace($victimWorkspace, function () {
            Contact::query()->create(['type' => 'customer', 'name' => 'Secret client']);

            return $this->makeInvoice(999000);
        });

        $intruder = $this->makeUser('intruder-biz@example.test');
        $intruderWorkspace = $this->makeWorkspace($intruder, 'Intruder Ltd');

        app(WorkspaceContext::class)->forget();

        Sanctum::actingAs($intruder);

        // A workspace the caller is not a member of: refused by the middleware.
        $this->getJson('/api/v1/invoices', ['X-Workspace-Id' => $victimWorkspace->id])
            ->assertForbidden();

        // The subtler attack: a header the caller *is* entitled to, plus
        // somebody else's id. The global scope has to stop this one.
        $this->getJson("/api/v1/invoices/{$invoice->id}", ['X-Workspace-Id' => $intruderWorkspace->id])
            ->assertNotFound();

        $this->postJson("/api/v1/invoices/{$invoice->id}/pay", [
            'account_id' => (string) Str::ulid(),
            'amount' => 1000,
        ], ['X-Workspace-Id' => $intruderWorkspace->id])->assertNotFound();

        $this->getJson('/api/v1/contacts', ['X-Workspace-Id' => $intruderWorkspace->id])
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertSame(
            0,
            $this->inWorkspace($intruderWorkspace, fn () => Invoice::query()->count()),
            'Another workspace’s invoices must be invisible to the model layer too.',
        );
    }

    // ------------------------------------------------------------------- api

    #[Test]
    public function the_api_issues_an_invoice_and_settles_it(): void
    {
        $owner = $this->makeUser('api@example.test');
        $workspace = $this->makeWorkspace($owner, 'Expandia');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 0);

        app(WorkspaceContext::class)->forget();

        Sanctum::actingAs($owner);
        $headers = ['X-Workspace-Id' => $workspace->id];

        $contactId = $this->postJson('/api/v1/contacts', [
            'type' => 'customer',
            'name' => 'Ada Yılmaz',
        ], $headers)->assertCreated()->json('data.id');

        $created = $this->postJson('/api/v1/invoices', [
            'direction' => 'sale',
            'currency' => 'TRY',
            'contact_id' => $contactId,
            'issue_date' => '2026-03-01',
            'due_date' => '2026-03-31',
            'items' => [
                ['description' => 'Retainer', 'quantity' => 3, 'unit_price' => 33333, 'tax_rate' => 9],
            ],
        ], $headers)->assertCreated();

        // Money always leaves the API as {value, currency, minor_unit, decimal}.
        $created->assertJsonPath('data.total.currency', 'TRY')
            ->assertJsonPath('data.total.minor_unit', 2)
            ->assertJsonPath('data.total.value', 108999)
            ->assertJsonPath('data.total.decimal', '1089.99')
            ->assertJsonPath('data.status', 'draft');

        $invoiceId = $created->json('data.id');

        $this->postJson("/api/v1/invoices/{$invoiceId}/pay", [
            'account_id' => $account->id,
            'amount' => 8999,
            'method' => 'bank',
        ], $headers)->assertCreated()
            ->assertJsonPath('data.amount.value', 8999)
            ->assertJsonPath('meta.invoice.status', 'partial')
            ->assertJsonPath('meta.invoice.outstanding.value', 100000);

        $this->postJson("/api/v1/invoices/{$invoiceId}/pay", [
            'account_id' => $account->id,
            'amount' => 100001,
        ], $headers)->assertStatus(422)
            ->assertJsonPath('error.code', 'payment_exceeds_balance');

        $this->postJson("/api/v1/invoices/{$invoiceId}/pay", [
            'account_id' => $account->id,
            'amount' => 100000,
        ], $headers)->assertCreated()
            ->assertJsonPath('meta.invoice.status', 'paid');

        $this->assertSame(108999, $account->fresh()->current_balance);
    }

    #[Test]
    public function a_viewer_may_read_invoices_but_not_issue_one(): void
    {
        $owner = $this->makeUser('biz-owner@example.test');
        $workspace = $this->makeWorkspace($owner, 'Expandia');

        $viewer = $this->makeUser('biz-viewer@example.test');
        $workspace->members()->create(['user_id' => $viewer->id, 'role' => 'viewer', 'joined_at' => now()]);

        app(WorkspaceContext::class)->forget();

        Sanctum::actingAs($viewer);
        $headers = ['X-Workspace-Id' => $workspace->id];

        $this->getJson('/api/v1/invoices', $headers)->assertOk();

        $this->postJson('/api/v1/invoices', [
            'direction' => 'sale',
            'currency' => 'TRY',
            'items' => [['description' => 'Nope', 'quantity' => 1, 'unit_price' => 1000]],
        ], $headers)->assertForbidden();
    }

    // ----------------------------------------------------------------- setup

    private function businessWorkspace(string $email, string $currency = 'TRY'): Workspace
    {
        return $this->makeWorkspace($this->makeUser($email), 'Expandia', $currency);
    }

    /** Issues a single-line invoice totalling exactly $total, inside the active workspace. */
    private function makeInvoice(int $total, string $direction = 'sale'): Invoice
    {
        return app(CreateInvoice::class)->handle([
            'direction' => $direction,
            'currency' => 'TRY',
            'issue_date' => '2026-03-01',
            'status' => Invoice::STATUS_SENT,
            'items' => [[
                'description' => 'Services rendered',
                'quantity' => '1',
                'unit_price' => $total,
                'tax_rate' => '0',
            ]],
        ]);
    }
}
