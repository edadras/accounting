<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');

            $table->string('scope', 16)->default('overall');

            // What the scope points at: a category, a project, a trip, a
            // building, a member. Deliberately not a foreign key — the target
            // lives in whichever module owns that scope, and several of those
            // tables do not exist yet.
            $table->ulid('scope_id')->nullable();

            $table->string('period', 16)->default('monthly');
            $table->timestamp('starts_at');

            // Null for a recurring budget that has not been given an end date;
            // required for `custom`, which has no calendar period to fall back on.
            $table->timestamp('ends_at')->nullable();

            $table->bigInteger('amount');
            $table->string('currency', 8);

            $table->boolean('rollover')->default(false);

            // MySQL forbids a DEFAULT on a JSON column, so the [80, 100] default
            // is carried by the model instead.
            $table->json('alert_thresholds')->nullable();

            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'scope', 'scope_id']);
            $table->index(['workspace_id', 'starts_at']);
        });

        Schema::create('budget_usages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('budget_id')->constrained()->cascadeOnDelete();

            // '2026-07', '2026', or 'YYYY-MM-DD..YYYY-MM-DD' for a custom window.
            $table->string('period_key', 32);

            $table->bigInteger('spent_amount')->default(0);
            $table->string('currency', 8);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['budget_id', 'period_key']);
            $table->index(['workspace_id', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_usages');
        Schema::dropIfExists('budgets');
    }
};
