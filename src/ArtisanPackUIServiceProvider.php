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
use ArtisanPackUI\VisualEditor\Facades\VisualEditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Throwable;

final class ArtisanPackUIServiceProvider extends PluginServiceProvider
{
    public function register(): void
    {
        // Bind plugin services on the container as needed.
    }

    public function boot(): void
    {
        $this->registerViewNamespace();
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

    protected function registerAdminSurfaces(): void
    {
        // Keystone renders admin pages via Inertia + React, so the admin page
        // action returns an Inertia response pointing at the page component in
        // resources/js/pages/admin/artisanpack-ui/. Registered directly on
        // AdminMenuManager rather than PluginServiceProvider::registerAdminPage()
        // because the helper's `view` key produces an invalid route action
        // upstream (ArtisanPack-UI/cms-framework#246).
        $this->app->make(AdminMenuManager::class)->addPage(
            __('ArtisanPack UI'),
            'artisanpack-ui',
            'tools',
            [
                'action'     => static fn () => Inertia::render('admin/artisanpack-ui/Index'),
                'capability' => 'access_admin_dashboard',
                'icon'       => 'fas.layer-group',
                'order'      => 60,
            ],
        );

        $this->registerNavEntry([
            'slug'       => 'artisanpack-ui',
            'label'      => __('ArtisanPack UI'),
            'url'        => '/admin/artisanpack-ui',
            'icon'       => 'fas.layer-group',
            'permission' => 'access_admin_dashboard',
            'order'      => 60,
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
