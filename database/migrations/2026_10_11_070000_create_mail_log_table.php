<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every message tkcrm sends on purpose, one row each, from the moment it is queued.
     */
    public function up(): void
    {
        Schema::create('mail_log', function (Blueprint $table) {
            $table->id();
            // App\Enums\MailKind.
            $table->string('kind', 20)->index();
            // The record it is about, by its morph map name: an invoice, a company.
            $table->nullableMorphs('related');
            // Who it is for, as it was addressed: lists of addresses. A redirect does not change these.
            $table->json('to');
            $table->json('cc');
            $table->json('bcc');
            $table->string('subject');
            // App\Enums\MailState: queued, sent or failed.
            $table->string('state', 10)->default('queued')->index();
            // What the last failed attempt said, kept until it is sent.
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            // The message itself, serialized, so that one that failed can be sent again.
            $table->longText('message');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_log');
    }
};
