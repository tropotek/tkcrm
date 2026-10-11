<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A piece of work for a client, quoted for, that tasks are grouped under.
     * Its status is not a column: it is worked out from the three dates (App\Models\Project).
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            // Cents (App\Casts\MoneyCast). What the client was quoted for the whole project.
            $table->integer('quote')->default(0);
            $table->date('start_on')->nullable();
            $table->date('end_on')->nullable();
            $table->date('cancelled_on')->nullable();
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
        Schema::dropIfExists('projects');
    }
};
