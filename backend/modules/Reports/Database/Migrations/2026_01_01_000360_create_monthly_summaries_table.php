<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_summaries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();

            // '2026-07'. A string, not a date: it is an identity, and a report
            // asking for July must never depend on which day of July it picks.
            $table->string('period_key', 7);

            // Both null on the workspace-wide row; one set on a per-category or
            // per-account row. Rows at different grains coexist in this table.
            $table->foreignUlid('category_id')->nullable()->constrained('categories')->cascadeOnDelete();
            $table->foreignUlid('account_id')->nullable()->constrained('accounts')->cascadeOnDelete();

            // Minor units, and only ever the workspace base currency — mixing
            // yardsticks in a cache would be undetectable once cached.
            $table->bigInteger('income_amount')->default(0);
            $table->bigInteger('expense_amount')->default(0);
            $table->string('currency', 8);

            $table->unsignedInteger('transaction_count')->default(0);

            // This is a cache: when it was computed is the only timestamp that
            // means anything about it.
            $table->timestamp('updated_at')->nullable();

            $table->index(['workspace_id', 'period_key']);
            $table->index(['workspace_id', 'period_key', 'category_id']);
            $table->index(['workspace_id', 'period_key', 'account_id']);

            // Deliberately not unique: category_id and account_id are nullable,
            // and both MySQL and SQLite treat NULLs as distinct, so a unique
            // index would silently fail to protect the workspace-wide row —
            // the one grain that most needs protecting. RebuildMonthlySummaries
            // replaces a period wholesale instead.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_summaries');
    }
};
