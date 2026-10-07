<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The plugin's integration settings: one row holding the docs site and
 * GitHub App connection details.
 *
 * Credentials and identifiers are `text` because they are stored encrypted
 * (see {@see \ArtisanPackUI\Site\Models\IntegrationSettings}), and a
 * ciphertext is far longer than the value it wraps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artisanpack_ui_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('docs_base_url')->nullable();
            $table->text('docs_api_token')->nullable();
            $table->text('github_app_id')->nullable();
            $table->text('github_private_key')->nullable();
            $table->text('github_installation_id')->nullable();
            $table->string('github_organization')->nullable();
            $table->unsignedInteger('github_project_number')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artisanpack_ui_settings');
    }
};
