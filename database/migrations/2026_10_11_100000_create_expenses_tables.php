<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the business spends: expenses, each from a supplier, grouped into categories that say
     * how much of what is spent in them can be claimed.
     */
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            // Basis points of an expense that can be claimed: 10000 is all of it, 5000 half.
            $table->unsignedInteger('claim_ratio')->default(10000);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            // The supplier it was bought from.
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            // The supplier's own numbers, as printed on what they sent.
            $table->string('invoice_number', 64)->nullable();
            $table->string('receipt_number', 64)->nullable();
            $table->text('description')->nullable();
            $table->date('purchased_on')->index();
            // Cents (App\Casts\MoneyCast). What was paid, with any GST in it. Negative for a refund.
            $table->integer('total');
            // The GST inside the total, 0 where there is none.
            $table->integer('gst_included')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};
