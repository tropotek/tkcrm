<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a client is billed for again and again: hosting, a domain, a care plan. A due item is
     * added to the client's open invoice and waits there; there is no switch to issue it unattended.
     */
    public function up(): void
    {
        Schema::create('recurrings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            // Cents (App\Casts\MoneyCast). Null follows the product's price; zero is a price.
            $table->integer('price')->nullable();
            // App\Enums\Cycle, any but each.
            $table->string('cycle', 16)->default('year');
            $table->date('start_on');
            // The last day it may bill on, if it ends.
            $table->date('end_on')->nullable();
            // When it last billed and when it bills next, and how many times it has: kept by the run.
            $table->date('previous_on')->nullable();
            $table->date('next_on')->index();
            $table->unsignedInteger('count')->default(0);
            // An inactive item still moves on each cycle, with no line billed.
            $table->boolean('active')->default(true);
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurrings');
    }
};
