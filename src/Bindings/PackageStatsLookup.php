<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Bindings;

use ArtisanPackUI\Site\Models\PackageStatSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Every package's latest stat snapshot, read once per request.
 *
 * A query loop binds the same stats on every card, so the first lookup
 * loads the latest row of every package in one query and later lookups
 * read the map. Bound as a scoped instance, so a queue worker or Octane
 * worker starts each job or request with fresh numbers.
 *
 * @since 1.2.0
 */
final class PackageStatsLookup
{
    /**
     * @var array<int, array{downloads: int|null, stars: int|null}>|null
     */
    private ?array $latest = null;

    /**
     * @return array{downloads: int|null, stars: int|null}
     */
    public function latest(int $packageId): array
    {
        $this->latest ??= $this->load();

        return $this->latest[$packageId] ?? ['downloads' => null, 'stars' => null];
    }

    /**
     * @return array<int, array{downloads: int|null, stars: int|null}>
     */
    private function load(): array
    {
        $table  = (new PackageStatSnapshot)->getTable();
        $newest = DB::table($table)->select('package_id', DB::raw('MAX(date) as newest'))->groupBy('package_id');

        return DB::table($table, 'snapshots')
            ->joinSub($newest, 'newest', static function ($join): void {
                $join->on('snapshots.package_id', '=', 'newest.package_id')->on('snapshots.date', '=', 'newest.newest');
            })
            ->get(['snapshots.package_id', 'snapshots.downloads_total', 'snapshots.stars'])
            ->mapWithKeys(static fn (object $row): array => [(int) $row->package_id => [
                'downloads' => null === $row->downloads_total ? null : (int) $row->downloads_total,
                'stars'     => null === $row->stars ? null : (int) $row->stars,
            ]])
            ->all();
    }
}
