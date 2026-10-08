<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When sync last checked each package and what went wrong, so the edit
 * screen can show the outcome of the daily run or a "Sync now" (see
 * {@see \ArtisanPackUI\Site\Models\PackageSyncState}).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artisanpack_ui_package_syncs', function (Blueprint $table): void {
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('artisanpack_ui_package_syncs', function (Blueprint $table): void {
            $table->dropColumn(['last_checked_at', 'last_error']);
        });
    }
};
