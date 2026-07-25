<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_index', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();

            // The group the row is reported under: transactions, documents,
            // categories.
            $table->string('type', 32);

            $table->string('indexable_type');
            $table->ulid('indexable_id');

            // Every searchable field of the record, normalised once on write so
            // that a query never has to normalise a whole column as it reads.
            $table->longText('content');

            $table->timestamps();

            $table->unique(['indexable_type', 'indexable_id'], 'search_index_indexable_unique');
            $table->index(['workspace_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_index');
    }
};
