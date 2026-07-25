<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Nullable: sign-in and sign-out happen before any workspace is
            // chosen. Those rows are the user's own security log, reachable at
            // /me/security-log rather than in a workspace's trail.
            $table->foreignUlid('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->string('subject_id', 40)->nullable();

            $table->json('before')->nullable();
            $table->json('after')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            // Append-only: there is no updated_at because a row is never
            // updated, and a trail you can quietly edit is not a trail.
            $table->timestamp('created_at')->index();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['workspace_id', 'subject_type', 'subject_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
