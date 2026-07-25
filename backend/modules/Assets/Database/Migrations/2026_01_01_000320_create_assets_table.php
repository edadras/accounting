<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind', 24);

            // Amounts are integers in the currency's minor unit. Never a float.
            $table->bigInteger('purchase_price')->default(0);
            $table->date('purchase_date');
            $table->bigInteger('current_value')->nullable();
            $table->string('currency', 8);

            $table->string('depreciation_method', 16)->default('none');

            // A ratio, not money: 0.20 means 20% of the book value each year.
            $table->decimal('depreciation_rate', 9, 6)->nullable();
            $table->unsignedSmallInteger('useful_life_years')->nullable();
            $table->bigInteger('salvage_value')->default(0);

            $table->string('insurance_provider')->nullable();
            $table->date('insurance_expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'kind']);
            $table->index(['workspace_id', 'insurance_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
