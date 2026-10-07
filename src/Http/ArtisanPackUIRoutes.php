<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Http;

use ArtisanPackUI\Site\ArtisanPackUIServiceProvider;
use ArtisanPackUI\Site\Http\Controllers\IconController;
use ArtisanPackUI\Site\Http\Controllers\PackageSyncController;
use ArtisanPackUI\Site\Http\Controllers\SettingsController;
use ArtisanPackUI\Site\Support\Permissions;
use Illuminate\Support\Facades\Route;

/**
 * The plugin's JSON admin endpoints, in one place.
 *
 * {@see ArtisanPackUIServiceProvider} calls {@see self::register()}, and so
 * does the test suite, which aliases the host-only middleware to stand-ins
 * instead of redeclaring the routes. A route registered without its
 * permission gate therefore fails a test rather than shipping.
 *
 * Every route sits behind the host's admin stack plus a
 * `permission:{slug}` gate from {@see Permissions}. The admin *pages* are
 * routed by Keystone's `AdminMenuManager` instead (see
 * {@see \ArtisanPackUI\Site\Support\AdminPages}).
 *
 * @since 0.2.0
 */
final class ArtisanPackUIRoutes
{
    /**
     * The host's shared admin middleware stack.
     *
     * @var list<string>
     */
    public const ADMIN_MIDDLEWARE = [
        'web',
        'auth',
        'verified',
        'two-factor',
        'two-factor.enroll',
    ];

    /**
     * Requests per minute, per user, to the connection checks. Each one
     * calls out to the docs site or GitHub (and spends GitHub API quota).
     */
    public const CONNECTION_TEST_LIMIT = 10;

    /**
     * Requests per minute, per user, to the import and icon sync steps.
     * Each reads the docs site; one "Sync from docs" click spends two.
     */
    public const SYNC_LIMIT = 10;

    /**
     * Requests per minute, per user, to the version sync. A run is one
     * request per {@see \ArtisanPackUI\Site\Services\Sync\PackageVersionSync::BATCH_SIZE}
     * packages, each calling Packagist, npm or GitHub per package, so this
     * limit leaves room for a few hundred packages per minute.
     */
    public const VERSION_SYNC_LIMIT = 120;

    /**
     * Requests per minute, per user, to the icon catalog, which the picker
     * calls as the admin types.
     */
    public const ICON_CATALOG_LIMIT = 120;

    /**
     * @param  list<string>  $adminMiddleware
     */
    public static function register(array $adminMiddleware = self::ADMIN_MIDDLEWARE): void
    {
        Route::middleware($adminMiddleware)
            ->prefix('admin/artisanpack-ui')
            ->name('artisanpack-ui.')
            ->group(function (): void {
                Route::middleware('permission:' . Permissions::SYNC)->group(function (): void {
                    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');

                    Route::middleware('throttle:' . self::CONNECTION_TEST_LIMIT . ',1')->group(function (): void {
                        Route::post('settings/test/docs', [SettingsController::class, 'testDocs'])->name('settings.test-docs');
                        Route::post('settings/test/github', [SettingsController::class, 'testGitHub'])->name('settings.test-github');
                    });

                    Route::prefix('sync')->name('sync.')->group(function (): void {
                        Route::middleware('throttle:' . self::SYNC_LIMIT . ',1')->group(function (): void {
                            Route::post('import', [PackageSyncController::class, 'import'])->name('import');
                            Route::post('icons', [PackageSyncController::class, 'icons'])->name('icons');
                        });

                        Route::post('versions', [PackageSyncController::class, 'versions'])
                            ->middleware('throttle:' . self::VERSION_SYNC_LIMIT . ',1')
                            ->name('versions');
                    });
                });

                // The icon picker on Edit Package. Icons are public data, so
                // any plugin permission will do.
                Route::get('icons', [IconController::class, 'index'])
                    ->middleware(['permission:' . Permissions::ACCESS, 'throttle:' . self::ICON_CATALOG_LIMIT . ',1'])
                    ->name('icons.index');
            });
    }
}
