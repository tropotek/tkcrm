<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Clients, who are invoiced, and suppliers, who expenses are bought from.
     */
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            // App\Enums\CompanyType: client or supplier.
            $table->string('type', 16)->default('client')->index();
            $table->string('name');
            $table->string('alias')->nullable();
            $table->string('abn', 16)->nullable();
            $table->string('website')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('phone', 32)->nullable();
            // Where invoices and statements are sent, with a copy to accounts_email.
            $table->string('email')->nullable();
            $table->string('accounts_email')->nullable();
            $table->text('address')->nullable();
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
        Schema::dropIfExists('companies');
    }
};
