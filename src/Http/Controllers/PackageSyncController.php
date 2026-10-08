<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Http\Controllers;

use ArtisanPackUI\Site\Exceptions\DocsSiteException;
use ArtisanPackUI\Site\Exceptions\IntegrationNotConfiguredException;
use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Models\PackageSyncState;
use ArtisanPackUI\Site\Services\Sync\DocsPackageImporter;
use ArtisanPackUI\Site\Services\Sync\PackageIconSync;
use ArtisanPackUI\Site\Services\Sync\PackageSyncRunner;
use ArtisanPackUI\Site\Services\Sync\PackageVersionSync;
use ArtisanPackUI\Site\Services\Sync\SyncReport;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The "Sync from docs" button on the Packages list. It calls the three
 * steps in turn (import, versions, icons), each in its own request, and
 * shows each step's {@see SyncReport} counts. The version step is itself
 * split into batches so no request waits on every registry lookup.
 *
 * A step that can't start at all (the docs site isn't configured or can't
 * be read) answers 422 with an admin-friendly message, and an import that
 * finds another one still running answers 409. Per-package problems are
 * reported inside the 200 report instead.
 *
 * Also the Edit Package sync status panel: the package's last sync
 * outcome, and its "Sync now" action, which runs all three steps for that
 * package alone through {@see PackageSyncRunner}.
 *
 * @since 0.3.0
 */
final class PackageSyncController
{
    public function import(DocsPackageImporter $importer): JsonResponse
    {
        return self::run(static fn (): SyncReport => $importer->import(), __('Packages imported from the docs site.'));
    }

    /**
     * One batch of the version sync; the button repeats it with the
     * report's `next` as `after` until `next` is null.
     */
    public function versions(Request $request, PackageVersionSync $versions): JsonResponse
    {
        $after = (int) ($request->validate(['after' => ['nullable', 'integer', 'min:0']])['after'] ?? 0);

        return self::run(static fn (): SyncReport => $versions->sync($after), __('Package versions synced.'));
    }

    public function icons(PackageIconSync $icons): JsonResponse
    {
        return self::run(static fn (): SyncReport => $icons->sync(), __('Package icons synced.'));
    }

    public function status(Package $package): JsonResponse
    {
        return response()->json(['status' => self::presentStatus($package)]);
    }

    /**
     * Sync one package now. Step failures don't fail the request: they are
     * part of the outcome the panel shows, just as the daily run records
     * them.
     */
    public function syncNow(Package $package, PackageSyncRunner $runner): JsonResponse
    {
        $run = $runner->runOne($package);

        return response()->json([
            'message' => [] === $run['errors'] && 0 === $run['report']->failed
                ? __('Package synced.')
                : __('Sync finished with problems.'),
            'report' => $run['report']->toArray(),
            'status' => self::presentStatus($package->refresh()),
        ]);
    }

    /**
     * @return array{lastSyncedAt: string|null, lastCheckedAt: string|null, lastError: string|null, linked: bool}
     */
    private static function presentStatus(Package $package): array
    {
        $state = PackageSyncState::query()->where('package_id', $package->getKey())->first();

        return [
            'lastSyncedAt'  => $package->last_synced_at?->toIso8601String(),
            'lastCheckedAt' => $state?->last_checked_at?->toIso8601String(),
            'lastError'     => $state?->last_error,
            'linked'        => null !== $package->docs_package_id,
        ];
    }

    /**
     * @param  Closure(): SyncReport  $step
     */
    private static function run(Closure $step, string $message): JsonResponse
    {
        try {
            $report = $step();
        } catch (IntegrationNotConfiguredException|DocsSiteException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (LockTimeoutException) {
            return response()->json(['message' => __('A sync is already running. Try again in a minute.')], 409);
        }

        return response()->json(['message' => $message, 'report' => $report->toArray()]);
    }
}
