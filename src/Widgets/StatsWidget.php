<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Widgets;

use ArtisanPackUI\Site\ArtisanPackUIServiceProvider;
use ArtisanPackUI\Site\Services\Dashboard\DashboardWidgetData;
use ArtisanPackUI\Site\Services\Stats\DashboardStats;
use ArtisanPackUI\Site\Support\Permissions;
use Modules\SiteEditor\Widgets\Contracts\KeystoneAdminWidgetInterface;

/**
 * Base for the dashboard stats widgets (roadmap 4.3).
 *
 * Each widget's `getData()` returns only the URL of its payload, which the
 * bundle fetches once the dashboard has rendered. The payloads themselves
 * are built by {@see DashboardWidgetData}, so they stay testable under bare
 * Testbench: these classes implement a host interface (and take the host
 * `User`), so they only autoload inside a running Keystone install. The provider registers them
 * behind an `interface_exists()` guard
 * ({@see ArtisanPackUIServiceProvider::registerDashboardWidgets()}).
 *
 * Every widget needs {@see Permissions::STATS_VIEW}, and is filed under the
 * plugin's name in the dashboard's "Add widget" drawer. Its body is a
 * component from the federated bundle, registered from `./boot` under the
 * widget's `extendedInfo()['component']` key.
 *
 * @since 1.0.0
 */
abstract class StatsWidget implements KeystoneAdminWidgetInterface
{
    /**
     * @return array{capability: string, source: string}
     */
    protected static function baseInfo(): array
    {
        return [
            'capability' => Permissions::STATS_VIEW,
            'source'     => ArtisanPackUIServiceProvider::SLUG,
        ];
    }

    /**
     * The `package` select, listing every package.
     *
     * @return array{name: string, label: string, type: string, default: string, options: list<array{value: string, label: string}>}
     */
    protected static function packageField(): array
    {
        return [
            'name'    => 'package',
            'label'   => 'Package',
            'type'    => 'select',
            'default' => DashboardStats::ALL_PACKAGES,
            'options' => DashboardStats::packageOptions(),
        ];
    }

    /**
     * A select over day counts.
     *
     * @param  list<int>  $days
     *
     * @return array{name: string, label: string, type: string, default: string, options: list<array{value: string, label: string}>}
     */
    protected static function daysField(string $name, string $label, array $days, int $default): array
    {
        return [
            'name'    => $name,
            'label'   => $label,
            'type'    => 'select',
            'default' => (string) $default,
            'options' => array_map(static fn (int $count): array => [
                'value' => (string) $count,
                'label' => 365 === $count ? 'Last year' : 'Last ' . $count . ' days',
            ], $days),
        ];
    }
}
