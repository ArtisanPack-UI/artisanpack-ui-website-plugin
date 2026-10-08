<?php

declare(strict_types=1);

/**
 * ArtisanPack UI Site Plugin Service Provider.
 *
 * Site-specific plugin for artisanpack-ui.dev. Registers admin surfaces, nav
 * entries, custom field types, block types, and hook subscriptions that are
 * unique to the marketing site and don't belong in a shared package.
 *
 * @since 0.1.0
 */

namespace ArtisanPackUI\Site;

use ArtisanPackUI\CMSFramework\Modules\Admin\Managers\AdminMenuManager;
use ArtisanPackUI\CMSFramework\Modules\AdminWidgets\Services\AdminWidgetManager;
use ArtisanPackUI\CMSFramework\Modules\ContentTypes\Managers\ContentTypeManager;
use ArtisanPackUI\CMSFramework\Modules\Plugins\Support\PluginServiceProvider;
use ArtisanPackUI\Site\Blocks\CopyCommandBlock;
use ArtisanPackUI\Site\Blocks\TerminalBlock;
use ArtisanPackUI\Site\Database\Seeders\PackageContentTypeSeeder;
use ArtisanPackUI\Site\Http\ArtisanPackUIRoutes;
use ArtisanPackUI\Site\Http\Controllers\PluginAssetController;
use ArtisanPackUI\Site\Support\AdminPages;
use ArtisanPackUI\Site\Support\IconPickerField;
use ArtisanPackUI\Site\Support\PackageFieldProvisioner;
use ArtisanPackUI\Site\Support\Permissions;
use ArtisanPackUI\Site\Support\PluginBootstrapper;
use ArtisanPackUI\Site\Widgets\BoardWidget;
use ArtisanPackUI\Site\Widgets\DownloadsKpiWidget;
use ArtisanPackUI\Site\Widgets\DownloadsTrendWidget;
use ArtisanPackUI\Site\Widgets\GitHubOverviewWidget;
use ArtisanPackUI\Site\Widgets\ReleaseFeedWidget;
use ArtisanPackUI\Site\Widgets\TopPackagesWidget;
use ArtisanPackUI\VisualEditor\Facades\VisualEditor;
use BladeUI\Icons\Factory as IconFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Modules\SiteEditor\Widgets\Contracts\KeystoneAdminWidgetInterface;
use Throwable;

final class ArtisanPackUIServiceProvider extends PluginServiceProvider
{
    /**
     * The plugin slug, which is also the federated remote's name and the
     * `plugins/{slug}/{page}` Inertia page prefix the host resolves.
     */
    public const SLUG = AdminPages::SLUG;

    /**
     * Prefix of the plugin's own icon set (`resources/icons/`). Kept free of
     * hyphens because Keystone's admin menu resolver splits icon ids on the
     * first `-` to find the set.
     */
    public const ICON_SET_PREFIX = 'artisanpackui';

    /**
     * The admin sidebar icon: the ArtisanPack UI mark from the plugin's set.
     */
    public const MENU_ICON = self::ICON_SET_PREFIX . '-logo';

    public function register(): void
    {
        PluginBootstrapper::register($this->app);
    }

    public function boot(): void
    {
        PluginBootstrapper::boot($this->app);

        $this->registerViewNamespace();
        $this->registerIconSet();
        $this->registerFederatedRemote();
        $this->registerAssetRoute();
        $this->registerAdminSurfaces();
        $this->registerRoutes();
        $this->registerFieldTypes();
        $this->registerEditPanels();
        $this->registerDashboardWidgets();
        $this->registerContentTypes();
        $this->registerBlocks();
        $this->registerHookSubscriptions();
    }

    /**
     * Idempotently provision the `package` content type and its custom
     * fields, so the CPT survives DB resets without needing a manual
     * `db:seed`, and a changed field type reaches existing installs. The
     * guard is cheap — one query for the package's `custom_fields` rows,
     * which only exist once the type does — and
     * short-circuits before the seeder runs. Any failure (missing table
     * during install, migration mid-flight, a field key clash) is swallowed
     * so a half-installed DB can't 500 the whole app on boot, and logged at
     * most once an hour so a persistent failure doesn't flood the log.
     */
    protected function registerContentTypes(): void
    {
        try {
            if (! Schema::hasTable('content_types') || ! Schema::hasTable('custom_fields')) {
                return;
            }

            $fields = $this->app->make(PackageFieldProvisioner::class);

            if (! $fields->isOutdated()) {
                return;
            }

            $this->app->make(PackageContentTypeSeeder::class)
                ->run($this->app->make(ContentTypeManager::class), $fields);
        } catch (Throwable $exception) {
            // Boot must never fail on best-effort provisioning.
            if (! Cache::add('artisanpack-ui:provisioning-failure-logged', true, 3600)) {
                return;
            }

            Log::warning('ArtisanPack UI plugin could not provision the package content type.', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Bind the `site::` view namespace to the plugin's resources/views/ dir so
     * block render callbacks can address partials as `site::blocks.terminal`.
     */
    protected function registerViewNamespace(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'site');
    }

    /**
     * Register the plugin's icon set so the admin sidebar can render the
     * ArtisanPack UI mark as `artisanpackui-logo`.
     *
     * Added to the blade-icons Factory directly rather than through
     * `ap.icons.registerIconSets`: that filter runs once, when the Factory is
     * first resolved, which can happen before plugins boot. `callAfterResolving`
     * fires immediately if the Factory already exists and on first resolve
     * otherwise, so the set registers either way. Registration failures are
     * logged rather than thrown, since they surface inside a resolve callback.
     */
    protected function registerIconSet(): void
    {
        $this->callAfterResolving(IconFactory::class, function (IconFactory $factory): void {
            try {
                $factory->add(self::ICON_SET_PREFIX, [
                    'path'   => dirname(__DIR__) . '/resources/icons',
                    'prefix' => self::ICON_SET_PREFIX,
                ]);
            } catch (Throwable $exception) {
                // A colliding prefix or missing directory must not take the
                // app down; the sidebar falls back to its default glyph.
                Log::warning('ArtisanPack UI plugin could not register its icon set.', [
                    'exception' => $exception->getMessage(),
                ]);
            }
        });
    }

    /**
     * Register the admin React bundle as a Module Federation remote, so the
     * host resolves `plugins/artisanpack-ui/{page}` Inertia pages to the
     * bundle's `./{page}` exposes and preloads `./boot` before the admin
     * shell mounts.
     *
     * Registered imperatively because `plugin.json`'s `federated_module`
     * entry is a filesystem path, which the host's `PluginUpdateUrlGuard`
     * drops: the browser needs the site's absolute (https) URL to the public
     * asset route. An imperative registration wins over the manifest bridge.
     * Versioned so a browser holding the previous release's entry fetches
     * the new one the moment the plugin is updated.
     *
     * `exposes` lists only page modules. `./boot` is side-effect only and the
     * Edit Package tab modules are mounted by it in-process, so neither may
     * become an Inertia page name.
     */
    protected function registerFederatedRemote(): void
    {
        $this->pluginRegistry()->setFederatedModule(self::SLUG, [
            'entry'      => url('/plugins/' . self::SLUG . '/assets/remoteEntry.js') . '?v=' . urlencode(self::pluginVersion()),
            'exposes'    => ['./packages-board', './settings'],
            'bootModule' => './boot',
        ]);
    }

    /**
     * The version in `plugin.json`, or `dev` when it cannot be read.
     */
    public static function pluginVersion(): string
    {
        $manifest = json_decode((string) @file_get_contents(dirname(__DIR__) . '/plugin.json'), true);

        return is_array($manifest) && is_string($manifest['version'] ?? null) ? $manifest['version'] : 'dev';
    }

    /**
     * Serve the built federated bundle from `dist/assets/`. Public and outside
     * the admin middleware stack: the browser fetches `remoteEntry.js` before
     * the admin shell has a page to gate, and the bundle carries no secrets.
     */
    protected function registerAssetRoute(): void
    {
        Route::get('/plugins/' . self::SLUG . '/assets/{path}', PluginAssetController::class)
            ->where('path', '.*')
            ->name('plugins.' . self::SLUG . '.assets');
    }

    /**
     * Register the plugin's admin pages from {@see AdminPages}. Each renders
     * a page component from the federated bundle (see
     * {@see registerFederatedRemote()}) behind its `can:` capability, and the
     * nav entry shows to anyone holding a plugin permission.
     *
     * Registered directly on AdminMenuManager rather than
     * PluginServiceProvider::registerAdminPage() because the helper's `view`
     * key produces an invalid route action upstream
     * (ArtisanPack-UI/cms-framework#246).
     */
    protected function registerAdminSurfaces(): void
    {
        $menu = $this->app->make(AdminMenuManager::class);

        foreach (AdminPages::definitions() as $page) {
            $options = [
                'action'     => $page['action'],
                'capability' => $page['capability'],
                'order'      => $page['order'],
            ];

            if (null === $page['parent']) {
                $menu->addPage($page['title'], $page['slug'], 'tools', [...$options, 'icon' => self::MENU_ICON]);
            } else {
                $menu->addSubPage($page['title'], $page['slug'], $page['parent'], $options);
            }
        }

        $this->registerNavEntry([
            'slug'       => self::SLUG,
            'label'      => __('ArtisanPack UI'),
            'url'        => '/admin/' . self::SLUG,
            'icon'       => self::MENU_ICON,
            'permission' => Permissions::ACCESS,
            'order'      => 60,
        ]);
    }

    /**
     * Register the plugin's JSON admin endpoints through
     * {@see ArtisanPackUIRoutes}, which the test suite registers too.
     *
     * Plugins boot after the framework's `booted()` callback has refreshed
     * the router's name and action lookups, so they are refreshed again
     * here; without it `route('artisanpack-ui.*')` returns nothing.
     */
    protected function registerRoutes(): void
    {
        ArtisanPackUIRoutes::register();

        $routes = $this->app['router']->getRoutes();
        $routes->refreshNameLookups();
        $routes->refreshActionLookups();
    }

    /**
     * Register the plugin's custom field types: the {@see IconPickerField}
     * the package `icon` field uses. Registering it is what lets Keystone's
     * custom-field admin accept the type.
     */
    protected function registerFieldTypes(): void
    {
        apRegisterFieldType(IconPickerField::TYPE, IconPickerField::definition());
    }

    /**
     * Register any content-edit sidebar panels the site needs. Left as an
     * anchor for future additions — push into `ap.admin.contentEdit.panels`.
     */
    protected function registerEditPanels(): void
    {
        // addFilter( 'ap.admin.contentEdit.panels', function ( array $panels ): array {
        //     $panels[] = [ ... ];
        //     return $panels;
        // } );
    }

    /**
     * Register the dashboard stats widgets (roadmap 4.3) and the board
     * widget (5.5) with the host's {@see AdminWidgetManager}. Each widget's
     * body is a component from the federated bundle, registered from `./boot` through
     * `keystone.admin.dashboard.widget.registerFederated` under the
     * widget's `extendedInfo()['component']` key; the two must match.
     *
     * Guarded on the host's widget interface, as Content Organizer does:
     * the widget classes implement it, so registering them where it doesn't
     * exist would fatal on autoload.
     */
    protected function registerDashboardWidgets(): void
    {
        if (! interface_exists(KeystoneAdminWidgetInterface::class)) {
            return;
        }

        $widgets = $this->app->make(AdminWidgetManager::class);

        $widgets->register(self::SLUG . '.downloadsKpi', DownloadsKpiWidget::class);
        $widgets->register(self::SLUG . '.downloadsTrend', DownloadsTrendWidget::class);
        $widgets->register(self::SLUG . '.gitHubOverview', GitHubOverviewWidget::class);
        $widgets->register(self::SLUG . '.topPackages', TopPackagesWidget::class);
        $widgets->register(self::SLUG . '.releaseFeed', ReleaseFeedWidget::class);
        $widgets->register(self::SLUG . '.board', BoardWidget::class);
    }

    /**
     * Register the site-only Gutenberg blocks.
     *
     * These are dynamic, server-rendered blocks — the editor synthesizes an
     * `edit` component from each attribute schema (SSR preview + inspector
     * controls) so no client bundle rebuild is needed.
     */
    protected function registerBlocks(): void
    {
        $terminal = $this->app->make(TerminalBlock::class);
        VisualEditor::registerBlockType(TerminalBlock::NAME, $terminal->metadata());
        VisualEditor::registerDynamicBlock(TerminalBlock::NAME, [
            'render' => $terminal->render(...),
        ]);

        $copyCommand = $this->app->make(CopyCommandBlock::class);
        VisualEditor::registerBlockType(CopyCommandBlock::NAME, $copyCommand->metadata());
        VisualEditor::registerDynamicBlock(CopyCommandBlock::NAME, [
            'render' => $copyCommand->render(...),
        ]);
    }

    /**
     * Subscribe to framework hooks. Left as an anchor for future additions —
     * call `addAction()` / `addFilter()` for each subscription.
     */
    protected function registerHookSubscriptions(): void
    {
        // addAction( 'ap.contentTypes.created', static function ( $contentType ): void { ... } );
    }
}
