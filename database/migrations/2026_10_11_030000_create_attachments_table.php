<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Files uploaded against a record: a task, a time log or an expense.
     */
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            // The record it belongs to, by its morph map name and id.
            $table->morphs('attachable');
            $table->string('label');
            // The name the file had when it was uploaded, used again when it is downloaded.
            $table->string('file_name');
            // Where the file is on the private (local) disk, and its thumbnail when it is an image.
            $table->string('path');
            $table->string('thumbnail_path')->nullable();
            $table->unsignedBigInteger('bytes');
            $table->string('mime_type');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
