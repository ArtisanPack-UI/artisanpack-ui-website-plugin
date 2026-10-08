<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Support;

use ArtisanPackUI\Icons\Registries\IconSetRegistration;
use ArtisanPackUI\Site\ArtisanPackUIServiceProvider;
use ArtisanPackUI\Site\Http\ArtisanPackUIRoutes;
use ArtisanPackUI\Site\Jobs\CollectPackageStats;
use ArtisanPackUI\Site\Jobs\SyncPackages;
use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\VisualEditor\Services\Icon\SvgSanitizer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Throwable;

/**
 * The host-independent parts of {@see ArtisanPackUIServiceProvider}: the
 * container bindings, the Gate ability, the shared Inertia prop, the
 * `apui` icon set and the daily scheduled jobs. The
 * provider and the test suite both call these, so the suite runs against
 * the same wiring the plugin ships with.
 *
 * @since 0.2.0
 */
final class PluginBootstrapper
{
    /**
     * Resolve {@see IntegrationSettings} to the saved row, fresh on every
     * resolution, so the API clients and connection checks autowire against
     * whatever the Settings page last saved.
     */
    public static function register(Application $app): void
    {
        $app->bind(IntegrationSettings::class, static fn (): IntegrationSettings => IntegrationSettings::current());

        $app->singleton(PackageIconSet::class, static fn (Application $app): PackageIconSet => new PackageIconSet(
            $app->make(SvgSanitizer::class),
            PackageIconSet::defaultDirectory(),
        ));
    }

    /**
     * Define {@see Permissions::ACCESS} and share the current user's plugin
     * abilities as the `artisanpackUi.can` Inertia prop, which the Edit
     * Package tabs, the icon picker and the Sync from docs button read
     * to gate themselves (the boot module can't read page props when it
     * registers them), together with the endpoints those call. Per-package
     * endpoints are templates holding
     * {@see ArtisanPackUIRoutes::PACKAGE_PLACEHOLDER} for the record id.
     */
    public static function boot(Application $app): void
    {
        $gate = $app->make(Gate::class);

        Permissions::defineGates($gate);

        Inertia::share('artisanpackUi', static fn (): array => [
            'can'       => Permissions::abilitiesFor($gate, $app->make('auth')->user()),
            'endpoints' => [
                'icons'        => route('artisanpack-ui.icons.index'),
                'syncImport'   => route('artisanpack-ui.sync.import'),
                'syncVersions' => route('artisanpack-ui.sync.versions'),
                'syncIcons'    => route('artisanpack-ui.sync.icons'),
                'package'      => self::packageEndpoints(),
            ],
        ]);

        self::registerIconSet($app);
        self::registerSchedule($app);
    }

    /**
     * @return array{syncStatus: string, syncNow: string, docsStatus: string, docsTree: string, importDocs: string, importChangelog: string, reorderDocs: string, stats: string}
     */
    private static function packageEndpoints(): array
    {
        $template = static fn (string $name): string => route($name, ['package' => ArtisanPackUIRoutes::PACKAGE_PLACEHOLDER]);

        return [
            'syncStatus'      => $template('artisanpack-ui.packages.sync.status'),
            'syncNow'         => $template('artisanpack-ui.packages.sync.now'),
            'docsStatus'      => $template('artisanpack-ui.packages.docs.status'),
            'docsTree'        => $template('artisanpack-ui.packages.docs.tree'),
            'importDocs'      => $template('artisanpack-ui.packages.docs.import-docs'),
            'importChangelog' => $template('artisanpack-ui.packages.docs.import-changelog'),
            'reorderDocs'     => $template('artisanpack-ui.packages.docs.reorder'),
            'stats'           => $template('artisanpack-ui.packages.stats'),
        ];
    }

    /**
     * Schedule the daily package sync and, after it, the stats snapshot.
     * Plugins boot late, during the host's own boot, so the scheduler may
     * already be resolved: then the jobs are added at once, otherwise when
     * it first resolves (the same as `ServiceProvider::callAfterResolving()`).
     */
    private static function registerSchedule(Application $app): void
    {
        $schedule = static function (Schedule $schedule): void {
            SyncPackages::schedule($schedule);
            CollectPackageStats::schedule($schedule);
        };

        $app->afterResolving(Schedule::class, $schedule);

        if ($app->resolved(Schedule::class)) {
            $schedule($app->make(Schedule::class));
        }
    }

    /**
     * Add the {@see PackageIconSet} to `ap.icons.registerIconSets`, which
     * the visual editor's `IconSvgResolver` reads lazily on its first
     * lookup, after every provider has booted. A failure is logged rather
     * than thrown, because it surfaces inside another package's filter
     * run and must not break icon rendering site-wide.
     */
    private static function registerIconSet(Application $app): void
    {
        if (! function_exists('addFilter') || ! class_exists(IconSetRegistration::class)) {
            return;
        }

        addFilter('ap.icons.registerIconSets', static function (IconSetRegistration $registry) use ($app): IconSetRegistration {
            try {
                return $app->make(PackageIconSet::class)->register($registry);
            } catch (Throwable $exception) {
                Log::warning('ArtisanPack UI plugin could not register the apui icon set.', [
                    'exception' => $exception->getMessage(),
                ]);

                return $registry;
            }
        });
    }
}
