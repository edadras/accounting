<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_members', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();

            // Null for a child who has no login of their own: the household is
            // still accounted for in full, and the row gets a user later without
            // the history having to move.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('display_name');
            $table->string('role', 16)->default('other'); // parent | child | other
            $table->date('birth_date')->nullable();

            // Amounts are integers in the currency's minor unit. Never a float.
            $table->bigInteger('monthly_allowance')->nullable();
            $table->string('currency', 8);
            $table->bigInteger('spending_cap')->nullable();

            // The member's own pocket: an ordinary ledger account, so an
            // allowance is a real transfer and every existing report already
            // understands it.
            $table->foreignUlid('account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->timestamps();

            $table->index(['workspace_id', 'role']);
            $table->index(['workspace_id', 'user_id']);
        });

        Schema::create('allowance_payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('member_id')->constrained('family_members')->cascadeOnDelete();
            $table->foreignUlid('payer_member_id')->constrained('family_members')->cascadeOnDelete();

            $table->string('period', 7); // YYYY-MM

            $table->bigInteger('amount');
            $table->string('currency', 8);

            $table->foreignUlid('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->timestamp('paid_at');
            $table->timestamps();

            // One allowance per member per month. Re-sending the request after a
            // timeout must not pay a child twice, and the database — not the
            // application — is what guarantees that.
            $table->unique(['member_id', 'period']);

            $table->index(['workspace_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allowance_payments');
        Schema::dropIfExists('family_members');
    }
};
