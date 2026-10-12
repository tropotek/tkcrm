<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The client sites that are watched, and every check made of each.
     *
     * A domain carries where it stands now, so a list of them needs no look through the pings:
     * its state, what the site last reported, and when each of those last changed.
     */
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            // The address that is requested: a page, a Laravel /up route or a ttek-stack /tkping route.
            $table->string('url');
            $table->text('notes')->nullable();
            // An inactive domain is not checked.
            $table->boolean('active')->default(true);
            // Whether going down and coming back are told to the owner.
            $table->boolean('alerts')->default(true);
            // App\Enums\DomainState, and when it last became that.
            $table->string('state', 16)->default('unknown');
            $table->dateTime('state_changed_at')->nullable();
            // How many checks in a row have failed. Two make it down.
            $table->unsignedInteger('failures')->default(0);
            $table->dateTime('checked_at')->nullable();
            $table->dateTime('last_ok_at')->nullable();
            // What the site last reported of itself, where its reply was JSON.
            $table->string('version', 64)->nullable();
            $table->dateTime('version_changed_at')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
        });

        Schema::create('domain_pings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->boolean('ok');
            // Null where there was no reply at all.
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            // The reply, where it was JSON.
            $table->json('reply')->nullable();
            // Why it failed, in a few words.
            $table->string('error')->nullable();
            $table->dateTime('checked_at');

            $table->index(['domain_id', 'checked_at']);
            // For pruning.
            $table->index('checked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('domain_pings');
        Schema::dropIfExists('domains');
    }
};
