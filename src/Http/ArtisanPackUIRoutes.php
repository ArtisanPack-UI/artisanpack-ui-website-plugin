<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Http;

use ArtisanPackUI\Site\ArtisanPackUIServiceProvider;
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
                });
            });
    }
}
