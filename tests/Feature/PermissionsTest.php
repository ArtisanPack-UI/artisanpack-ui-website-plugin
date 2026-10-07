<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Support\Permissions;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Every route the plugin registers is gated on a plugin permission, and each
 * permission can be granted on its own.
 */

it('declares every permission in plugin.json', function (): void {
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/plugin.json'), true);

    expect($manifest['permissions'])->toBe(Permissions::all());
});

it('gates every plugin JSON route on a plugin permission', function (): void {
    $routes = array_filter(
        RouteFacade::getRoutes()->getRoutes(),
        fn (Route $route): bool => str_starts_with((string) $route->getName(), 'artisanpack-ui.'),
    );

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        $gates = array_filter($route->gatherMiddleware(), fn (string $middleware): bool => str_starts_with($middleware, 'permission:artisanpack-ui.'));

        expect($gates)->not->toBeEmpty("Route {$route->getName()} has no plugin permission gate.");
    }
});

it('lets any plugin permission reach the landing page', function (string $permission): void {
    actingAsUserWith([$permission]);

    $this->get('/admin/artisanpack-ui')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('plugins/artisanpack-ui/packages-board', false));
})->with(Permissions::all());

it('refuses the landing page without a plugin permission', function (): void {
    actingAsUserWith();

    $this->get('/admin/artisanpack-ui')->assertForbidden();
});

it('refuses the landing page to a guest', function (): void {
    $this->getJson('/admin/artisanpack-ui')->assertUnauthorized();
});

it('opens settings only with the sync permission', function (): void {
    actingAsUserWith([Permissions::SYNC]);
    $this->get('/admin/artisanpack-ui/settings')->assertOk();

    actingAsUserWith([Permissions::ISSUES_MANAGE, Permissions::STATS_VIEW, Permissions::API_TOKENS_MANAGE]);
    $this->get('/admin/artisanpack-ui/settings')->assertForbidden();
});

it('refuses the settings endpoints without the sync permission', function (string $method, string $uri): void {
    actingAsUserWith([Permissions::ISSUES_MANAGE]);

    $this->json($method, $uri)->assertForbidden();
})->with([
    'save'        => ['PUT', '/admin/artisanpack-ui/settings'],
    'test docs'   => ['POST', '/admin/artisanpack-ui/settings/test/docs'],
    'test github' => ['POST', '/admin/artisanpack-ui/settings/test/github'],
]);

it('refuses the settings endpoints to a guest', function (): void {
    $this->putJson('/admin/artisanpack-ui/settings')->assertUnauthorized();
});

it('shares exactly the abilities the user holds with every page', function (): void {
    actingAsUserWith([Permissions::SYNC, Permissions::STATS_VIEW]);

    $this->get('/admin/artisanpack-ui')->assertInertia(fn (Assert $page) => $page
        ->where('can', ['sync' => true, 'issuesManage' => false, 'statsView' => true, 'apiTokensManage' => false])
        ->where('artisanpackUi.can.statsView', true)
        ->where('artisanpackUi.can.issuesManage', false));
});
