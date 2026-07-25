<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();

            // Both come from closed whitelists — a report type and a format
            // enum — never from free text the caller chose.
            $table->string('report_type', 32);
            $table->string('format', 8);
            $table->string('locale', 8);

            // Exactly what was asked for, resolved: a queued export that ran an
            // hour late must be reproducible from this row alone.
            $table->json('filters');

            $table->string('status', 16)->default('pending');

            $table->string('disk', 32)->nullable();
            $table->string('path', 512)->nullable();
            $table->string('filename', 191)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('row_count')->default(0);

            $table->text('error')->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->timestamp('completed_at')->nullable();

            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
