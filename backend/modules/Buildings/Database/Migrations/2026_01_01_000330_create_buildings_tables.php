<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buildings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->unsignedInteger('units_count')->default(0);

            // The building's cash box is an ordinary ledger account, so charge
            // income and building expenses land in the same books as everything
            // else and every existing report already understands them.
            $table->foreignUlid('fund_account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->string('charge_formula', 16)->default('fixed');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'name']);
        });

        Schema::create('building_units', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('building_id')->constrained('buildings')->cascadeOnDelete();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('unit_no', 32);

            // Area and share factor are exact decimals, never floats: they are
            // the weights a charge is split by, and a drifting weight becomes a
            // drifting bill.
            $table->decimal('area_m2', 12, 2)->default(0);
            $table->unsignedInteger('residents_count')->default(0);

            $table->string('owner_name')->nullable();
            $table->string('owner_contact')->nullable();
            $table->string('tenant_name')->nullable();
            $table->string('tenant_contact')->nullable();

            $table->decimal('share_factor', 12, 4)->default(1);
            $table->boolean('is_occupied')->default(true);
            $table->timestamps();

            $table->unique(['building_id', 'unit_no']);
            $table->index(['workspace_id', 'building_id']);
        });

        Schema::create('building_charges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('building_id')->constrained('buildings')->cascadeOnDelete();
            $table->foreignUlid('unit_id')->constrained('building_units')->cascadeOnDelete();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('period', 7); // YYYY-MM

            $table->bigInteger('amount');
            $table->string('currency', 8);
            $table->date('due_date')->nullable();

            $table->string('status', 16)->default('unpaid');
            $table->bigInteger('paid_amount')->default(0);

            $table->foreignUlid('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->timestamps();

            // One bill per unit per month. Re-running the issue job after a
            // timeout must not bill anyone twice, and the database — not the
            // application — is what guarantees that.
            $table->unique(['unit_id', 'period']);

            $table->index(['workspace_id', 'building_id', 'period']);
            $table->index(['workspace_id', 'building_id', 'status']);
        });

        Schema::create('building_expenses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('building_id')->constrained('buildings')->cascadeOnDelete();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('category_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->bigInteger('amount');
            $table->string('currency', 8);
            $table->timestamp('occurred_at');
            $table->string('description')->nullable();

            $table->foreignUlid('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'building_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('building_expenses');
        Schema::dropIfExists('building_charges');
        Schema::dropIfExists('building_units');
        Schema::dropIfExists('buildings');
    }
};
