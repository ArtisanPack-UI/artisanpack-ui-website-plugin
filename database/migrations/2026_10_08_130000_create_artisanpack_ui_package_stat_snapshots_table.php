<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per marketing package per day: its downloads and GitHub numbers
 * as the daily stats job read them, so the Stats tab can chart trends
 * (see {@see \ArtisanPackUI\Site\Models\PackageStatSnapshot}).
 *
 * Every figure is nullable because either source can be unavailable on a
 * given day, and a gap must not read as zero. `package_id` has no foreign
 * key because the `packages` table belongs to the host's content type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artisanpack_ui_package_stat_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('package_id');
            $table->date('date');
            $table->unsignedBigInteger('downloads_daily')->nullable();
            $table->unsignedBigInteger('downloads_monthly')->nullable();
            $table->unsignedBigInteger('downloads_total')->nullable();
            $table->unsignedInteger('stars')->nullable();
            $table->unsignedInteger('forks')->nullable();
            $table->unsignedInteger('watchers')->nullable();
            $table->unsignedInteger('open_issues')->nullable();
            $table->unsignedInteger('open_prs')->nullable();
            $table->unsignedInteger('dependents')->nullable();
            $table->string('latest_release')->nullable();
            $table->timestamp('latest_release_at')->nullable();
            $table->timestamps();

            $table->unique(['package_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artisanpack_ui_package_stat_snapshots');
    }
};
