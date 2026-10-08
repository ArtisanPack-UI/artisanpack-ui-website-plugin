<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Support;

use ArtisanPackUI\Icons\Registries\IconSetRegistration;
use ArtisanPackUI\Site\ArtisanPackUIServiceProvider;
use ArtisanPackUI\Site\Http\ArtisanPackUIRoutes;
use ArtisanPackUI\Site\Jobs\CollectPackageStats;
use ArtisanPackUI\Site\Jobs\SyncPackages;
use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Services\GitHub\GitHubAppClient;
use ArtisanPackUI\VisualEditor\Services\Icon\SvgSanitizer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Throwable;

/**
 * The host-independent parts of {@see ArtisanPackUIServiceProvider}: the
 * container bindings, the Gate ability, the shared Inertia prop, the
 * `apui` icon set, the daily scheduled jobs and the clean-up when the
 * plugin is deleted. The
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
     * {@see ArtisanPackUIRoutes::PACKAGE_PLACEHOLDER} for the record id, and
     * the board and issue endpoints hold the item, repo and issue
     * placeholders.
     *
     * The prop is only shared on admin pages, with users who pass
     * {@see Permissions::ACCESS}; everyone else, guests included, gets an
     * empty array, which the bundle treats as "nothing allowed".
     */
    public static function boot(Application $app): void
    {
        $gate = $app->make(Gate::class);

        Permissions::defineGates($gate);

        Inertia::share('artisanpackUi', static fn (): array => self::sharedProps($app, $gate));

        self::registerIconSet($app);
        self::registerSchedule($app);
        self::registerDeletePurge();
    }

    /**
     * Remove what the plugin keeps outside its own tables when Keystone
     * deletes it: the stored credentials (encrypted, but still the docs API
     * token and the App private key), the cached installation token and
     * the mirrored `apui` icons.
     *
     * The framework's migration rollback is meant to drop the plugin's
     * tables, but `migrate:rollback --path` only reverts the last global
     * batch, so the settings row is cleared here rather than trusting it.
     * The `package` content type, its records and its custom fields stay:
     * they are site content, not plugin state.
     *
     * Runs from `ap.cmsFramework.plugin.deleting`, which only fires in a
     * request where the plugin booted, i.e. when it is deleted while active.
     *
     * @since 1.0.0
     */
    public static function purgeOnDelete(string $slug): void
    {
        if (AdminPages::SLUG !== $slug || ! Schema::hasTable('artisanpack_ui_settings')) {
            return;
        }

        app(GitHubAppClient::class)->forgetInstallationToken();
        IntegrationSettings::query()->delete();
        File::deleteDirectory(PackageIconSet::defaultDirectory());
    }

    private static function registerDeletePurge(): void
    {
        if (! function_exists('addAction')) {
            return;
        }

        addAction('ap.cmsFramework.plugin.deleting', self::purgeOnDelete(...));
    }

    /**
     * The `artisanpackUi` prop for the current request.
     *
     * A route name that doesn't resolve (a stale cached route table) is
     * logged and the prop left empty, so it can never take every admin page
     * down with it.
     *
     * @return array{can?: array<string, bool>, endpoints?: array<string, mixed>}
     *
     * @since 1.0.0
     */
    public static function sharedProps(Application $app, Gate $gate): array
    {
        $request = $app->make('request');
        $user    = $app->make('auth')->user();

        if (! $request->is('admin', 'admin/*') || null === $user || ! $gate->forUser($user)->allows(Permissions::ACCESS)) {
            return [];
        }

        try {
            return [
                'can'       => Permissions::abilitiesFor($gate, $user),
                'endpoints' => [
                    'icons'        => route('artisanpack-ui.icons.index'),
                    'syncImport'   => route('artisanpack-ui.sync.import'),
                    'syncVersions' => route('artisanpack-ui.sync.versions'),
                    'syncIcons'    => route('artisanpack-ui.sync.icons'),
                    'packagesList' => Route::has('admin.content.index') ? route('admin.content.index', ['contentType' => 'package']) : null,
                    'package'      => self::packageEndpoints(),
                    'board'        => self::boardEndpoints(),
                ],
            ];
        } catch (RouteNotFoundException $exception) {
            Log::warning('ArtisanPack UI plugin could not build its admin endpoints; clear the route cache with `php artisan optimize`.', [
                'exception' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array{syncStatus: string, syncNow: string, docsStatus: string, docsTree: string, importDocs: string, importChangelog: string, reorderDocs: string, stats: string, board: string}
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
            'board'           => $template('artisanpack-ui.packages.board'),
        ];
    }

    /**
     * The global board, and the templates a card's move and issue modal
     * fill in.
     *
     * @return array{index: string, move: string, issue: string, comments: string, options: string}
     */
    private static function boardEndpoints(): array
    {
        $issue = [
            'repo'   => ArtisanPackUIRoutes::REPO_PLACEHOLDER,
            'number' => ArtisanPackUIRoutes::ISSUE_PLACEHOLDER,
        ];

        return [
            'index'    => route('artisanpack-ui.board'),
            'move'     => route('artisanpack-ui.board.move', ['item' => ArtisanPackUIRoutes::ITEM_PLACEHOLDER]),
            'issue'    => route('artisanpack-ui.issues.show', $issue),
            'comments' => route('artisanpack-ui.issues.comments.store', $issue),
            'options'  => route('artisanpack-ui.issues.options', ['repo' => ArtisanPackUIRoutes::REPO_PLACEHOLDER]),
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
