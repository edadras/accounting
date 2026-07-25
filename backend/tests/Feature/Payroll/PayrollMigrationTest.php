<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * A migration that cannot be rolled back is a migration nobody dares deploy on
 * a Friday. So the down path is exercised, not assumed.
 */
final class PayrollMigrationTest extends PayrollTestCase
{
    use RefreshDatabase;

    private const TABLES = [
        'employees',
        'employee_compensations',
        'payroll_tax_rules',
        'payroll_runs',
        'payslips',
        'payslip_lines',
    ];

    #[Test]
    public function the_payroll_tables_roll_back_and_migrate_again(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "[{$table}] should exist after migrating.");
        }

        $this->artisan('migrate:rollback', [
            '--path' => 'modules/Payroll/Database/Migrations',
        ])->assertSuccessful();

        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "[{$table}] should be gone after rolling back.");
        }

        // The ledger the module posts into must still be standing: rolling
        // payroll back is not allowed to take anybody else's tables with it.
        $this->assertTrue(Schema::hasTable('transactions'));
        $this->assertTrue(Schema::hasTable('accounts'));
        $this->assertTrue(Schema::hasTable('workspaces'));

        $this->artisan('migrate', [
            '--path' => 'modules/Payroll/Database/Migrations',
        ])->assertSuccessful();

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "[{$table}] should be back after migrating again.");
        }
    }

    #[Test]
    public function the_payroll_amount_columns_are_integers_and_never_floats(): void
    {
        // docs/03-data-model.md: amounts are BIGINT in the smallest unit,
        // never FLOAT or DOUBLE. A float column here would be a rounding bug
        // that no amount of careful arithmetic above it could fix.
        $moneyColumns = [
            'employee_compensations' => ['amount'],
            'payroll_runs' => ['gross_total', 'deduction_total', 'contribution_total', 'net_total'],
            'payslips' => ['gross', 'deduction_total', 'contribution_total', 'net'],
            'payslip_lines' => ['amount'],
        ];

        foreach ($moneyColumns as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertTrue(Schema::hasColumn($table, $column), "{$table}.{$column} is missing.");

                $this->assertNotSame(
                    'float',
                    Schema::getColumnType($table, $column),
                    "{$table}.{$column} must not be a float.",
                );
            }
        }
    }
}
