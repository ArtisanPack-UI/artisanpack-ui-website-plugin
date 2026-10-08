<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Widgets;

use Modules\Users\Models\User;

/**
 * "Release feed": the latest version of each package and when it was
 * released, newest first.
 *
 * @since 1.0.0
 */
class ReleaseFeedWidget extends StatsWidget
{
    public const DEFAULT_LIMIT = 8;

    public const MAX_LIMIT = 30;

    /**
     * @return array{title: string, description: string, capability: string, source: string, default_options: array{limit: int}}
     */
    public static function getWidgetInfo(): array
    {
        return [
            'title'           => 'Release feed',
            'description'     => 'The latest version of each package and when it was released.',
            ...self::baseInfo(),
            'default_options' => ['limit' => self::DEFAULT_LIMIT],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @return array<string, mixed>
     */
    public static function getData(User $user, array $options): array
    {
        return self::stats()->releaseFeed(self::intOption($options, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT));
    }

    /**
     * @return array{component: string, default_grid_config: array<string, array{rows: int, cols: int}>, settings_schema: array{fields: list<array<string, mixed>>}}
     */
    public static function extendedInfo(): array
    {
        return [
            'component'           => 'ArtisanPackUIReleaseFeedWidget',
            'default_grid_config' => [
                'sm' => ['rows' => 3, 'cols' => 12],
                'md' => ['rows' => 3, 'cols' => 6],
                'lg' => ['rows' => 3, 'cols' => 4],
                'xl' => ['rows' => 3, 'cols' => 3],
            ],
            'settings_schema' => [
                'fields' => [
                    [
                        'name'    => 'limit',
                        'label'   => 'Number of releases',
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
