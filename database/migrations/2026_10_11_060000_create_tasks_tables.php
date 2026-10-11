<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Work done for a client: tasks, the categories they are sorted into, and the time logged on them.
     * A task's status is not a column: it is worked out from its dates (App\Models\Task).
     */
    public function up(): void
    {
        Schema::create('task_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            // Where it comes in a list, lowest first.
            $table->unsignedInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_category_id')->constrained()->restrictOnDelete();
            $table->string('subject');
            $table->text('comments')->nullable();
            // App\Enums\TaskPriority: 0 none, 1 low, 5 medium, 10 high.
            $table->unsignedTinyInteger('priority')->default(0)->index();
            $table->unsignedInteger('estimated_minutes')->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            // When its billable time went onto an invoice, and the line it became. The invoice_lines
            // table comes with the invoices: the constraint is added there.
            $table->timestamp('invoiced_at')->nullable();
            $table->unsignedBigInteger('invoice_line_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('task_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->boolean('billable')->default(true);
            $table->dateTime('started_at');
            $table->unsignedInteger('minutes')->default(0);
            // Cents per hour (App\Casts\MoneyCast), copied from the product's price when the log is made.
            $table->integer('rate');
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_logs');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('task_categories');
    }
};
