<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Widgets;

use ArtisanPackUI\Site\Services\Stats\DashboardStats;
use Modules\Users\Models\User;

/**
 * "Top packages": packages ranked by downloads gained, or by growth, over a
 * chosen window.
 *
 * @since 1.0.0
 */
class TopPackagesWidget extends StatsWidget
{
    public const DEFAULT_WINDOW = 30;

    public const DEFAULT_LIMIT = 5;

    public const MAX_LIMIT = 20;

    /**
     * @return array{title: string, description: string, capability: string, source: string, default_options: array{rank_by: string, window: string, limit: int}}
     */
    public static function getWidgetInfo(): array
    {
        return [
            'title'           => 'Top packages',
            'description'     => 'Packages ranked by downloads or growth over a window.',
            ...self::baseInfo(),
            'default_options' => [
                'rank_by' => DashboardStats::RANK_BY_DOWNLOADS,
                'window'  => (string) self::DEFAULT_WINDOW,
                'limit'   => self::DEFAULT_LIMIT,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @return array<string, mixed>
     */
    public static function getData(User $user, array $options): array
    {
        return self::stats()->topPackages(
            is_string($options['rank_by'] ?? null) ? $options['rank_by'] : DashboardStats::RANK_BY_DOWNLOADS,
            self::intOption($options, 'window', self::DEFAULT_WINDOW, 1, 365),
            self::intOption($options, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT),
        );
    }

    /**
     * @return array{component: string, default_grid_config: array<string, array{rows: int, cols: int}>, settings_schema: array{fields: list<array<string, mixed>>}}
     */
    public static function extendedInfo(): array
    {
        return [
            'component'           => 'ArtisanPackUITopPackagesWidget',
            'default_grid_config' => [
                'sm' => ['rows' => 2, 'cols' => 12],
                'md' => ['rows' => 2, 'cols' => 6],
                'lg' => ['rows' => 2, 'cols' => 4],
                'xl' => ['rows' => 2, 'cols' => 3],
            ],
            'settings_schema' => [
                'fields' => [
                    [
                        'name'    => 'rank_by',
                        'label'   => 'Rank by',
                        'type'    => 'select',
                        'default' => DashboardStats::RANK_BY_DOWNLOADS,
                        'options' => [
                            ['value' => DashboardStats::RANK_BY_DOWNLOADS, 'label' => 'Downloads gained'],
                            ['value' => DashboardStats::RANK_BY_GROWTH, 'label' => 'Growth (%)'],
                        ],
                    ],
                    self::daysField('window', 'Window', DashboardStats::TOP_WINDOWS, self::DEFAULT_WINDOW),
                    [
                        'name'    => 'limit',
                        'label'   => 'Number of packages',
                        'type'    => 'number',
                        'min'     => 1,
                        'max'     => self::MAX_LIMIT,
                        'default' => self::DEFAULT_LIMIT,
                    ],
                ],
            ],
        ];
    }
}
