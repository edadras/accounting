<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('symbol', 32);
            $table->string('kind', 24);

            // Major units, at the same width the fx table uses. A price is
            // market data, not an amount anybody owns, so it is not held in
            // minor units — that conversion happens when it lands on a
            // position, in the position's own currency.
            $table->decimal('price', 30, 12);

            $table->string('currency', 8);
            $table->string('source', 40)->default('static');
            $table->timestamp('captured_at');
            $table->timestamps();

            // History, not state: a second capture in the same second is nudged
            // forward rather than replacing the row it would collide with.
            $table->unique(['symbol', 'kind', 'captured_at']);
            $table->index(['symbol', 'captured_at']);
        });

        // Why a rate was refused, kept out of the rates table so nothing can
        // ever read a rejected number as if it were a rate.
        Schema::create('market_rejections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('provider', 40);
            $table->string('scope', 16);
            $table->string('subject', 64);
            $table->string('value', 128)->nullable();
            $table->string('reason', 40);
            $table->timestamp('rejected_at');
            $table->timestamps();

            $table->index(['scope', 'rejected_at']);
            $table->index(['subject', 'rejected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_rejections');
        Schema::dropIfExists('price_snapshots');
    }
};
