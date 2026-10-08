<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Support\Permissions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The Edit Package Docs tab (roadmap 3.1–3.2): import triggers and their
 * status, and reordering the documentation tree on the docs site.
 */

beforeEach(function (): void {
    actingAsUserWith([Permissions::SYNC]);
    Http::preventStrayRequests();

    IntegrationSettings::create([
        'docs_base_url'  => 'https://docs.example.invalid',
        'docs_api_token' => 'docs-token',
    ]);

    $this->package = Package::query()->create(['title' => 'A11y', 'docs_package_id' => 7]);
});

/**
 * A documentation node as `GET /packages/{package}/documentation` sends it.
 *
 * @param  list<array<string, mixed>>  $children
 *
 * @return array<string, mixed>
 */
function docNode(int $id, int $parent, int $menuOrder, array $children = []): array
{
    return ['id' => $id, 'title' => "Page {$id}", 'slug' => "page-{$id}", 'parent' => $parent, 'menu_order' => $menuOrder, 'children' => $children];
}

/**
 * Two roots (1, 2); root 1 has children 11, 12 and 13.
 */
function fakeDocTree(array $others = []): void
{
    Http::fake([
        'docs.example.invalid/api/v1/packages/7/documentation' => Http::response(['data' => [
            docNode(1, 0, 0, [docNode(11, 1, 0), docNode(12, 1, 1), docNode(13, 1, 2)]),
            docNode(2, 0, 1),
        ]]),
        ...$others,
    ]);
}

describe('imports', function (): void {
    it('reports the docs site import status', function (): void {
        Http::fake(['docs.example.invalid/api/v1/packages/7' => Http::response(['data' => [
            'id'      => 7,
            'name'    => 'Accessibility',
            'slug'    => 'accessibility',
            'imports' => [
                'docs'      => ['status' => 'queued', 'error' => null, 'imported_at' => null],
                'changelog' => ['status' => 'failed', 'error' => 'No changelog URL.', 'imported_at' => '2026-10-01T12:00:00Z'],
            ],
        ]])]);

        $this->getJson("/admin/artisanpack-ui/packages/{$this->package->id}/docs/status")
            ->assertOk()
            ->assertJsonPath('linked', true)
            ->assertJsonPath('imports.docs.status', 'queued')
            ->assertJsonPath('imports.changelog.status', 'failed')
            ->assertJsonPath('imports.changelog.error', 'No changelog URL.')
            ->assertJsonPath('imports.changelog.importedAt', '2026-10-01T12:00:00+00:00');
    });

    it('reports an unlinked package without calling the docs site', function (): void {
        $unlinked = Package::query()->create(['title' => 'Draft']);

        $this->getJson("/admin/artisanpack-ui/packages/{$unlinked->id}/docs/status")
            ->assertOk()
            ->assertExactJson(['linked' => false, 'imports' => null]);

        Http::assertNothingSent();
    });

    it('queues each import on the docs site', function (string $endpoint, string $docsEndpoint): void {
        Http::fake(["docs.example.invalid/api/v1/packages/7/{$docsEndpoint}" => Http::response(['message' => 'Import queued.'], 202)]);

        $this->postJson("/admin/artisanpack-ui/packages/{$this->package->id}/docs/{$endpoint}")
            ->assertAccepted()
            ->assertJsonPath('message', 'Import queued.')
            ->assertJsonPath('queued', true);

        Http::assertSent(fn (Request $request): bool => 'POST' === $request->method()
            && str_ends_with($request->url(), "/packages/7/{$docsEndpoint}"));
    })->with([
        'documentation' => ['import-docs', 'import-docs'],
        'changelog'     => ['import-changelog', 'import-changelog'],
    ]);

    it('explains a docs site refusal', function (): void {
        Http::fake(['docs.example.invalid/api/v1/packages/7/import-changelog' => Http::response(['message' => 'Forbidden.'], 403)]);

        $this->postJson("/admin/artisanpack-ui/packages/{$this->package->id}/docs/import-changelog")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The docs site API token isn\'t allowed to do this. It needs the "imports:trigger" ability.');
    });

    it('refuses to import for an unlinked package', function (): void {
        $unlinked = Package::query()->create(['title' => 'Draft']);

        $this->postJson("/admin/artisanpack-ui/packages/{$unlinked->id}/docs/import-docs")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This package isn\'t linked to the docs site yet. Run "Sync now" to link it.');

        Http::assertNothingSent();
    });
});

describe('reorder', function (): void {
    it('returns the documentation tree', function (): void {
        fakeDocTree();

        $this->getJson("/admin/artisanpack-ui/packages/{$this->package->id}/docs/tree")
            ->assertOk()
            ->assertJsonPath('tree.0.id', 1)
            ->assertJsonPath('tree.0.children.2.id', 13)
            ->assertJsonPath('tree.0.children.2.menuOrder', 2)
            ->assertJsonPath('tree.1.title', 'Page 2');
    });

    it('saves a new sibling order as menu_order', function (int $parent, array $ids, array $expected): void {
        fakeDocTree(['docs.example.invalid/api/v1/packages/7/documentation/reorder' => Http::response(['message' => 'Documentation order updated.'])]);

        $this->postJson("/admin/artisanpack-ui/packages/{$this->package->id}/docs/reorder", ['parent' => $parent, 'ids' => $ids])
            ->assertOk()
            ->assertJsonPath('message', 'Documentation order updated.');

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/documentation/reorder')
            && $expected === $request['items']);
    })->with([
        'roots'    => [0, [2, 1], [['id' => 2, 'menu_order' => 0], ['id' => 1, 'menu_order' => 1]]],
        'children' => [1, [13, 11, 12], [['id' => 13, 'menu_order' => 0], ['id' => 11, 'menu_order' => 1], ['id' => 12, 'menu_order' => 2]]],
    ]);

    it('refuses an order that is not exactly the parent\'s children', function (int $parent, array $ids): void {
        fakeDocTree();

        $this->postJson("/admin/artisanpack-ui/packages/{$this->package->id}/docs/reorder", ['parent' => $parent, 'ids' => $ids])
            ->assertConflict()
            ->assertJsonPath('message', 'The documentation changed on the docs site. Reload the tab and try again.');

        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/documentation/reorder'));
    })->with([
        'a page moved to another parent' => [1, [11, 12, 13, 2]],
        'a page left out'                => [1, [11, 12]],
        'an unknown parent'              => [99, [11]],
    ]);

    it('validates the reorder payload', function (): void {
        $this->postJson("/admin/artisanpack-ui/packages/{$this->package->id}/docs/reorder", ['parent' => 1, 'ids' => [11, 11]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ids.0', 'ids.1']);

        $this->postJson("/admin/artisanpack-ui/packages/{$this->package->id}/docs/reorder", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent', 'ids']);

        $this->postJson("/admin/artisanpack-ui/packages/{$this->package->id}/docs/reorder", ['parent' => 0, 'ids' => range(1, 501)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ids']);

        Http::assertNothingSent();
    });
});

it('refuses the Docs tab endpoints without the sync permission', function (string $method, string $endpoint): void {
    actingAsUserWith([Permissions::STATS_VIEW]);

    $this->json($method, "/admin/artisanpack-ui/packages/{$this->package->id}/docs/{$endpoint}")->assertForbidden();
})->with([
    ['GET', 'status'],
    ['GET', 'tree'],
    ['POST', 'import-docs'],
    ['POST', 'import-changelog'],
    ['POST', 'reorder'],
]);
