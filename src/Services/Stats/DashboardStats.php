<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Stats;

use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Models\PackageStatSnapshot;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as BaseCollection;

/**
 * The payloads of the dashboard stats widgets (roadmap 4.3), read from the
 * daily snapshots alone.
 *
 * The host hydrates every widget on every dashboard load, so nothing here
 * calls Packagist, npm or GitHub: the figures are as fresh as the last
 * daily stats run, and each payload says which day that was. A figure a
 * snapshot doesn't have is null, never zero. Snapshots of deleted packages
 * are ignored.
 *
 * The widget classes in `src/Widgets` are thin adapters over this, because
 * they implement a host interface that bare Testbench can't load.
 *
 * @phpstan-type PackageRef array{id: int, title: string}
 *
 * @since 0.5.0
 */
class DashboardStats
{
    /** Value of the widgets' package option meaning every package. */
    public const ALL_PACKAGES = 'all';

    public const DOWNLOAD_METRICS = ['total', 'monthly', 'daily'];

    /** Days of history the trend widget offers. */
    public const TREND_RANGES = [30, 90, 365];

    /** Windows, in days, the top packages widget ranks over. */
    public const TOP_WINDOWS = [7, 30, 90];

    public const RANK_BY_DOWNLOADS = 'downloads';

    public const RANK_BY_GROWTH = 'growth';

    /**
     * The package option's choices: every package, then each package by
     * title.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function packageOptions(): array
    {
        return [
            ['value' => self::ALL_PACKAGES, 'label' => __('All packages')],
            ...array_map(
                static fn (array $package): array => ['value' => (string) $package['id'], 'label' => $package['title']],
                array_values(self::packages()),
            ),
        ];
    }

    /**
     * Downloads (total, monthly or daily) for one package, or summed over
     * every package from each one's latest snapshot.
     *
     * @return array{metric: string, package: PackageRef|null, value: int|null, asOf: string|null}
     */
    public function downloads(string $metric, ?int $packageId): array
    {
        $metric    = in_array($metric, self::DOWNLOAD_METRICS, true) ? $metric : 'total';
        $packages  = self::packages();
        $package   = null === $packageId ? null : ($packages[$packageId] ?? null);
        $snapshots = $this->latestSnapshots(null === $package ? null : $package['id']);
        $values    = $snapshots->pluck('downloads_' . $metric)->filter(static fn (mixed $value): bool => null !== $value);

        return [
            'metric'  => $metric,
            'package' => $package,
            'value'   => $values->isEmpty() ? null : (int) $values->sum(),
            'asOf'    => self::latestDate($snapshots),
        ];
    }

    /**
     * Daily downloads per day over the last `$days` days: summed over every
     * package (`all`) and for each package, so the widget can switch
     * packages without a round trip. Days with no figure are null.
     *
     * @return array{range: int, ranges: list<int>, selected: string, packages: list<PackageRef>, series: array<string, list<array{date: string, value: int|null}>>}
     */
    public function downloadsTrend(?int $packageId, int $days): array
    {
        $days     = in_array($days, self::TREND_RANGES, true) ? $days : self::TREND_RANGES[0];
        $packages = self::packages();
        $from     = Carbon::today()->subDays($days - 1);
        $dates    = [];

        for ($date = $from->copy(); $date->lte(Carbon::today()); $date->addDay()) {
            $dates[] = $date->toDateString();
        }

        $byPackage = [];
        $totals    = [];

        PackageStatSnapshot::query()
            ->whereIn('package_id', array_keys($packages))
            ->whereDate('date', '>=', $from->toDateString())
            ->whereNotNull('downloads_daily')
            ->get(['package_id', 'date', 'downloads_daily'])
            ->each(static function (PackageStatSnapshot $snapshot) use (&$byPackage, &$totals): void {
                $date = $snapshot->date->toDateString();

                $byPackage[$snapshot->package_id][$date] = (int) $snapshot->downloads_daily;
                $totals[$date]                           = ($totals[$date] ?? 0) + (int) $snapshot->downloads_daily;
            });

        $series = static fn (array $values): array => array_map(
            static fn (string $date): array => ['date' => $date, 'value' => $values[$date] ?? null],
            $dates,
        );

        $result = [self::ALL_PACKAGES => $series($totals)];

        foreach ($packages as $id => $package) {
            $result[(string) $id] = $series($byPackage[$id] ?? []);
        }

        return [
            'range'    => $days,
            'ranges'   => self::TREND_RANGES,
            'selected' => null !== $packageId && isset($packages[$packageId]) ? (string) $packageId : self::ALL_PACKAGES,
            'packages' => array_values($packages),
            'series'   => $result,
        ];
    }

    /**
     * Stars, open issues and open PRs per package, most-starred first, with
     * their totals.
     *
     * @return array{packages: list<array{id: int, title: string, stars: int|null, openIssues: int|null, openPullRequests: int|null}>, totals: array{stars: int, openIssues: int, openPullRequests: int}, asOf: string|null}
     */
    public function gitHubOverview(): array
    {
        $packages  = self::packages();
        $snapshots = $this->latestSnapshots()->filter(static fn (PackageStatSnapshot $snapshot): bool => null !== $snapshot->stars
            || null !== $snapshot->open_issues
            || null !== $snapshot->open_prs);

        $rows = $snapshots
            ->map(static fn (PackageStatSnapshot $snapshot): array => [
                ...$packages[$snapshot->package_id],
                'stars'            => $snapshot->stars,
                'openIssues'       => $snapshot->open_issues,
                'openPullRequests' => $snapshot->open_prs,
            ])
            ->sort(static fn (array $a, array $b): int => [$b['stars'] ?? -1, $a['title']] <=> [$a['stars'] ?? -1, $b['title']])
            ->values()
            ->all();

        return [
            'packages' => $rows,
            'totals'   => [
                'stars'            => (int) $snapshots->sum('stars'),
                'openIssues'       => (int) $snapshots->sum('open_issues'),
                'openPullRequests' => (int) $snapshots->sum('open_prs'),
            ],
            'asOf' => self::latestDate($snapshots),
        ];
    }

    /**
     * Packages ranked by downloads gained over the last `$days` days, or
     * by that gain as a percentage of their total at the window's start.
     *
     * Each package's gain is its latest total less its total on its first
     * snapshot in the window (the window's first day, when the daily job
     * ran then). A gap at the start shortens the window rather than reaching
     * back past it. A package with fewer than two totals in the window
     * can't be ranked and is left out.
     *
     * @return array{rankBy: string, window: int, windows: list<int>, packages: list<array{id: int, title: string, downloads: int, growth: float|null}>}
     */
    public function topPackages(string $rankBy, int $days, int $limit): array
    {
        $rankBy   = self::RANK_BY_GROWTH === $rankBy ? self::RANK_BY_GROWTH : self::RANK_BY_DOWNLOADS;
        $days     = in_array($days, self::TOP_WINDOWS, true) ? $days : 30;
        $packages = self::packages();
        $start    = Carbon::today()->subDays($days)->toDateString();
        $rows     = [];

        $snapshots = PackageStatSnapshot::query()
            ->whereIn('package_id', array_keys($packages))
            ->whereDate('date', '>=', $start)
            ->whereNotNull('downloads_total')
            ->orderBy('date')
            ->get(['package_id', 'date', 'downloads_total'])
            ->groupBy('package_id');

        foreach ($snapshots as $packageId => $history) {
            if ($history->count() < 2) {
                continue;
            }

            $baseline = $history->first();
            $latest   = $history->last();

            $gained = (int) $latest->downloads_total - (int) $baseline->downloads_total;

            $rows[] = [
                ...$packages[(int) $packageId],
                'downloads' => $gained,
                'growth'    => (int) $baseline->downloads_total > 0
                    ? round($gained / (int) $baseline->downloads_total * 100, 1)
                    : null,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => self::RANK_BY_GROWTH === $rankBy
            ? [$b['growth'] ?? -INF, $b['downloads']] <=> [$a['growth'] ?? -INF, $a['downloads']]
            : [$b['downloads'], $a['title']] <=> [$a['downloads'], $b['title']]);

        return [
            'rankBy'   => $rankBy,
            'window'   => $days,
            'windows'  => self::TOP_WINDOWS,
            'packages' => array_slice($rows, 0, max(1, $limit)),
        ];
    }

    /**
     * Each package's latest release, newest first. A package no snapshot
     * has a release for falls back to its synced `version`, undated, after
     * the dated ones.
     *
     * @return array{releases: list<array{id: int, title: string, version: string, releasedAt: string|null}>}
     */
    public function releaseFeed(int $limit): array
    {
        $snapshots = $this->latestSnapshots()->keyBy('package_id');
        $releases  = [];

        Package::query()
            ->orderBy('id')
            ->get(['id', 'title', 'version'])
            ->each(static function (Package $package) use ($snapshots, &$releases): void {
                $snapshot = $snapshots->get($package->getKey());
                $version  = $snapshot?->latest_release ?? (filled($package->version) ? (string) $package->version : null);

                if (null === $version) {
                    return;
                }

                $releases[] = [
                    'id'         => (int) $package->getKey(),
                    'title'      => (string) $package->title,
                    'version'    => $version,
                    'releasedAt' => null !== $snapshot?->latest_release ? $snapshot->latest_release_at?->toIso8601String() : null,
                ];
            });

        usort($releases, static fn (array $a, array $b): int => [$b['releasedAt'] ?? '', $a['title']] <=> [$a['releasedAt'] ?? '', $b['title']]);

        return ['releases' => array_slice($releases, 0, max(1, $limit))];
    }

    /**
     * Every package, keyed by id, titled.
     *
     * @return array<int, PackageRef>
     */
    private static function packages(): array
    {
        $packages = [];

        Package::query()
            ->orderBy('title')
            ->orderBy('id')
            ->get(['id', 'title'])
            ->each(static function (Package $package) use (&$packages): void {
                $packages[(int) $package->getKey()] = ['id' => (int) $package->getKey(), 'title' => (string) ($package->title ?: __('Untitled'))];
            });

        return $packages;
    }

    /**
     * Each existing package's most recent snapshot, or one package's.
     *
     * @return Collection<int, PackageStatSnapshot>
     */
    private function latestSnapshots(?int $packageId = null): Collection
    {
        $table  = (new PackageStatSnapshot)->getTable();
        $latest = PackageStatSnapshot::query()
            ->selectRaw('package_id, MAX(date) AS latest_date')
            ->groupBy('package_id');

        return PackageStatSnapshot::query()
            ->select($table . '.*')
            ->joinSub($latest, 'latest', static function (JoinClause $join) use ($table): void {
                $join->on($table . '.package_id', '=', 'latest.package_id')
                    ->on($table . '.date', '=', 'latest.latest_date');
            })
            ->whereIn($table . '.package_id', null === $packageId ? Package::query()->select('id') : [$packageId])
            ->get();
    }

    /**
     * The newest snapshot date among `$snapshots`, or null.
     *
     * @param  BaseCollection<int, PackageStatSnapshot>  $snapshots
     */
    private static function latestDate(BaseCollection $snapshots): ?string
    {
        $date = $snapshots->max(static fn (PackageStatSnapshot $snapshot): string => $snapshot->date->toDateString());

        return is_string($date) ? $date : null;
    }
}
