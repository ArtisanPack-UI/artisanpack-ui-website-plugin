<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Dashboard;

use ArtisanPackUI\Site\Services\Board\BoardSummary;
use ArtisanPackUI\Site\Services\Stats\DashboardStats;
use ArtisanPackUI\Site\Widgets\StatsWidget;
use InvalidArgumentException;

/**
 * The dashboard widgets' payloads, served after the dashboard renders.
 *
 * The host's dashboard calls every widget's `getData()` while it renders,
 * one after another. A dashboard full of the plugin's widgets used to
 * build every payload in that one request, the board's GitHub read
 * included, which could run past the server's timeout. So each widget's
 * `getData()` now returns only {@see self::deferred()}, the URL of its
 * payload. The bundle shows a skeleton in the widget's place and fetches
 * that URL, which resolves the payload here ({@see self::resolve()}).
 *
 * The URL carries the instance's options, and they are read again as
 * untrusted input: every option is checked or clamped here, as it was
 * when the dashboard hydrated it.
 *
 * Lives outside `src/Widgets` because those classes implement a host
 * interface that bare Testbench can't load ({@see StatsWidget}).
 *
 * @since 1.3.0
 */
final class DashboardWidgetData
{
    public const DOWNLOADS_KPI = 'downloads-kpi';

    public const DOWNLOADS_TREND = 'downloads-trend';

    public const GITHUB_OVERVIEW = 'github-overview';

    public const TOP_PACKAGES = 'top-packages';

    public const RELEASE_FEED = 'release-feed';

    public const BOARD = 'board';

    /** The widgets behind the `stats.view` permission. */
    public const STATS_WIDGETS = [
        self::DOWNLOADS_KPI,
        self::DOWNLOADS_TREND,
        self::GITHUB_OVERVIEW,
        self::TOP_PACKAGES,
        self::RELEASE_FEED,
    ];

    public const KPI_DEFAULT_METRIC = 'total';

    public const TREND_DEFAULT_RANGE = 30;

    public const TOP_DEFAULT_WINDOW = 30;

    public const TOP_DEFAULT_LIMIT = 5;

    public const TOP_MAX_LIMIT = 20;

    public const RELEASES_DEFAULT_LIMIT = 8;

    public const RELEASES_MAX_LIMIT = 30;

    /**
     * The options each widget reads, so its URL carries nothing else.
     *
     * @var array<string, list<string>>
     */
    private const OPTIONS = [
        self::DOWNLOADS_KPI   => ['metric', 'package'],
        self::DOWNLOADS_TREND => ['package', 'range'],
        self::GITHUB_OVERVIEW => [],
        self::TOP_PACKAGES    => ['rank_by', 'window', 'limit'],
        self::RELEASE_FEED    => ['limit'],
        self::BOARD           => ['packages'],
    ];

    public function __construct(
        private readonly DashboardStats $stats,
        private readonly BoardSummary $board,
    ) {}

    /**
     * What a widget's `getData()` returns: the URL of its payload, carrying
     * the options it reads.
     *
     * @param  array<string, mixed>  $options
     *
     * @return array{deferred: string}
     */
    public static function deferred(string $widget, array $options): array
    {
        $query = array_intersect_key($options, array_flip(self::optionsFor($widget)));

        $url = self::BOARD === $widget
            ? route('artisanpack-ui.widgets.board', $query)
            : route('artisanpack-ui.widgets.stats', ['widget' => $widget, ...$query]);

        return ['deferred' => $url];
    }

    /**
     * A widget's payload for an instance's options.
     *
     * @param  array<string, mixed>  $options
     *
     * @return array<string, mixed>
     */
    public function resolve(string $widget, array $options): array
    {
        return match ($widget) {
            self::DOWNLOADS_KPI => $this->stats->downloads(
                is_string($options['metric'] ?? null) ? $options['metric'] : self::KPI_DEFAULT_METRIC,
                self::packageId($options),
            ),
            self::DOWNLOADS_TREND => $this->stats->downloadsTrend(
                self::packageId($options),
                self::intOption($options, 'range', self::TREND_DEFAULT_RANGE, 1, 365),
            ),
            self::GITHUB_OVERVIEW => $this->stats->gitHubOverview(),
            self::TOP_PACKAGES    => $this->stats->topPackages(
                is_string($options['rank_by'] ?? null) ? $options['rank_by'] : DashboardStats::RANK_BY_DOWNLOADS,
                self::intOption($options, 'window', self::TOP_DEFAULT_WINDOW, 1, 365),
                self::intOption($options, 'limit', self::TOP_DEFAULT_LIMIT, 1, self::TOP_MAX_LIMIT),
            ),
            self::RELEASE_FEED => $this->stats->releaseFeed(
                self::intOption($options, 'limit', self::RELEASES_DEFAULT_LIMIT, 1, self::RELEASES_MAX_LIMIT),
            ),
            self::BOARD => $this->board->summarize(self::packageIds($options)),
            default     => throw new InvalidArgumentException('Unknown dashboard widget [' . $widget . '].'),
        };
    }

    /**
     * The package ids an instance's `packages` option names.
     *
     * @param  array<string, mixed>  $options
     *
     * @return list<int>
     */
    public static function packageIds(array $options): array
    {
        $values = is_array($options['packages'] ?? null) ? $options['packages'] : [];
        $ids    = array_map(static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0, $values);

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * @return list<string>
     */
    private static function optionsFor(string $widget): array
    {
        return self::OPTIONS[$widget] ?? throw new InvalidArgumentException('Unknown dashboard widget [' . $widget . '].');
    }

    /**
     * The package an instance's `package` option names, or null for every
     * package.
     *
     * @param  array<string, mixed>  $options
     */
    private static function packageId(array $options): ?int
    {
        $value = $options['package'] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * An integer option, clamped to `[$min, $max]`.
     *
     * @param  array<string, mixed>  $options
     */
    private static function intOption(array $options, string $name, int $default, int $min, int $max): int
    {
        $value = $options[$name] ?? null;

        return is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
    }
}
