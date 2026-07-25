<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every inbound message, whatever came of it. A capture channel that
        // only kept what it understood would make "why did my bank SMS never
        // show up" unanswerable, so an unrecognised message is stored too and
        // marked `unparsed` rather than dropped or guessed at.
        //
        // Nothing here writes to the ledger. Like the AI layer, capture stops
        // at a draft (docs/08-ai-layer.md, opening rule); `ai_draft_id` is the
        // suggestion this message produced and `transaction_id` stays null
        // until a user confirms it.
        Schema::create('capture_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('channel', 16);      // text | sms | qr | email
            $table->string('status', 16);       // parsed | unparsed | duplicate | rejected

            $table->string('sender', 191)->nullable();     // SMS sender id, email From
            $table->string('recipient', 191)->nullable();  // the ingest address mail arrived at
            $table->string('subject', 191)->nullable();
            $table->longText('body')->nullable();

            // Content-derived, so the same SMS delivered twice lands on the
            // same key. Indexed, never unique: the duplicate delivery is itself
            // a row worth keeping, pointing at the message it repeats.
            $table->string('dedupe_key', 64);
            $table->foreignUlid('duplicate_of_id')->nullable();

            $table->timestamp('received_at');

            // Which configured shape matched, so a bank whose format changed
            // can be found by query instead of by complaint.
            $table->string('matched_pattern', 64)->nullable();
            $table->json('parsed')->nullable();
            $table->string('reason', 64)->nullable();

            $table->foreignUlid('ingest_alias_id')->nullable();
            $table->foreignUlid('ai_draft_id')->nullable();
            $table->foreignUlid('transaction_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['workspace_id', 'channel', 'status']);
            $table->index(['workspace_id', 'dedupe_key']);
            $table->index(['workspace_id', 'received_at']);
        });

        // The address mail has to arrive at to reach a given set of books.
        //
        // Only the hash is stored. The token is a bearer credential — anyone
        // holding it can post into that workspace's inbox — so a leaked
        // database must not hand over a working address for every workspace.
        Schema::create('capture_ingest_aliases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('token_hash', 64)->unique();
            $table->string('domain', 191);
            $table->string('label', 64)->nullable();

            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['workspace_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capture_ingest_aliases');
        Schema::dropIfExists('capture_messages');
    }
};
