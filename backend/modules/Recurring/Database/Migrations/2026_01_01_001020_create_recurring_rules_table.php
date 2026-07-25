<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();

            // What to post, in the shape RecordTransaction already accepts:
            // type, account_id, counter_account_id, category_id, amount,
            // currency, description, payee, tags. Stored as JSON rather than as
            // columns because it is a payload for another module's action, not
            // a second copy of the ledger's schema.
            $table->json('template');

            $table->string('frequency', 16); // daily | weekly | monthly | yearly
            $table->unsignedSmallInteger('interval')->default(1);

            // The RRULE fields this product actually needs: "the 5th of every
            // month" and "every other Tuesday". Anything richer waits until a
            // user asks for it.
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->unsignedTinyInteger('day_of_week')->nullable();

            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();

            // The next occurrence still owed. Null means the rule has run out:
            // an exhausted rule is skipped by an index lookup rather than by
            // re-deriving its whole schedule every night.
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();

            $table->boolean('auto_post')->default(true);
            $table->boolean('is_paused')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_rules');
    }
};
