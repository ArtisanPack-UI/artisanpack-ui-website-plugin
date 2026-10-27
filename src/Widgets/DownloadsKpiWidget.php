<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Widgets;

use ArtisanPackUI\Site\Services\Dashboard\DashboardWidgetData;
use ArtisanPackUI\Site\Services\Stats\DashboardStats;
use Modules\Users\Models\User;

/**
 * "Package downloads" KPI tile: total, monthly or daily downloads for every
 * package or one, like the host's `KpiTileWidget` and its `metric` option.
 *
 * @since 1.0.0
 */
class DownloadsKpiWidget extends StatsWidget
{
    public const DEFAULT_METRIC = DashboardWidgetData::KPI_DEFAULT_METRIC;

    /**
     * @return array{title: string, description: string, capability: string, source: string, default_options: array{metric: string, package: string}}
     */
    public static function getWidgetInfo(): array
    {
        return [
            'title'           => 'Package downloads',
            'description'     => 'Total, monthly or daily downloads, for every package or one.',
            ...self::baseInfo(),
            'default_options' => [
                'metric'  => self::DEFAULT_METRIC,
                'package' => DashboardStats::ALL_PACKAGES,
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
        return DashboardWidgetData::deferred(DashboardWidgetData::DOWNLOADS_KPI, $options);
    }

    /**
     * @return array{component: string, default_grid_config: array<string, array{rows: int, cols: int}>, settings_schema: array{fields: list<array<string, mixed>>}}
     */
    public static function extendedInfo(): array
    {
        return [
            'component'           => 'ArtisanPackUIDownloadsKpiWidget',
            'default_grid_config' => [
                'sm' => ['rows' => 1, 'cols' => 12],
                'md' => ['rows' => 1, 'cols' => 6],
                'lg' => ['rows' => 1, 'cols' => 4],
                'xl' => ['rows' => 1, 'cols' => 3],
            ],
            'settings_schema' => [
                'fields' => [
                    [
                        'name'    => 'metric',
                        'label'   => 'Metric',
                        'type'    => 'select',
                        'default' => self::DEFAULT_METRIC,
                        'options' => [
                            ['value' => 'total', 'label' => 'Total downloads'],
                            ['value' => 'monthly', 'label' => 'Monthly downloads'],
                            ['value' => 'daily', 'label' => 'Daily downloads'],
                        ],
                    ],
                    self::packageField(),
                ],
            ],
        ];
    }
}
