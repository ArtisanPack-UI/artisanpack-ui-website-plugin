<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Http;

use ArtisanPackUI\Site\ArtisanPackUIServiceProvider;
use ArtisanPackUI\Site\Http\Controllers\BoardController;
use ArtisanPackUI\Site\Http\Controllers\IconController;
use ArtisanPackUI\Site\Http\Controllers\IssueController;
use ArtisanPackUI\Site\Http\Controllers\PackageDocsController;
use ArtisanPackUI\Site\Http\Controllers\PackageStatsController;
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
     * Requests per minute, per user, to the Docs tab's reads and reorder
     * saves. The tab polls the import status every few seconds while an
     * import is queued, and each reorder is a save.
     */
    public const DOCS_LIMIT = 60;

    /**
     * Requests per minute, per user, to the Stats tab. An uncached load
     * calls the registry and GitHub.
     */
    public const STATS_LIMIT = 30;

    /**
     * Requests per minute, per user, to the boards and the issue modal.
     * Each is a live GitHub call (a board load pages through the whole
     * project), and a busy triage session moves and opens many cards.
     */
    public const BOARD_LIMIT = 60;

    /**
     * The `{package}` placeholder in the per-package endpoint templates
     * shared with the Edit Package screen, which the bundle swaps for the
     * record's id.
     */
    public const PACKAGE_PLACEHOLDER = '__package__';

    /**
     * The `{item}`, `{repo}` and `{number}` placeholders in the shared board
     * and issue endpoint templates, which the bundle swaps for a card's
     * project item id, repo name and issue number.
     */
    public const ITEM_PLACEHOLDER = '__item__';

    public const REPO_PLACEHOLDER = '__repo__';

    public const ISSUE_PLACEHOLDER = '__issue__';

    /**
     * An issue number. Bounded so an over-long one is a 404 rather than an
     * integer overflow in the controller.
     */
    public const ISSUE_NUMBER_PATTERN = '[1-9][0-9]{0,9}';

    /**
     * Register the routes. Every route is named *before* its verb
     * (`Route::name('x')->get(...)`): under a cached route table the router
     * is a `CompiledRouteCollection`, whose `refreshNameLookups()` does
     * nothing, so a name attached after the verb is never indexed and
     * `route('artisanpack-ui.*')` throws.
     *
     * @param  list<string>  $adminMiddleware
     */
    public static function register(array $adminMiddleware = self::ADMIN_MIDDLEWARE): void
    {
        Route::middleware($adminMiddleware)
            ->prefix('admin/artisanpack-ui')
            ->name('artisanpack-ui.')
            ->group(function (): void {
                Route::middleware('permission:' . Permissions::SYNC)->group(function (): void {
                    Route::name('settings.update')->put('settings', [SettingsController::class, 'update']);

                    Route::middleware('throttle:' . self::CONNECTION_TEST_LIMIT . ',1')->group(function (): void {
                        Route::name('settings.test-docs')->post('settings/test/docs', [SettingsController::class, 'testDocs']);
                        Route::name('settings.test-github')->post('settings/test/github', [SettingsController::class, 'testGitHub']);
                    });

                    Route::prefix('sync')->name('sync.')->group(function (): void {
                        Route::middleware('throttle:' . self::SYNC_LIMIT . ',1')->group(function (): void {
                            Route::name('import')->post('import', [PackageSyncController::class, 'import']);
                            Route::name('icons')->post('icons', [PackageSyncController::class, 'icons']);
                        });

                        Route::name('versions')
                            ->middleware('throttle:' . self::VERSION_SYNC_LIMIT . ',1')
                            ->post('versions', [PackageSyncController::class, 'versions']);
                    });

                    // The Edit Package sync status panel and Docs tab.
                    Route::prefix('packages/{package}')->name('packages.')->group(function (): void {
                        Route::name('sync.status')->get('sync', [PackageSyncController::class, 'status']);

                        Route::middleware('throttle:' . self::SYNC_LIMIT . ',1')->group(function (): void {
                            Route::name('sync.now')->post('sync', [PackageSyncController::class, 'syncNow']);
                            Route::name('docs.import-docs')->post('docs/import-docs', [PackageDocsController::class, 'importDocs']);
                            Route::name('docs.import-changelog')->post('docs/import-changelog', [PackageDocsController::class, 'importChangelog']);
                        });

                        Route::middleware('throttle:' . self::DOCS_LIMIT . ',1')->group(function (): void {
                            Route::name('docs.status')->get('docs/status', [PackageDocsController::class, 'status']);
                            Route::name('docs.tree')->get('docs/tree', [PackageDocsController::class, 'tree']);
                            Route::name('docs.reorder')->post('docs/reorder', [PackageDocsController::class, 'reorder']);
                        });
                    });
                });

                // The kanban boards and the issue modal.
                Route::middleware(['permission:' . Permissions::ISSUES_MANAGE, 'throttle:' . self::BOARD_LIMIT . ',1'])->group(function (): void {
                    Route::name('board')->get('board', [BoardController::class, 'index']);
                    Route::name('board.move')->put('board/items/{item}/status', [BoardController::class, 'move'])
                        ->where('item', BoardController::ITEM_ID_PATTERN);
                    Route::name('packages.board')->get('packages/{package}/board', [BoardController::class, 'package']);

                    Route::prefix('issues/{repo}')->name('issues.')->where(['repo' => '[A-Za-z0-9._-]+'])->group(function (): void {
                        Route::name('options')->get('options', [IssueController::class, 'options']);
                        Route::name('show')->get('{number}', [IssueController::class, 'show'])->where('number', self::ISSUE_NUMBER_PATTERN);
                        Route::name('update')->patch('{number}', [IssueController::class, 'update'])->where('number', self::ISSUE_NUMBER_PATTERN);
                        Route::name('comments.store')->post('{number}/comments', [IssueController::class, 'comment'])->where('number', self::ISSUE_NUMBER_PATTERN);
                    });
                });

                Route::name('packages.stats')
                    ->middleware(['permission:' . Permissions::STATS_VIEW, 'throttle:' . self::STATS_LIMIT . ',1'])
                    ->get('packages/{package}/stats', [PackageStatsController::class, 'show']);

                // The icon picker on Edit Package. Icons are public data, so
                // any plugin permission will do.
                Route::name('icons.index')
                    ->middleware(['permission:' . Permissions::ACCESS, 'throttle:' . self::ICON_CATALOG_LIMIT . ',1'])
                    ->get('icons', [IconController::class, 'index']);
            });
    }
}
