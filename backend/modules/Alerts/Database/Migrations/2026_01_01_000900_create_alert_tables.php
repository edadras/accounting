<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('type', 32);
            $table->json('config')->nullable();
            $table->json('channels');

            // How far ahead of a due date the user wants to hear about it.
            $table->unsignedSmallInteger('lead_days')->default(3);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['workspace_id', 'type', 'is_active']);
        });

        Schema::create('alerts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('type', 32);
            $table->json('payload');

            $table->timestamp('scheduled_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();

            // Per-channel outcome: {"database":"sent","push":"failed"}. A single
            // status column could not say that push failed while email worked.
            $table->json('channels');
            $table->string('status', 16)->default('pending');

            // The whole deduplication mechanism. A scan that runs every hour
            // recomputes the same due cheque every hour; without a unique key
            // the user would be told about it twenty-four times a day. The
            // uniqueness is enforced by the database, not by a prior SELECT,
            // because two workers can scan at once.
            $table->string('dedupe_key', 191);

            $table->timestamps();

            $table->unique(['workspace_id', 'user_id', 'dedupe_key']);
            $table->index(['workspace_id', 'user_id', 'status', 'scheduled_at']);
            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('alert_preferences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // {"push":true,"sms":false} — an absent channel is on, so adding a
            // channel later does not silently mute it for everyone.
            $table->json('channels')->nullable();

            // Local wall-clock "HH:MM" in the member's own timezone, not an
            // instant: "do not wake me before 07:00" must survive them flying
            // somewhere else.
            $table->string('quiet_hours_start', 5)->nullable();
            $table->string('quiet_hours_end', 5)->nullable();
            $table->string('timezone', 64)->nullable();

            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_preferences');
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('alert_rules');
    }
};
