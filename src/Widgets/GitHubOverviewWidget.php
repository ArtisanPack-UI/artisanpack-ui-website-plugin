<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Widgets;

use Modules\Users\Models\User;

/**
 * "GitHub overview": stars, open issues and open PRs for each package.
 *
 * @since 0.5.0
 */
class GitHubOverviewWidget extends StatsWidget
{
    /**
     * @return array{title: string, description: string, capability: string, source: string, default_options: array<string, mixed>}
     */
    public static function getWidgetInfo(): array
    {
        return [
            'title'           => 'GitHub overview',
            'description'     => 'Stars, open issues and open PRs for each package.',
            ...self::baseInfo(),
            'default_options' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @return array<string, mixed>
     */
    public static function getData(User $user, array $options): array
    {
        return self::stats()->gitHubOverview();
    }

    /**
     * @return array{component: string, default_grid_config: array<string, array{rows: int, cols: int}>}
     */
    public static function extendedInfo(): array
    {
        return [
            'component'           => 'ArtisanPackUIGitHubOverviewWidget',
            'default_grid_config' => [
                'sm' => ['rows' => 3, 'cols' => 12],
                'md' => ['rows' => 3, 'cols' => 6],
                'lg' => ['rows' => 3, 'cols' => 6],
                'xl' => ['rows' => 3, 'cols' => 4],
            ],
        ];
    }
}
