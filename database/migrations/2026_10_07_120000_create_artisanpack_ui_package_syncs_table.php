<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-package sync state the plugin keeps for itself, one row per
 * marketing package (see {@see \ArtisanPackUI\Site\Models\PackageSyncState}).
 *
 * A separate table rather than columns on `packages`: the host's dynamic
 * edit screen only knows about custom fields, so a plain column there
 * would neither show nor survive the way this state needs to.
 *
 * `package_id` has no foreign key because the `packages` table belongs to
 * the host's content type and may not exist when this runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artisanpack_ui_package_syncs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('package_id')->unique();
            $table->json('last_synced_icon')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artisanpack_ui_package_syncs');
    }
};
