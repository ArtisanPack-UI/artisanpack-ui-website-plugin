<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Release announcements: the GitHub webhook secret, the Bluesky and Mastodon
 * credentials the announcements post with, and the repositories that are
 * never announced.
 *
 * The secret, app password and access token are `text` because they are
 * stored encrypted (see {@see \ArtisanPackUI\Site\Models\IntegrationSettings}).
 * `announcement_excluded_repositories` is null until the Settings page saves
 * a list, which leaves the default exclusions in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artisanpack_ui_settings', function (Blueprint $table): void {
            $table->text('github_webhook_secret')->nullable();
            $table->string('bluesky_handle')->nullable();
            $table->text('bluesky_app_password')->nullable();
            $table->string('mastodon_instance_url')->nullable();
            $table->text('mastodon_access_token')->nullable();
            $table->text('announcement_excluded_repositories')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('artisanpack_ui_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'github_webhook_secret',
                'bluesky_handle',
                'bluesky_app_password',
                'mastodon_instance_url',
                'mastodon_access_token',
                'announcement_excluded_repositories',
            ]);
        });
    }
};
