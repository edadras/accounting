<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Everything the AI layer proposes lands here first. Nothing in this
        // migration touches the ledger: a draft is a suggestion, and it only
        // becomes a transaction when the user confirms it
        // (docs/08-ai-layer.md, opening rule).
        Schema::create('ai_drafts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('kind', 16);          // transaction | receipt
            $table->string('source', 16);        // text | voice | ocr
            $table->string('status', 16)->default('pending');

            // What the user actually said or what came off the page. Kept so a
            // wrong draft can be diagnosed against its input.
            $table->text('input_text')->nullable();

            $table->json('payload');
            $table->json('warnings')->nullable();

            // 0.000–1.000. Not money, so a decimal is safe here.
            $table->decimal('confidence', 4, 3)->default(0);
            $table->boolean('needs_confirmation')->default(true);

            $table->foreignUlid('document_id')->nullable();
            $table->foreignUlid('transaction_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'kind', 'created_at']);
        });

        Schema::create('ai_conversations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title')->nullable();
            $table->string('locale', 8)->default('en');
            $table->timestamp('last_message_at')->nullable();

            $table->timestamps();
            // The user may delete their chat history (docs/08-ai-layer.md §8).
            $table->softDeletes();

            $table->index(['workspace_id', 'user_id', 'last_message_at']);
        });

        Schema::create('ai_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();

            $table->string('role', 16);          // user | assistant | tool
            $table->longText('content')->nullable();

            // The audit trail docs/07-security.md §5.5 requires: which tools ran
            // and with which arguments, kept beside the answer they produced.
            $table->json('tool_calls')->nullable();
            $table->string('tool_name', 64)->nullable();
            $table->json('tool_result')->nullable();

            $table->string('provider', 32)->nullable();
            $table->string('model', 64)->nullable();
            $table->json('usage')->nullable();

            $table->timestamps();

            $table->index(['workspace_id', 'conversation_id', 'created_at']);
        });

        Schema::create('ai_insights', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('type', 48);
            $table->string('severity', 16)->default('info');

            $table->string('title')->nullable();
            $table->text('body')->nullable();

            // The figures the insight was derived from, so the sentence can
            // always be checked against arithmetic rather than trusted.
            $table->json('data');

            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('score', 8, 3)->default(0);

            // Identifies the same finding across runs, so regenerating updates
            // one row instead of stacking duplicates on the dashboard.
            $table->string('fingerprint', 191);

            $table->timestamp('computed_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'fingerprint']);
            $table->index(['workspace_id', 'type', 'dismissed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_insights');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
        Schema::dropIfExists('ai_drafts');
    }
};
