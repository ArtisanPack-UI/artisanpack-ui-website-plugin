<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Http\Controllers;

use ArtisanPackUI\Site\Exceptions\DocsSiteException;
use ArtisanPackUI\Site\Exceptions\IntegrationNotConfiguredException;
use ArtisanPackUI\Site\Services\Sync\DocsPackageImporter;
use ArtisanPackUI\Site\Services\Sync\PackageIconSync;
use ArtisanPackUI\Site\Services\Sync\PackageVersionSync;
use ArtisanPackUI\Site\Services\Sync\SyncReport;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The "Sync from docs" button on the Packages list. It calls the three
 * steps in turn (import, versions, icons), each in its own request, and
 * shows each step's {@see SyncReport} counts. The version step is itself
 * split into batches so no request waits on every registry lookup.
 *
 * A step that can't start at all (the docs site isn't configured or can't
 * be read) answers 422 with an admin-friendly message. Per-package
 * problems are reported inside the 200 report instead.
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

    /**
     * @param  Closure(): SyncReport  $step
     */
    private static function run(Closure $step, string $message): JsonResponse
    {
        try {
            $report = $step();
        } catch (IntegrationNotConfiguredException|DocsSiteException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => $message, 'report' => $report->toArray()]);
    }
}
