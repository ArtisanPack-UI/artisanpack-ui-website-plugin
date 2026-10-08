<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Jobs;

use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Models\PackageStatSnapshot;
use ArtisanPackUI\Site\Models\PackageSyncState;
use ArtisanPackUI\Site\Services\Stats\PackageStatsCollector;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The daily stats snapshot (roadmap 4.1): one
 * {@see PackageStatSnapshot} per package for today, from
 * {@see PackageStatsCollector}.
 *
 * Idempotent per date: a second run on the same day overwrites that day's
 * row instead of adding one. A package with nothing readable (no registry
 * name or repo, or both sources down) gets no row, so the chart shows a
 * gap rather than zeroes.
 *
 * Runs after {@see SyncPackages} so a newly synced registry name or repo
 * is already in place. Unique and guarded by {@see WithoutOverlapping} for
 * the same reasons as that job. Each run also drops the snapshots and sync state
 * of packages that no longer exist, which have no foreign key to cascade
 * them away.
 *
 * @since 1.0.0
 */
final class CollectPackageStats implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Seconds a run may take: each package costs a few registry calls and
     * one GitHub query.
     */
    public int $timeout = 900;

    /**
     * A failed run isn't retried; the next day's run picks it up.
     */
    public int $tries = 1;

    /**
     * Seconds a queued run blocks another from being queued.
     */
    public int $uniqueFor = 3600;

    /**
     * Two runs never execute at once, even across workers: the second is
     * dropped rather than released back onto the queue. The lock outlives
     * {@see self::$timeout} so a run that is killed can't hold it forever.
     *
     * @return list<object>
     *
     * @since 1.0.0
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping(self::class))->dontRelease()->expireAfter(1200)];
    }

    public static function schedule(Schedule $schedule): void
    {
        $schedule->job(new self)
            ->dailyAt('04:00')
            ->name('artisanpack-ui:collect-package-stats')
            ->withoutOverlapping()
            ->onOneServer();
    }

    public function handle(PackageStatsCollector $collector): void
    {
        self::purgeOrphans();

        $today    = Carbon::today();
        $problems = [];

        foreach (Package::query()->lazyById() as $package) {
            $stats = $collector->collect($package);

            foreach ($stats->errors as $error) {
                $problems[] = ($package->title ?: '#' . $package->getKey()) . ': ' . $error;
            }

            if (! $stats->hasData()) {
                continue;
            }

            PackageStatSnapshot::record((int) $package->getKey(), $today, $stats->toSnapshotAttributes());
        }

        if ([] !== $problems) {
            Log::warning('ArtisanPack UI daily stats snapshot finished with problems.', ['problems' => $problems]);
        }
    }

    /**
     * Delete snapshots and sync state left behind by deleted packages.
     *
     * @since 1.0.0
     */
    private static function purgeOrphans(): void
    {
        PackageStatSnapshot::query()->whereNotIn('package_id', Package::query()->select('id'))->delete();
        PackageSyncState::query()->whereNotIn('package_id', Package::query()->select('id'))->delete();
    }
}
