<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Widgets;

use ArtisanPackUI\Site\ArtisanPackUIServiceProvider;
use ArtisanPackUI\Site\Services\Board\BoardSummary;
use ArtisanPackUI\Site\Services\Stats\DashboardStats;
use ArtisanPackUI\Site\Support\Permissions;
use Modules\SiteEditor\Widgets\Contracts\KeystoneAdminWidgetInterface;
use Modules\Users\Models\User;

/**
 * "Packages board": card counts per Status column on the org project,
 * for every package or the ones an instance picks, linking to the global
 * board with the same filter applied (roadmap 5.5).
 *
 * A thin adapter over {@see BoardSummary}, registered like the stats
 * widgets ({@see StatsWidget}) but gated on {@see Permissions::ISSUES_MANAGE},
 * the permission the board it links to needs.
 *
 * @since 1.0.0
 */
class BoardWidget implements KeystoneAdminWidgetInterface
{
    /**
     * @return array{title: string, description: string, capability: string, source: string, default_options: array{packages: list<string>}}
     */
    public static function getWidgetInfo(): array
    {
        return [
            'title'           => 'Packages board',
            'description'     => 'Issue counts per Status column on the org project.',
            'capability'      => Permissions::ISSUES_MANAGE,
            'source'          => ArtisanPackUIServiceProvider::SLUG,
            'default_options' => ['packages' => []],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @return array<string, mixed>
     */
    public static function getData(User $user, array $options): array
    {
        return app(BoardSummary::class)->summarize(self::packageIds($options));
    }

    /**
     * @return array{component: string, default_grid_config: array<string, array{rows: int, cols: int}>, settings_schema: array{fields: list<array<string, mixed>>}}
     */
    public static function extendedInfo(): array
    {
        return [
            'component'           => 'ArtisanPackUIBoardWidget',
            'default_grid_config' => [
                'sm' => ['rows' => 2, 'cols' => 12],
                'md' => ['rows' => 2, 'cols' => 6],
                'lg' => ['rows' => 2, 'cols' => 4],
                'xl' => ['rows' => 2, 'cols' => 3],
            ],
            'settings_schema' => [
                'fields' => [
                    [
                        'name'        => 'packages',
                        'label'       => 'Packages',
                        'type'        => 'multiselect',
                        'description' => 'Leave every package unchecked to count them all.',
                        'default'     => [],
                        'options'     => array_values(array_filter(
                            DashboardStats::packageOptions(),
                            static fn (array $option): bool => DashboardStats::ALL_PACKAGES !== $option['value'],
                        )),
                    ],
                ],
            ],
        ];
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
}
