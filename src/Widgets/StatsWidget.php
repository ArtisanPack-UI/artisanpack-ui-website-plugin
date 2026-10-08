<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Widgets;

use ArtisanPackUI\Site\ArtisanPackUIServiceProvider;
use ArtisanPackUI\Site\Services\Stats\DashboardStats;
use ArtisanPackUI\Site\Support\Permissions;
use Modules\SiteEditor\Widgets\Contracts\KeystoneAdminWidgetInterface;

/**
 * Base for the dashboard stats widgets (roadmap 4.3).
 *
 * Each widget is a thin adapter over {@see DashboardStats}, which holds the
 * payloads so they stay testable under bare Testbench: these classes
 * implement a host interface (and take the host `User`), so they only
 * autoload inside a running Keystone install. The provider registers them
 * behind an `interface_exists()` guard
 * ({@see ArtisanPackUIServiceProvider::registerDashboardWidgets()}).
 *
 * Every widget needs {@see Permissions::STATS_VIEW}, and is filed under the
 * plugin's name in the dashboard's "Add widget" drawer. Its body is a
 * component from the federated bundle, registered from `./boot` under the
 * widget's `extendedInfo()['component']` key.
 *
 * @since 0.5.0
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

    protected static function stats(): DashboardStats
    {
        return app(DashboardStats::class);
    }

    /**
     * The package an instance's `package` option names, or null for every
     * package.
     *
     * @param  array<string, mixed>  $options
     */
    protected static function packageId(array $options): ?int
    {
        $value = $options['package'] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * An integer option, clamped to `[$min, $max]`.
     *
     * @param  array<string, mixed>  $options
     */
    protected static function intOption(array $options, string $name, int $default, int $min, int $max): int
    {
        $value = $options[$name] ?? null;

        return is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
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
