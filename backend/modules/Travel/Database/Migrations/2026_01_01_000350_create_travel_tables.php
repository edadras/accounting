<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('destination')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // Every expense is converted to this currency, and settlement is
            // computed in it. A trip abroad is priced in a dozen currencies;
            // only one of them can answer "who owes whom".
            $table->string('base_currency', 8);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'starts_at']);
        });

        Schema::create('trip_members', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();

            // Null for a plain contact: half the people on a trip never install
            // the app, and the split still has to include them.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('display_name');
            $table->unsignedInteger('weight')->default(1);
            $table->timestamps();

            $table->index(['workspace_id', 'trip_id']);
            $table->index(['trip_id', 'user_id']);
        });

        Schema::create('split_expenses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('payer_member_id')->constrained('trip_members')->cascadeOnDelete();

            $table->bigInteger('amount');
            $table->string('currency', 8);

            // Rate and base amount are frozen at recording time: re-deriving
            // them from today's rate would rewrite a settlement that people
            // have already paid.
            $table->decimal('fx_rate', 30, 12)->default(1);
            $table->bigInteger('base_amount');

            $table->foreignUlid('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->string('description')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'trip_id', 'occurred_at']);
            $table->index(['payer_member_id']);
        });

        Schema::create('split_shares', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('split_expense_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('member_id')->constrained('trip_members')->cascadeOnDelete();

            $table->bigInteger('share_amount');

            // The same share in the trip's base currency, allocated from the
            // expense's base amount rather than converted share by share.
            // Converting each share on its own would round each one
            // independently and the settlement would no longer sum to zero.
            $table->bigInteger('base_share_amount');

            $table->string('mode', 8);
            $table->timestamps();

            $table->unique(['split_expense_id', 'member_id']);
            $table->index(['workspace_id', 'member_id']);
        });

        Schema::create('settlements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('from_member_id')->constrained('trip_members')->cascadeOnDelete();
            $table->foreignUlid('to_member_id')->constrained('trip_members')->cascadeOnDelete();

            $table->bigInteger('amount');
            $table->string('currency', 8);
            $table->timestamp('settled_at');

            // Set when the payment was also recorded in the ledger; a trip can
            // be settled in cash without ever touching an account.
            $table->foreignUlid('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();

            $table->timestamps();

            $table->index(['workspace_id', 'trip_id', 'settled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
        Schema::dropIfExists('split_shares');
        Schema::dropIfExists('split_expenses');
        Schema::dropIfExists('trip_members');
        Schema::dropIfExists('trips');
    }
};
