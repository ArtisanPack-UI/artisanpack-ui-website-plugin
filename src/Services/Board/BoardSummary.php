<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Board;

use ArtisanPackUI\Site\Exceptions\GitHubException;
use ArtisanPackUI\Site\Exceptions\IntegrationNotConfiguredException;
use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Support\AdminPages;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Card counts per Status column, for the dashboard board widget (roadmap
 * 5.5).
 *
 * The dashboard hydrates every widget on every load, so the project isn't
 * read live each time: the counts for every package are cached together
 * for {@see self::TTL} seconds, and each widget instance narrows them to
 * its own packages. The boards themselves stay live.
 *
 * @phpstan-type Column array{id: string|null, name: string, count: int}
 * @phpstan-type PackageRef array{id: int, title: string}
 * @phpstan-type Counts array{project: array{title: string, url: string}, columns: list<array{id: string|null, name: string}>, counts: array<string, array<string, int>>}
 *
 * @since 0.5.0
 */
class BoardSummary
{
    public const TTL = 60;

    /** The counts key for cards whose repo maps to no package. */
    private const NO_PACKAGE = 'none';

    /** The counts key for the "No status" column. */
    private const NO_STATUS = '';

    public function __construct(
        private readonly ProjectBoardReader $reader,
        private readonly IntegrationSettings $settings,
        private readonly Cache $cache,
    ) {}

    /**
     * Counts per column for the given packages, or for every card when
     * `$packageIds` is empty. Ids that no longer name a package are
     * dropped; if none are left the counts cover every card.
     *
     * A GitHub or settings failure comes back as `error` rather than
     * throwing, so the widget can say what's wrong.
     *
     * @param  list<int>  $packageIds
     *
     * @return array{project: array{title: string, url: string}|null, columns: list<Column>, total: int, packages: list<PackageRef>, boardUrl: string, error: string|null}
     */
    public function summarize(array $packageIds): array
    {
        try {
            $counts = $this->counts();
        } catch (GitHubException|IntegrationNotConfiguredException $exception) {
            return [
                'project'  => null,
                'columns'  => [],
                'total'    => 0,
                'packages' => [],
                'boardUrl' => self::boardUrl([]),
                'error'    => $exception->getMessage(),
            ];
        }

        $selected = self::packages($packageIds);
        $keys     = array_map(static fn (array $package): string => (string) $package['id'], $selected);
        $columns  = [];
        $total    = 0;

        foreach ($counts['columns'] as $column) {
            $byPackage = $counts['counts'][$column['id'] ?? self::NO_STATUS] ?? [];
            $count     = [] === $keys ? array_sum($byPackage) : array_sum(array_intersect_key($byPackage, array_flip($keys)));
            $total += $count;
            $columns[] = [...$column, 'count' => $count];
        }

        return [
            'project'  => $counts['project'],
            'columns'  => $columns,
            'total'    => $total,
            'packages' => $selected,
            'boardUrl' => self::boardUrl(array_map(static fn (array $package): int => $package['id'], $selected)),
            'error'    => null,
        ];
    }

    /**
     * The global board's URL, filtered to `$packageIds` when there are any.
     *
     * @param  list<int>  $packageIds
     */
    public static function boardUrl(array $packageIds): string
    {
        $url = url('/admin/' . AdminPages::SLUG);

        return [] === $packageIds ? $url : $url . '?' . http_build_query(['packages' => implode(',', $packageIds)]);
    }

    /**
     * The packages `$packageIds` name that still exist, A–Z. A package with
     * no cards on the project is kept, so it counts zero rather than
     * widening the widget to every package.
     *
     * @param  list<int>  $packageIds
     *
     * @return list<PackageRef>
     */
    private static function packages(array $packageIds): array
    {
        if ([] === $packageIds) {
            return [];
        }

        return Package::query()
            ->whereKey($packageIds)
            ->orderBy('title')
            ->orderBy('id')
            ->get(['id', 'title'])
            ->map(static fn (Package $package): array => ['id' => (int) $package->getKey(), 'title' => (string) ($package->title ?: __('Untitled'))])
            ->values()
            ->all();
    }

    /**
     * Every card counted by Status option and package, from the cache when
     * it's fresh.
     *
     * @return Counts
     *
     * @throws IntegrationNotConfiguredException
     * @throws GitHubException
     */
    private function counts(): array
    {
        $key    = $this->cacheKey();
        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            /** @var Counts $cached */
            return $cached;
        }

        $board  = $this->reader->read();
        $counts = [];

        foreach ($board->items as $item) {
            $package = null === $item->package ? self::NO_PACKAGE : (string) $item->package['id'];
            $status  = $item->statusId ?? self::NO_STATUS;

            $counts[$status][$package] = ($counts[$status][$package] ?? 0) + 1;
        }

        $data = [
            'project' => ['title' => $board->title, 'url' => $board->url],
            'columns' => [
                ['id' => null, 'name' => __('No status')],
                ...array_map(
                    static fn (array $option): array => ['id' => $option['id'], 'name' => $option['name']],
                    $board->status->options,
                ),
            ],
            'counts' => $counts,
        ];

        $this->cache->put($key, $data, self::TTL);

        return $data;
    }

    /**
     * Keyed on the org and project, so changing either in Settings never
     * shows the old project's counts.
     */
    private function cacheKey(): string
    {
        return 'artisanpack-ui:board:summary:' . sha1(strtolower($this->settings->githubOrganization()) . '|' . (int) $this->settings->github_project_number);
    }
}
