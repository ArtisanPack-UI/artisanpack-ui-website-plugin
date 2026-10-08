<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Http\ArtisanPackUIRoutes;
use ArtisanPackUI\Site\Support\Permissions;
use ArtisanPackUI\Site\Support\PluginBootstrapper;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Plugins register their routes after the host has cached its route table.
 * Under a cached table the router holds a `CompiledRouteCollection`, which
 * never re-indexes names attached after the verb, so every route must be
 * named before it (see plan item 1.1).
 */

it('registers every shared route name under a compiled route table', function (): void {
    // A fresh cached table, as `route:cache` leaves it before plugins boot.
    $router = app('router');
    $router->setCompiledRoutes((new RouteCollection)->compile());

    ArtisanPackUIRoutes::register(['web']);

    foreach ([
        'artisanpack-ui.icons.index',
        'artisanpack-ui.sync.import',
        'artisanpack-ui.sync.versions',
        'artisanpack-ui.sync.icons',
        'artisanpack-ui.packages.sync.status',
        'artisanpack-ui.packages.sync.now',
        'artisanpack-ui.packages.docs.status',
        'artisanpack-ui.packages.docs.tree',
        'artisanpack-ui.packages.docs.import-docs',
        'artisanpack-ui.packages.docs.import-changelog',
        'artisanpack-ui.packages.docs.reorder',
        'artisanpack-ui.packages.stats',
        'artisanpack-ui.packages.board',
        'artisanpack-ui.board',
        'artisanpack-ui.board.move',
        'artisanpack-ui.issues.show',
        'artisanpack-ui.issues.update',
        'artisanpack-ui.issues.comments.store',
        'artisanpack-ui.issues.options',
        'artisanpack-ui.settings.update',
        'artisanpack-ui.settings.test-docs',
        'artisanpack-ui.settings.test-github',
    ] as $name) {
        expect(Route::has($name))->toBeTrue("Route {$name} isn't registered under a compiled route table.");
    }
});

it('leaves the shared prop empty instead of failing when a route name is missing', function (): void {
    actingAsUserWith([Permissions::ISSUES_MANAGE]);
    app('router')->setRoutes(new RouteCollection);
    app()->instance('request', Request::create('/admin/artisanpack-ui'));
    Log::spy();

    expect(PluginBootstrapper::sharedProps(app(), app(Gate::class)))->toBe([]);

    Log::shouldHaveReceived('warning')->once();
});

it('shares the plugin prop only on admin pages, with plugin users', function (): void {
    Route::middleware('web')->get('/admin/test-page', fn () => Inertia::render('test'));
    Route::middleware('web')->get('/test-page', fn () => Inertia::render('test'));

    $this->get('/admin/test-page')->assertInertia(fn (Assert $page) => $page->where('artisanpackUi', []));

    actingAsUserWith();
    $this->get('/admin/test-page')->assertInertia(fn (Assert $page) => $page->where('artisanpackUi', []));

    actingAsUserWith([Permissions::STATS_VIEW]);
    $this->get('/test-page')->assertInertia(fn (Assert $page) => $page->where('artisanpackUi', []));
    $this->get('/admin/test-page')->assertInertia(fn (Assert $page) => $page
        ->where('artisanpackUi.can.statsView', true)
        ->has('artisanpackUi.endpoints.icons'));
});
