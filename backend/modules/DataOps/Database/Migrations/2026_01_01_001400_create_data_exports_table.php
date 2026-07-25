<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_exports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();

            // Null once the requester's account is purged; the workspace keeps
            // the record of what left it either way.
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 16)->default('pending');
            $table->string('format', 8)->default('zip');

            // The disk is stored beside the path: an export written last week
            // must still be downloadable after the configured disk changes.
            $table->string('disk', 32)->nullable();
            $table->string('path', 512)->nullable();
            $table->unsignedBigInteger('size')->default(0);

            // Why a failed export failed, so the user is told something better
            // than "try again".
            $table->text('error')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'created_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_exports');
    }
};
