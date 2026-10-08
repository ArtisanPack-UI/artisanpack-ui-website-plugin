<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Jobs;

use ArtisanPackUI\Site\Services\Sync\PackageSyncRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * The daily package sync (roadmap 2.4): imports new docs packages as
 * drafts, syncs every version and icon, and records each package's
 * outcome for its edit screen. See {@see PackageSyncRunner}.
 *
 * Scheduled by {@see \ArtisanPackUI\Site\Support\PluginBootstrapper}
 * through {@see self::schedule()}.
 *
 * @since 0.4.0
 */
final class SyncPackages implements ShouldQueue
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

    public static function schedule(Schedule $schedule): void
    {
        $schedule->job(new self)
            ->dailyAt('03:00')
            ->name('artisanpack-ui:sync-packages')
            ->withoutOverlapping();
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
