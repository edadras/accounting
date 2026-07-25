<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('embeddings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();

            // The record this vector describes — a transaction, a document, a
            // category. Morph rather than a column per module, exactly as the
            // search index does it.
            $table->string('owner_type');
            $table->ulid('owner_id');

            // The L2-normalised vector as a JSON array of floats. Portable
            // across SQLite, MySQL and Postgres; a native vector type would tie
            // the schema to one engine for a similarity pass that runs over a
            // workspace's worth of rows, not a corpus.
            $table->longText('vector');

            // Which model produced it. Vectors from two models are not
            // comparable, so this is part of the row's identity rather than
            // metadata: re-embedding with a new model adds rows instead of
            // corrupting the old ones, and the switch-over can be gradual.
            $table->string('model', 64);

            $table->timestamp('created_at')->nullable();

            $table->unique(['owner_type', 'owner_id', 'model'], 'embeddings_owner_model_unique');
            $table->index(['workspace_id', 'model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('embeddings');
    }
};
