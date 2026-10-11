<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invoices, their lines and the payments made against them. They come before the tasks,
     * because a billed task points at the line it became.
     *
     * An invoice is open until it is issued. Issuing gives it its number and due date and stores
     * its GST rate and totals, which nothing recalculates afterwards; until then all of those are null.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            // Allocated at issue from a counter, and kept if the invoice is re-opened or cancelled.
            $table->unsignedBigInteger('number')->nullable()->unique();
            $table->string('purchase_order')->nullable();
            // Basis points of the sub-total: 1000 is 10%.
            $table->unsignedInteger('discount')->default(0);
            $table->text('billing_address')->nullable();
            $table->date('issued_on')->nullable()->index();
            $table->date('due_on')->nullable();
            $table->date('paid_on')->nullable();
            $table->date('cancelled_on')->nullable();
            $table->text('notes')->nullable();
            // What issuing freezes, null while the invoice is open: the GST rate charged on the whole
            // invoice (basis points, 0 for none) and the totals, in cents (App\Casts\MoneyCast).
            $table->unsignedInteger('gst_rate')->nullable();
            $table->integer('subtotal')->nullable();
            $table->integer('discount_total')->nullable();
            $table->integer('gst_total')->nullable();
            $table->integer('total')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            // Only an open invoice is ever deleted, and its lines go with it.
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            // Copied from the product, like the price: the line does not point at the product.
            $table->string('product_code')->nullable();
            $table->text('description');
            // A decimal string ("1.25"), kept as text so that it is never read as a float.
            $table->string('quantity', 20)->default('1');
            // Cents (App\Casts\MoneyCast). It can be negative, for a line that takes money off.
            $table->integer('unit_price');
            $table->text('notes')->nullable();
            // The record the line was made from, by its morph map name: a task or a recurring item.
            $table->nullableMorphs('source');
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            // Cents (App\Casts\MoneyCast).
            $table->integer('amount');
            // App\Enums\PaymentMethod.
            $table->string('method', 20);
            $table->dateTime('received_at');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
