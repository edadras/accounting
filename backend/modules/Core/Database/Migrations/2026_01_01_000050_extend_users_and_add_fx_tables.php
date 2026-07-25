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
            $table->string('locale', 8)->default('fa')->after('email');
            $table->string('timezone', 64)->default('Asia/Tehran')->after('locale');
            $table->timestamp('last_active_at')->nullable();
        });

        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('base_code', 8);
            $table->string('quote_code', 8);

            // Wide decimal: one IRR in BTC needs a lot of places before it stops
            // being zero.
            $table->decimal('rate', 30, 12);

            $table->string('source', 40)->default('seed');
            $table->timestamp('rated_at');
            $table->timestamps();

            $table->unique(['base_code', 'quote_code', 'rated_at']);
            $table->index(['base_code', 'quote_code', 'rated_at']);
        });

        Schema::create('translations', function (Blueprint $table): void {
            $table->id();
            $table->string('locale', 8);
            $table->string('group', 64)->default('app');
            $table->string('key', 191);
            $table->text('value')->nullable();
            $table->boolean('is_overridden')->default(false);
            $table->timestamps();

            $table->unique(['locale', 'group', 'key']);
            $table->index(['locale', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations');
        Schema::dropIfExists('exchange_rates');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['locale', 'timezone', 'last_active_at']);
        });
    }
};
