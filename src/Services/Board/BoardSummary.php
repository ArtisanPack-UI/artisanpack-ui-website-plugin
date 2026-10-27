<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Board;

use ArtisanPackUI\Site\Exceptions\GitHubException;
use ArtisanPackUI\Site\Exceptions\IntegrationNotConfiguredException;
use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Support\AdminPages;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Cache as CacheLocks;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Card counts per Status column, for the dashboard board widget (roadmap
 * 5.5).
 *
 * Reading the project pages through all of it, which can take close to
 * the server's timeout, so a widget never waits on it. The counts for
 * every package are cached together, and each widget instance narrows
 * them to its own packages. Counts older than {@see self::TTL} seconds
 * are still served, and {@see self::refresh()} reads the project again
 * once the response has been sent. Before the first read finishes there
 * are no counts to serve, and the summary says it's `pending`. The
 * boards themselves stay live.
 *
 * @phpstan-type Column array{id: string|null, name: string, count: int}
 * @phpstan-type PackageRef array{id: int, title: string}
 * @phpstan-type Counts array{project: array{title: string, url: string}, columns: list<array{id: string|null, name: string}>, counts: array<string, array<string, int>>}
 * @phpstan-type Entry array{counts: Counts|null, countsAt: int|null, error: string|null, at: int}
 *
 * @since 1.0.0
 */
class BoardSummary
{
    /** Seconds the cached counts are served before they're read again. */
    public const TTL = 60;

    /**
     * Seconds after their last successful read that counts are still
     * served while reads keep failing. Past it the widget shows the error.
     */
    public const STALE_TTL = 604800;

    /**
     * Seconds a refresh may run, which is also how long its lock is held.
     * Past the server's usual 30-second limit, because a read of a large
     * project can take nearly that.
     */
    public const REFRESH_SECONDS = 120;

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
     * Serves the cached counts, even stale ones, and refreshes them after
     * the response when they're stale or missing. With nothing cached yet
     * the summary is `pending`. A GitHub or settings failure with no
     * counts to fall back on comes back as `error` rather than throwing,
     * so the widget can say what's wrong; with counts to serve it comes
     * back as `warning` beside them.
     *
     * @param  list<int>  $packageIds
     *
     * @return array{project: array{title: string, url: string}|null, columns: list<Column>, total: int, packages: list<PackageRef>, boardUrl: string, error: string|null, warning: string|null, pending: bool}
     */
    public function summarize(array $packageIds): array
    {
        $entry = $this->entry();

        if (null === $entry || now()->getTimestamp() - $entry['at'] >= self::TTL) {
            defer(fn (): bool => $this->refresh(), 'artisanpack-ui:board:summary');
        }

        $counts = $entry['counts'] ?? null;

        if (null === $counts) {
            return [
                'project'  => null,
                'columns'  => [],
                'total'    => 0,
                'packages' => [],
                'boardUrl' => self::boardUrl([]),
                'error'    => $entry['error'] ?? null,
                'warning'  => null,
                'pending'  => null === $entry,
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
            'warning'  => null === $entry['error'] ? null : __('These counts may be out of date: :error', ['error' => $entry['error']]),
            'pending'  => false,
        ];
    }

    /**
     * Read the project and cache its counts, unless another request is
     * already reading it or the counts were read within {@see self::TTL}
     * seconds. A failure keeps the counts already cached for up to
     * {@see self::STALE_TTL} seconds after their last successful read, and
     * is recorded so the next refresh waits out {@see self::TTL} instead
     * of retrying on every request.
     *
     * @return bool Whether this call read the project.
     */
    public function refresh(): bool
    {
        $key = $this->cacheKey();

        return (bool) CacheLocks::lock($key . ':refresh', self::REFRESH_SECONDS)->get(function () use ($key): bool {
            $previous = $this->entry();
            $now      = now()->getTimestamp();

            // A request that queued its refresh while another was reading.
            if (null !== $previous && $now - $previous['at'] < self::TTL) {
                return false;
            }

            @set_time_limit(self::REFRESH_SECONDS);

            try {
                $entry = ['counts' => $this->read(), 'countsAt' => $now, 'error' => null, 'at' => $now];
            } catch (GitHubException|IntegrationNotConfiguredException $exception) {
                $entry = self::failed($previous, $exception->getMessage(), $now);
            } catch (Throwable $exception) {
                report($exception);

                $entry = self::failed($previous, __('The project board couldn\'t be read. The error has been logged.'), $now);
            }

            $this->cache->put($key, $entry, self::STALE_TTL);

            return true;
        });
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
     * The entry a failed read leaves: the previous counts while they're
     * within {@see self::STALE_TTL} of their last successful read, and the
     * error.
     *
     * @param  Entry|null  $previous
     *
     * @return Entry
     */
    private static function failed(?array $previous, string $error, int $now): array
    {
        $countsAt = $previous['countsAt'] ?? null;
        $keep     = null !== $countsAt && $now - $countsAt < self::STALE_TTL;

        return [
            'counts'   => $keep ? $previous['counts'] : null,
            'countsAt' => $keep ? $countsAt : null,
            'error'    => $error,
            'at'       => $now,
        ];
    }

    /**
     * The cached counts and when they were read, or null before the first
     * read.
     *
     * @return Entry|null
     */
    private function entry(): ?array
    {
        $entry = $this->cache->get($this->cacheKey());

        /** @var Entry|null */
        return is_array($entry) && array_key_exists('countsAt', $entry) ? $entry : null;
    }

    /**
     * Every card counted by Status option and package, read from the
     * project.
     *
     * @return Counts
     *
     * @throws IntegrationNotConfiguredException
     * @throws GitHubException
     */
    private function read(): array
    {
        $board  = $this->reader->read();
        $counts = [];

        foreach ($board->items as $item) {
            $package = null === $item->package ? self::NO_PACKAGE : (string) $item->package['id'];
            $status  = $item->statusId ?? self::NO_STATUS;

            $counts[$status][$package] = ($counts[$status][$package] ?? 0) + 1;
        }

        return [
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
