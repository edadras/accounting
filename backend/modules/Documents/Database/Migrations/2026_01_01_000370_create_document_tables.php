<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('disk', 32)->default('local');
            $table->string('path', 512);
            $table->string('original_name');
            $table->string('mime', 191)->nullable();
            $table->unsignedBigInteger('size')->default(0);

            $table->string('kind', 16)->default('other');

            $table->string('ocr_status', 16)->default('pending');
            $table->longText('ocr_text')->nullable();
            $table->json('ocr_data')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            // sha256 of the bytes, so a re-upload of the same receipt can be
            // recognised instead of stored twice.
            $table->string('checksum', 64)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'kind']);
            $table->index(['workspace_id', 'ocr_status']);
            $table->index(['workspace_id', 'checksum']);
        });

        Schema::create('documentables', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained()->cascadeOnDelete();

            $table->string('documentable_type');
            $table->ulid('documentable_id');

            $table->timestamps();

            // One contract may hang off many records, but only once off each.
            $table->unique(
                ['document_id', 'documentable_type', 'documentable_id'],
                'documentables_document_record_unique',
            );
            $table->index(['documentable_type', 'documentable_id']);
            $table->index(['workspace_id', 'documentable_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentables');
        Schema::dropIfExists('documents');
    }
};
