<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Widgets;

use ArtisanPackUI\Site\Services\Stats\DashboardStats;
use Modules\Users\Models\User;

/**
 * "Downloads trend" chart: daily downloads over a range, for every package
 * or one. The settings pick the package it opens on; the widget's own
 * selector switches packages in place.
 *
 * @since 0.5.0
 */
class DownloadsTrendWidget extends StatsWidget
{
    public const DEFAULT_RANGE = 30;

    /**
     * @return array{title: string, description: string, capability: string, source: string, default_options: array{package: string, range: string}}
     */
    public static function getWidgetInfo(): array
    {
        return [
            'title'           => 'Downloads trend',
            'description'     => 'Daily package downloads over time, with a package selector.',
            ...self::baseInfo(),
            'default_options' => [
                'package' => DashboardStats::ALL_PACKAGES,
                'range'   => (string) self::DEFAULT_RANGE,
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
        return self::stats()->downloadsTrend(
            self::packageId($options),
            self::intOption($options, 'range', self::DEFAULT_RANGE, 1, 365),
        );
    }

    /**
     * @return array{component: string, default_grid_config: array<string, array{rows: int, cols: int}>, settings_schema: array{fields: list<array<string, mixed>>}}
     */
    public static function extendedInfo(): array
    {
        return [
            'component'           => 'ArtisanPackUIDownloadsTrendWidget',
            'default_grid_config' => [
                'sm' => ['rows' => 2, 'cols' => 12],
                'md' => ['rows' => 2, 'cols' => 12],
                'lg' => ['rows' => 2, 'cols' => 8],
                'xl' => ['rows' => 2, 'cols' => 6],
            ],
            'settings_schema' => [
                'fields' => [
                    self::packageField(),
                    self::daysField('range', 'Range', DashboardStats::TREND_RANGES, self::DEFAULT_RANGE),
                ],
            ],
        ];
    }
}
