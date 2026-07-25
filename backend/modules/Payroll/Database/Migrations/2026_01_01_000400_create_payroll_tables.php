<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('employee_number', 64)->nullable();
            $table->string('name');
            $table->string('job_title')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();

            // A national identifier is exactly the kind of field docs/07-security
            // says never sits in the clear, so it is encrypted at rest and is
            // therefore not searchable — deliberately.
            $table->text('national_id')->nullable();

            // ISO 3166-1 alpha-2. Decides which tax rule set applies, which is
            // why it lives on the employee and not only on the workspace: a
            // company can employ someone abroad.
            $table->string('country', 2);

            $table->string('status', 16)->default('active');
            $table->date('started_on');
            $table->date('ended_on')->nullable();
            $table->text('notes')->nullable();

            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'employee_number']);
            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'started_on']);
            $table->index(['workspace_id', 'ended_on']);
        });

        Schema::create('employee_compensations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('employee_id')->constrained()->cascadeOnDelete();

            // Minor units, like every other amount in the system.
            $table->bigInteger('amount');
            $table->string('currency', 8);
            $table->string('period', 16)->default('monthly');

            // Compensation is a history, not a column: a payslip written last
            // March must still price itself at last March's salary after a
            // raise, so the rate in force is looked up by date.
            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['workspace_id', 'employee_id', 'effective_from']);
        });

        Schema::create('payroll_tax_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('country', 2);
            $table->string('name');

            // Null means the schedule applies whatever the run is denominated
            // in; set it and a payslip in another currency is refused, because
            // bracket ceilings are absolute amounts.
            $table->string('currency', 8)->nullable();

            $table->date('effective_from');
            $table->boolean('is_active')->default(true);

            // Brackets, contributions and fixed deductions as data. Tax law is
            // country-dependent (docs/02-modules.md), so the calculation reads
            // this column rather than having a country compiled into it.
            $table->json('rules');

            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'country', 'effective_from']);
            $table->index(['workspace_id', 'country', 'is_active']);
        });

        Schema::create('payroll_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 64);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('pay_date')->nullable();

            $table->string('currency', 8);

            // gross - deductions = net, always, exactly. Contributions are the
            // employer's own cost and sit outside that identity.
            $table->bigInteger('gross_total')->default(0);
            $table->bigInteger('deduction_total')->default(0);
            $table->bigInteger('contribution_total')->default(0);
            $table->bigInteger('net_total')->default(0);

            $table->string('status', 16)->default('draft');

            // The account the payroll leaves from, and the category the cost is
            // filed under. Both are resolved when the run is created so that
            // approving it can post without asking anything further.
            $table->foreignUlid('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignUlid('category_id')->nullable()->constrained('categories')->nullOnDelete();

            // The two postings approving this run produced. Nullable only so a
            // deleted transaction cannot take the run with it.
            $table->foreignUlid('net_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignUlid('liability_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();

            $table->timestamp('approved_at')->nullable();

            // users.id is a bigint, so this has to be a foreignId. Declaring it
            // as a ULID made a char(26) column point at an integer key: on
            // MySQL or Postgres the constraint cannot even be created, and the
            // approver's id would be stored in a column it does not fit.
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();

            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'reference']);
            $table->index(['workspace_id', 'status', 'period_start']);
            $table->index(['workspace_id', 'period_start', 'period_end']);
        });

        Schema::create('payslips', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('employee_id')->constrained()->cascadeOnDelete();

            $table->string('currency', 8);
            $table->bigInteger('gross');
            $table->bigInteger('deduction_total')->default(0);
            $table->bigInteger('contribution_total')->default(0);
            $table->bigInteger('net');

            // What the employee was actually entitled to in this period, kept
            // so a prorated payslip can explain itself years later.
            $table->unsignedSmallInteger('period_days');
            $table->unsignedSmallInteger('worked_days');

            // The country and schedule that priced it, copied rather than
            // referenced: amending the rules must not rewrite history.
            $table->string('country', 2);
            $table->string('tax_rules_name');

            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            // One payslip per employee per run: paying somebody twice in one
            // run is not a thing the data model should be able to express.
            $table->unique(['payroll_run_id', 'employee_id']);
            $table->index(['workspace_id', 'employee_id']);
            $table->index(['workspace_id', 'payroll_run_id']);
        });

        Schema::create('payslip_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('payslip_id')->constrained()->cascadeOnDelete();

            $table->string('kind', 16);
            $table->string('code', 64);
            $table->string('label');
            $table->bigInteger('amount');

            // The rate this line was computed at, for the lines that had one.
            $table->decimal('rate', 8, 4)->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['payslip_id', 'sort_order']);
            $table->index(['workspace_id', 'payslip_id']);
            $table->index(['workspace_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_lines');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('payroll_tax_rules');
        Schema::dropIfExists('employee_compensations');
        Schema::dropIfExists('employees');
    }
};
