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
use ArtisanPackUI\CMSFramework\Modules\Plugins\Support\PluginServiceProvider;
use ArtisanPackUI\Site\Blocks\CopyCommandBlock;
use ArtisanPackUI\Site\Blocks\TerminalBlock;
use ArtisanPackUI\VisualEditor\Facades\VisualEditor;
use Inertia\Inertia;

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
        $this->registerBlocks();
        $this->registerHookSubscriptions();
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
        VisualEditor::registerServerBlock(
            TerminalBlock::NAME,
            $terminal->metadata(),
            $terminal->render(...),
        );

        $copyCommand = $this->app->make(CopyCommandBlock::class);
        VisualEditor::registerServerBlock(
            CopyCommandBlock::NAME,
            $copyCommand->metadata(),
            $copyCommand->render(...),
        );
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
