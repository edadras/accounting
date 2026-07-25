<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('deletion_requested_at')->nullable();

            // The moment the grace period ends, frozen at the moment the user
            // asked. Deriving it from today's configured window instead would
            // silently move the date of a deletion already promised.
            $table->timestamp('deletion_purge_after')->nullable();

            $table->index('deletion_purge_after');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // SQLite refuses to drop a column an index still points at, so the
            // index goes first.
            $table->dropIndex(['deletion_purge_after']);
            $table->dropColumn(['deletion_requested_at', 'deletion_purge_after']);
        });
    }
};
