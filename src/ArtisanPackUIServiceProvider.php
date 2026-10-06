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
use ArtisanPackUI\CMSFramework\Modules\ContentTypes\Managers\ContentTypeManager;
use ArtisanPackUI\CMSFramework\Modules\Plugins\Support\PluginServiceProvider;
use ArtisanPackUI\Site\Blocks\CopyCommandBlock;
use ArtisanPackUI\Site\Blocks\TerminalBlock;
use ArtisanPackUI\Site\Database\Seeders\PackageContentTypeSeeder;
use ArtisanPackUI\Site\Http\Controllers\PluginAssetController;
use ArtisanPackUI\VisualEditor\Facades\VisualEditor;
use BladeUI\Icons\Factory as IconFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class ArtisanPackUIServiceProvider extends PluginServiceProvider
{
    /**
     * The plugin slug, which is also the federated remote's name and the
     * `plugins/{slug}/{page}` Inertia page prefix the host resolves.
     */
    public const SLUG = 'artisanpack-ui';

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
        // Bind plugin services on the container as needed.
    }

    public function boot(): void
    {
        $this->registerViewNamespace();
        $this->registerIconSet();
        $this->registerFederatedRemote();
        $this->registerAssetRoute();
        $this->registerAdminSurfaces();
        $this->registerFieldTypes();
        $this->registerEditPanels();
        $this->registerContentTypes();
        $this->registerBlocks();
        $this->registerHookSubscriptions();
    }

    /**
     * Idempotently provision the `package` content type so the CPT survives DB
     * resets without needing a manual `db:seed`. The guard is cheap — a single
     * pluck on `content_types` — and short-circuits before the seeder runs.
     * Any failure (missing table during install, migration mid-flight) is
     * swallowed so a half-installed DB can't 500 the whole app on boot.
     */
    protected function registerContentTypes(): void
    {
        try {
            if (! Schema::hasTable('content_types')) {
                return;
            }

            if (DB::table('content_types')->where('slug', 'package')->exists()) {
                return;
            }

            $this->app->make(PackageContentTypeSeeder::class)
                ->run($this->app->make(ContentTypeManager::class));
        } catch (Throwable) {
            // Boot must never fail on best-effort provisioning.
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
     * Register the plugin's admin pages. Each renders a page component from
     * the federated bundle (see {@see registerFederatedRemote()}).
     *
     * Registered directly on AdminMenuManager rather than
     * PluginServiceProvider::registerAdminPage() because the helper's `view`
     * key produces an invalid route action upstream
     * (ArtisanPack-UI/cms-framework#246).
     */
    protected function registerAdminSurfaces(): void
    {
        $menu = $this->app->make(AdminMenuManager::class);

        $menu->addPage(
            __('ArtisanPack UI'),
            self::SLUG,
            'tools',
            [
                'action'     => static fn (): Response => self::renderPage('packages-board'),
                'capability' => 'access_admin_dashboard',
                'icon'       => self::MENU_ICON,
                'order'      => 60,
            ],
        );

        $menu->addSubPage(
            __('Settings'),
            self::SLUG . '/settings',
            self::SLUG,
            [
                'action'     => static fn (): Response => self::renderPage('settings'),
                'capability' => 'access_admin_dashboard',
                'order'      => 10,
            ],
        );

        $this->registerNavEntry([
            'slug'       => self::SLUG,
            'label'      => __('ArtisanPack UI'),
            'url'        => '/admin/' . self::SLUG,
            'icon'       => self::MENU_ICON,
            'permission' => 'access_admin_dashboard',
            'order'      => 60,
        ]);
    }

    /**
     * Render one of the federated admin pages with the props every page
     * shares.
     */
    protected static function renderPage(string $page): Response
    {
        return Inertia::render('plugins/' . self::SLUG . '/' . $page, [
            'nav' => [
                'board'    => url('/admin/' . self::SLUG),
                'settings' => url('/admin/' . self::SLUG . '/settings'),
            ],
        ]);
    }

    /**
     * Register any custom field types the site needs. Left as an anchor for
     * future additions — call `apRegisterFieldType()` for each one.
     */
    protected function registerFieldTypes(): void
    {
        // apRegisterFieldType( 'some_field', [ ... ] );
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
