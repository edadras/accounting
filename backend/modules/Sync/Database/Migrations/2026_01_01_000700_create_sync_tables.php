<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table): void {
            // The device mints its own ULID before it has ever seen the server,
            // so an install that starts offline already has its final identity.
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 32)->default('unknown');
            $table->string('name')->nullable();
            $table->string('push_token', 512)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('sync_changes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained('devices')->nullOnDelete();

            // The registry key ('transaction', 'account', …), never a class name.
            $table->string('entity_type', 64);
            $table->string('entity_id', 64);
            $table->string('operation', 16);

            $table->json('payload')->nullable();

            // client_version is the base_version the device believed it was
            // editing; server_version is what the row carries after the verdict.
            $table->unsignedInteger('client_version')->default(0);
            $table->unsignedInteger('server_version')->nullable();

            $table->timestamp('applied_at')->nullable();
            $table->string('status', 16);
            $table->string('conflict_reason', 64)->nullable();
            $table->timestamps();

            // The replay lookup: a retried batch is matched on this prefix and
            // answered from the log instead of being applied a second time.
            $table->index(['workspace_id', 'entity_type', 'entity_id', 'operation', 'client_version'], 'sync_changes_replay_index');
            $table->index(['workspace_id', 'status']);
            $table->index(['device_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_changes');
        Schema::dropIfExists('devices');
    }
};
