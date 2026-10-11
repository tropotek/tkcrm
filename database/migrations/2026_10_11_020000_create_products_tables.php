<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What is sold: the products an invoice line, a time log or a recurring item is priced from,
     * grouped into categories.
     */
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_category_id')->constrained()->restrictOnDelete();
            $table->string('name');
            // Printed on invoice lines.
            $table->string('code', 64)->unique();
            // Cents (App\Casts\MoneyCast). For labour, the price of an hour.
            $table->integer('price')->default(0);
            // App\Enums\Cycle: the cycle a recurring item for this product starts with.
            $table->string('cycle', 16)->default('each');
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
    }
};
