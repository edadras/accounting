<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('branch')->nullable();
            $table->string('swift', 16)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('logo')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'name']);
        });

        Schema::create('checks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('account_id')->constrained('accounts')->cascadeOnDelete();

            $table->string('direction', 16); // received | issued | guarantee
            $table->string('check_number', 64);

            // Amounts are integers in the currency's minor unit. Never a float.
            $table->bigInteger('amount');
            $table->string('currency', 8);
            $table->bigInteger('base_amount')->default(0);

            $table->date('due_date');
            $table->string('status', 16)->default('draft');
            $table->string('party_name')->nullable();
            $table->text('notes')->nullable();

            // Set once the cheque actually clears; its presence is what makes
            // clearing idempotent.
            $table->foreignUlid('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->timestamp('cleared_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'due_date', 'status']);
            $table->index(['workspace_id', 'account_id']);
        });

        Schema::create('loans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('bank_id')->nullable()->constrained('banks')->nullOnDelete();
            $table->foreignUlid('account_id')->constrained('accounts')->cascadeOnDelete();

            $table->string('title')->nullable();
            $table->bigInteger('principal');
            $table->string('currency', 8);

            // Annual nominal percentage, e.g. 18.5000 for 18.5%. A decimal
            // column and not a float: the rate is an exact term of the contract.
            $table->decimal('interest_rate', 9, 4)->default(0);
            $table->string('interest_type', 16)->default('simple'); // simple | compound
            $table->unsignedSmallInteger('installments_count');
            $table->date('start_date');
            $table->decimal('penalty_rate', 9, 4)->default(0);

            $table->bigInteger('outstanding_balance')->default(0);
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'bank_id']);
        });

        Schema::create('loan_installments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('loan_id')->constrained('loans')->cascadeOnDelete();

            $table->unsignedSmallInteger('number');
            $table->date('due_date');

            $table->bigInteger('principal_part');
            $table->bigInteger('interest_part');
            $table->bigInteger('total_amount');
            $table->bigInteger('paid_amount')->default(0);
            $table->bigInteger('penalty_amount')->default(0);
            $table->timestamp('paid_at')->nullable();

            $table->string('status', 16)->default('due'); // due | paid | late | partial
            $table->foreignUlid('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->timestamps();

            $table->unique(['loan_id', 'number']);
            $table->index(['workspace_id', 'due_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_installments');
        Schema::dropIfExists('loans');
        Schema::dropIfExists('checks');
        Schema::dropIfExists('banks');
    }
};
