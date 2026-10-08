<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Http\Controllers;

use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Models\PackageStatSnapshot;
use ArtisanPackUI\Site\Services\Stats\PackageStatsCollector;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The Edit Package Stats tab (roadmap 4.2): live KPIs and compatibility
 * read from Packagist/npm and GitHub, plus the daily snapshots for the
 * trend charts over the chosen range.
 *
 * Live figures are cached for {@see self::LIVE_TTL} seconds per package,
 * so flipping the range or reopening the tab doesn't spend registry and
 * GitHub calls each time. A partial result (one source failed) isn't
 * cached, so the next load retries. The cache key covers the registry
 * name and repo, so editing either reads fresh figures.
 *
 * @since 0.4.0
 */
final class PackageStatsController
{
    /** Days of history the range selector offers. */
    public const RANGES = [30, 90, 365];

    public const DEFAULT_RANGE = 90;

    public const LIVE_TTL = 600;

    public function show(Request $request, Package $package, PackageStatsCollector $collector, Cache $cache): JsonResponse
    {
        $range = (int) ($request->validate([
            'range' => ['nullable', 'integer', Rule::in(self::RANGES)],
        ])['range'] ?? self::DEFAULT_RANGE);

        $key  = 'artisanpack-ui:stats:live:' . $package->getKey() . ':' . sha1(implode('|', [
            $package->registry, $package->composer_name, $package->npm_name, $package->github_repo,
        ]));
        $live = $cache->get($key);

        if (! is_array($live)) {
            $live = $collector->collect($package)->toArray();

            if ([] === $live['errors']) {
                $cache->put($key, $live, self::LIVE_TTL);
            }
        }

        $history = PackageStatSnapshot::query()
            ->where('package_id', $package->getKey())
            ->whereDate('date', '>=', Carbon::today()->subDays($range - 1)->toDateString())
            ->orderBy('date')
            ->get()
            ->map(static fn (PackageStatSnapshot $snapshot): array => $snapshot->toChartPoint())
            ->values();

        return response()->json([
            ...$live,
            'range'   => $range,
            'ranges'  => self::RANGES,
            'history' => $history,
        ]);
    }
}
