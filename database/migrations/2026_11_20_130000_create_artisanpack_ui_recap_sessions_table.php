<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly recap sessions: one admin's recap in progress, from the notes
 * through the outline conversation to the draft post, so closing the modal
 * doesn't lose it.
 *
 * @since 1.6.0
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artisanpack_ui_recap_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->date('publish_date');
            $table->string('title');
            $table->text('notes')->nullable();
            $table->json('window')->nullable();
            $table->json('releases')->nullable();
            $table->json('warnings')->nullable();
            $table->json('pull_requests')->nullable();
            $table->json('sources')->nullable();
            $table->json('messages')->nullable();
            $table->longText('outline')->nullable();
            $table->json('packages')->nullable();
            $table->string('status', 16);
            $table->string('failed_action', 16)->nullable();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('post_id')->nullable();
            $table->string('edit_url')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artisanpack_ui_recap_sessions');
    }
};
