<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Jobs;

use ArtisanPackUI\Site\Services\Sync\PackageSyncRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * The daily package sync (roadmap 2.4): imports new docs packages as
 * drafts, syncs every version and icon, and records each package's
 * outcome for its edit screen. See {@see PackageSyncRunner}.
 *
 * Scheduled by {@see \ArtisanPackUI\Site\Support\PluginBootstrapper}
 * through {@see self::schedule()}. Unique and guarded by
 * {@see WithoutOverlapping}, because the scheduler's own
 * `withoutOverlapping()` only covers dispatching it. A run can take up to
 * {@see self::$timeout} seconds, so the queue connection's `retry_after`
 * must be longer than that or the job is handed out twice.
 *
 * @since 0.4.0
 */
final class SyncPackages implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Seconds a run may take: each package can cost a registry, a GitHub
     * and a docs site call.
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
            ->dailyAt('03:00')
            ->name('artisanpack-ui:sync-packages')
            ->withoutOverlapping()
            ->onOneServer();
    }

    public function handle(PackageSyncRunner $runner): void
    {
        $run = $runner->runAll();

        if ([] !== $run['errors'] || $run['report']->failed > 0) {
            Log::warning('ArtisanPack UI daily package sync finished with problems.', [
                'errors'   => $run['errors'],
                'failures' => $run['report']->messages,
            ]);
        }
    }
}
