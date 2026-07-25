<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('name');

            // Minor units, like every amount in the system.
            $table->bigInteger('price')->default(0);
            $table->string('currency', 8)->default('USD');
            $table->string('interval', 16)->default('monthly');

            $table->json('features');
            $table->unsignedSmallInteger('rank')->default(0);
            $table->boolean('is_public')->default(true);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();

            // The plan code and not a foreign key to `plans`: a subscription
            // must keep naming its plan even if the catalogue row is retired.
            $table->string('plan_code', 32);
            $table->string('status', 16)->default('active');

            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('renews_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->string('gateway', 32)->nullable();
            $table->string('gateway_reference')->nullable();
            $table->timestamps();

            // One subscription per workspace: two would make "which plan am I
            // on?" ambiguous, and every entitlement decision asks that.
            $table->unique('workspace_id');
            $table->index(['status', 'renews_at']);
        });

        Schema::create('subscription_invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('subscription_id')->constrained('subscriptions')->cascadeOnDelete();

            $table->string('number', 40);
            $table->string('plan_code', 32);
            $table->bigInteger('amount');
            $table->string('currency', 8);
            $table->string('status', 16)->default('pending');

            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->string('gateway', 32)->nullable();
            $table->string('gateway_reference')->nullable();
            $table->timestamps();

            $table->unique('number');
            $table->index(['workspace_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
