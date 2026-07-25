<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16)->default('customer');
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('tax_id', 64)->nullable();
            $table->text('address')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'type']);
            $table->index(['workspace_id', 'name']);
        });

        Schema::create('projects', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('status', 16)->default('active');

            // Minor units, like every other amount in the system.
            $table->bigInteger('budget_amount')->default(0);
            $table->string('currency', 8);

            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'contact_id']);
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUlid('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('direction', 16)->default('sale');

            $table->date('issue_date');
            $table->date('due_date')->nullable();

            // subtotal - discount + tax = total, always, exactly. The columns
            // are stored rather than derived so a historical invoice keeps the
            // arithmetic it was issued with.
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('discount')->default(0);
            $table->bigInteger('tax')->default(0);
            $table->bigInteger('total')->default(0);

            $table->string('currency', 8);
            $table->decimal('fx_rate', 30, 12)->default(1);
            $table->bigInteger('base_total')->default(0);
            $table->string('base_currency', 8);

            $table->string('status', 16)->default('draft');
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            // Two invoices in one workspace may never share a number; two
            // workspaces numbering from the same prefix are unrelated.
            $table->unique(['workspace_id', 'number']);
            $table->index(['workspace_id', 'status', 'due_date']);
            $table->index(['workspace_id', 'contact_id']);
            $table->index(['workspace_id', 'project_id']);
        });

        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description');

            // Quantity is the one non-money decimal here; it is stored scaled
            // rather than as a float so 0.1 + 0.2 cannot enter the maths.
            $table->decimal('quantity', 18, 4)->default(1);

            $table->bigInteger('unit_price');
            $table->bigInteger('discount')->default(0);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->bigInteger('tax')->default(0);
            $table->bigInteger('line_total');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['invoice_id', 'sort_order']);
            $table->index(['workspace_id', 'invoice_id']);
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUlid('account_id')->constrained('accounts')->cascadeOnDelete();

            $table->bigInteger('amount');
            $table->string('currency', 8);
            $table->timestamp('paid_at');
            $table->string('method', 24)->default('cash');

            // The ledger posting this payment produced. Nullable only so a
            // deleted transaction cannot take the payment record with it.
            $table->foreignUlid('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();

            $table->string('reference')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'invoice_id']);
            $table->index(['workspace_id', 'paid_at']);
            $table->index(['workspace_id', 'transaction_id']);
        });

        Schema::create('invoice_sequences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();

            // e.g. "INV:2026" — one counter per prefix per reset period.
            $table->string('scope', 64);
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();

            $table->unique(['workspace_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_sequences');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('contacts');
    }
};
