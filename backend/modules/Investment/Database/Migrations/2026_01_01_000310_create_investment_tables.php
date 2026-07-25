<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind', 24);
            $table->string('symbol', 32)->nullable();

            // The one legitimately fractional column in the module: 0.00318 BTC
            // is a real position. Prices and profits below stay integers.
            $table->decimal('quantity', 30, 8)->default(0);

            // Per-unit prices in the currency's minor unit. Never a float.
            $table->bigInteger('avg_buy_price')->default(0);
            $table->string('currency', 8);
            $table->bigInteger('current_price')->nullable();
            $table->timestamp('priced_at')->nullable();

            $table->bigInteger('realized_profit')->default(0);
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'kind']);
            $table->index(['workspace_id', 'symbol']);
        });

        Schema::create('investment_transactions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('investment_id')->constrained()->cascadeOnDelete();
            $table->string('action', 16);

            $table->decimal('quantity', 30, 8)->default(0);
            $table->bigInteger('price')->default(0);
            $table->bigInteger('fee')->default(0);
            $table->string('currency', 8);

            // Frozen at the moment of the trade, exactly as the ledger does it:
            // re-deriving history from today's rate would rewrite past reports.
            $table->decimal('fx_rate', 30, 12)->default(1);
            $table->bigInteger('base_amount')->default(0);
            $table->string('base_currency', 8);

            // Profit crystallised by this row, so a realised-gains report never
            // has to replay the whole position.
            $table->bigInteger('realized_profit')->default(0);

            $table->timestamp('occurred_at');
            $table->text('notes')->nullable();

            // Set when the trade also moved real money through an account.
            $table->foreignUlid('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();

            $table->string('idempotency_key', 64)->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'investment_id', 'occurred_at']);
            $table->unique(['workspace_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_transactions');
        Schema::dropIfExists('investments');
    }
};
