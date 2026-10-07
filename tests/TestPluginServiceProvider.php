<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Tests;

use ArtisanPackUI\Site\ArtisanPackUIServiceProvider;
use ArtisanPackUI\Site\Http\ArtisanPackUIRoutes;
use ArtisanPackUI\Site\Support\AdminPages;
use ArtisanPackUI\Site\Support\PluginBootstrapper;
use ArtisanPackUI\Site\Tests\Support\GatePermissionMiddleware;
use ArtisanPackUI\Site\Tests\Support\PassthroughMiddleware;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The Keystone-independent slices of {@see ArtisanPackUIServiceProvider},
 * reduced to what Testbench can boot.
 *
 * The real provider extends cms-framework's `PluginServiceProvider` and needs
 * the Keystone host for its nav, icons, blocks and federated remote. This
 * reproduces the rest through the same classes the provider calls:
 * {@see PluginBootstrapper}, {@see ArtisanPackUIRoutes}, and the
 * {@see AdminPages} definitions, routed the way Keystone's
 * `AdminPageManager` routes them (`web`, `auth`, `can:{capability}`). The
 * host-only `two-factor` aliases pass through and `permission` checks the
 * Gate, so every permission gate is exercised for real.
 */
class TestPluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        PluginBootstrapper::register($this->app);
    }

    public function boot(): void
    {
        PluginBootstrapper::boot($this->app);

        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('two-factor', PassthroughMiddleware::class);
        $router->aliasMiddleware('two-factor.enroll', PassthroughMiddleware::class);
        $router->aliasMiddleware('permission', GatePermissionMiddleware::class);

        ArtisanPackUIRoutes::register();

        Route::middleware(['web', 'auth'])->prefix('admin')->name('admin.')->group(function (): void {
            foreach (AdminPages::definitions() as $page) {
                Route::get($page['slug'], $page['action'])
                    ->name(str_replace('/', '.', $page['slug']))
                    ->middleware('can:' . $page['capability']);
            }
        });
    }
}
