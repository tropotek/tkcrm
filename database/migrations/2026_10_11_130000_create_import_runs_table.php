<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each time tktask's data was imported. The last one's finishing time is how a later run
     * tells what the import brought from what has been made or changed in tkcrm since.
     */
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->dateTime('started_at');
            $table->dateTime('finished_at');
            // Where it was read from, without the password.
            $table->string('source');
            // How many rows each pass brought over, by its name.
            $table->json('imported');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_runs');
    }
};
