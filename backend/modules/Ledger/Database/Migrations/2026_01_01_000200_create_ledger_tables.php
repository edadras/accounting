<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 32)->default('cash');
            $table->string('currency', 8);

            // Amounts are integers in the currency's minor unit. Never a float.
            $table->bigInteger('opening_balance')->default(0);
            $table->bigInteger('current_balance')->default(0);

            $table->string('iban', 34)->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->string('icon', 64)->nullable();
            $table->string('color', 16)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'type']);
            $table->index(['workspace_id', 'archived_at']);
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');

            // Translation key for seeded categories; null once the user renames it.
            $table->string('name_key')->nullable();

            // Materialized path: '/food/restaurant'. Subtree queries are a
            // prefix LIKE instead of a recursive join, which keeps unlimited
            // nesting cheap.
            $table->string('path', 512);
            $table->unsignedTinyInteger('depth')->default(0);

            $table->string('type', 16)->default('expense');
            $table->string('icon', 64)->nullable();
            $table->string('color', 16)->nullable();
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'path']);
            $table->index(['workspace_id', 'parent_id']);
        });

        Schema::create('transactions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);

            $table->foreignUlid('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignUlid('counter_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignUlid('category_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->bigInteger('amount');
            $table->string('currency', 8);

            // The rate used at the moment of recording, and the resulting base
            // amount. Both are frozen: re-deriving history from today's rate
            // would silently rewrite past reports.
            $table->decimal('fx_rate', 30, 12)->default(1);
            $table->bigInteger('base_amount');
            $table->string('base_currency', 8);

            $table->timestamp('occurred_at');
            $table->string('description')->nullable();
            $table->text('notes')->nullable();
            $table->string('payee')->nullable();
            $table->string('reference')->nullable();
            $table->json('tags')->nullable();

            $table->string('source', 24)->default('manual');
            $table->json('source_meta')->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_reconciled')->default(false);

            $table->string('idempotency_key', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'occurred_at']);
            $table->index(['workspace_id', 'account_id', 'occurred_at']);
            $table->index(['workspace_id', 'category_id', 'occurred_at']);
            $table->unique(['workspace_id', 'idempotency_key']);
        });

        Schema::create('entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('account_id')->constrained('accounts')->cascadeOnDelete();

            $table->string('direction', 8); // debit | credit
            $table->bigInteger('amount');
            $table->string('currency', 8);
            $table->bigInteger('base_amount');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['workspace_id', 'account_id', 'occurred_at']);
            $table->index(['transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entries');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('accounts');
    }
};
