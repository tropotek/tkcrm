<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each time the schedule ran one of its jobs: when, how it went and what it said.
     * Runs older than the days kept are deleted as new ones are recorded.
     */
    public function up(): void
    {
        Schema::create('job_runs', function (Blueprint $table) {
            $table->id();
            // The artisan command, as App\Schedule\ScheduledJobs lists it.
            $table->string('command')->index();
            $table->dateTime('started_at');
            // Null while it is running, or if the worker stopped before it ended.
            $table->dateTime('finished_at')->nullable();
            $table->boolean('succeeded')->nullable();
            $table->text('output')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_runs');
    }
};
