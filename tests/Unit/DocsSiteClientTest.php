<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Exceptions\DocsSiteException;
use ArtisanPackUI\Site\Exceptions\IntegrationNotConfiguredException;
use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Services\Docs\DocsSiteClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function docsClient(array $overrides = []): DocsSiteClient
{
    return new DocsSiteClient(new IntegrationSettings([
        'docs_base_url'  => 'https://docs.example.test/',
        'docs_api_token' => 'secret-token',
        ...$overrides,
    ]));
}

function docsPackagePayload(array $overrides = []): array
{
    return [
        'id'               => 7,
        'name'             => 'Accessibility',
        'slug'             => 'accessibility',
        'homepage'         => 12,
        'wiki_url'         => null,
        'docs_url'         => 'https://github.com/ArtisanPack-UI/accessibility/tree/main/docs',
        'changelog_url'    => 'https://github.com/ArtisanPack-UI/accessibility/blob/main/CHANGELOG.md',
        'icon'             => ['raw' => 'fas.universal-access', 'set' => 'fas', 'name' => 'universal-access', 'svg' => null],
        'version'          => '2.3.0',
        'package_registry' => 'packagist',
        'imports'          => [
            'docs'      => ['status' => 'succeeded', 'error' => null, 'imported_at' => '2026-10-01T12:00:00.000000Z'],
            'changelog' => ['status' => 'failed', 'error' => 'Not found', 'imported_at' => null],
        ],
        'updated_at' => '2026-10-02T12:00:00.000000Z',
        ...$overrides,
    ];
}

it('lists packages with the bearer token and maps them to typed objects', function (): void {
    Http::fake(['docs.example.test/api/v1/packages*' => Http::response(['data' => [docsPackagePayload()]])]);

    $packages = docsClient()->packages('accessibility');

    expect($packages)->toHaveCount(1)
        ->and($packages[0]->id)->toBe(7)
        ->and($packages[0]->registryName())->toBe('artisanpack-ui/accessibility')
        ->and($packages[0]->icon['name'])->toBe('universal-access')
        ->and($packages[0]->imports['docs']->status)->toBe('succeeded')
        ->and($packages[0]->imports['docs']->importedAt?->toDateString())->toBe('2026-10-01')
        ->and($packages[0]->imports['changelog']->error)->toBe('Not found');

    Http::assertSent(fn (Request $request): bool => 'https://docs.example.test/api/v1/packages?slug=accessibility' === $request->url()
        && 'Bearer secret-token' === $request->header('Authorization')[0]);
});

it('derives the npm name for npm packages', function (): void {
    Http::fake(['docs.example.test/*' => Http::response(['data' => docsPackagePayload(['slug' => 'react', 'package_registry' => 'npm'])])]);

    expect(docsClient()->package('react')->registryName())->toBe('@artisanpack-ui/react');
});

it('resends the full package payload when updating', function (): void {
    Http::fake([
        'docs.example.test/api/v1/packages/accessibility' => Http::response(['data' => docsPackagePayload()]),
        'docs.example.test/api/v1/packages/7'             => Http::response(['data' => docsPackagePayload(['version' => '2.4.0'])]),
    ]);

    $package = docsClient()->updatePackage('accessibility', ['version' => '2.4.0']);

    expect($package->version)->toBe('2.4.0');

    Http::assertSent(fn (Request $request): bool => 'PATCH' === $request->method()
        && str_ends_with($request->url(), '/packages/7')
        && '2.4.0' === $request['version']
        && 'accessibility' === $request['slug']
        && 'fas.universal-access' === $request['icon']
        && 'packagist' === $request['package_registry']);
});

it('reads the documentation tree and changelogs', function (): void {
    Http::fake([
        'docs.example.test/api/v1/packages/7/documentation' => Http::response(['data' => [
            ['id' => 1, 'title' => 'Intro', 'slug' => 'intro', 'parent' => 0, 'menu_order' => 0, 'children' => [
                ['id' => 2, 'title' => 'Install', 'slug' => 'install', 'parent' => 1, 'menu_order' => 0, 'children' => []],
            ]],
        ]]),
        'docs.example.test/api/v1/packages/7/changelogs' => Http::response(['data' => [
            ['id' => 3, 'title' => 'v2.3.0', 'content' => '- Fixes', 'created_at' => '2026-10-01T00:00:00.000000Z'],
        ]]),
    ]);

    $tree       = docsClient()->documentation(7);
    $changelogs = docsClient()->changelogs(7);

    expect($tree[0]->children[0]->title)->toBe('Install')
        ->and($tree[0]->children[0]->parent)->toBe(1)
        ->and($changelogs[0]->title)->toBe('v2.3.0');
});

it('posts the reorder payload', function (): void {
    Http::fake(['docs.example.test/*' => Http::response(['message' => 'Documentation order updated.'])]);

    expect(docsClient()->reorderDocumentation(7, [12 => 1, 15 => 0]))->toBe('Documentation order updated.');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/packages/7/documentation/reorder')
        && $request['items'] === [['id' => 12, 'menu_order' => 1], ['id' => 15, 'menu_order' => 0]]);
});

it('reports a 202 import trigger as queued', function (): void {
    Http::fake([
        'docs.example.test/api/v1/packages/7/import-docs'      => Http::response(['message' => 'Documentation import queued.', 'package' => 'accessibility', 'source' => 'docs'], 202),
        'docs.example.test/api/v1/packages/7/import-changelog' => Http::response(['message' => 'Changelog import queued.', 'package' => 'accessibility'], 202),
    ]);

    $docs      = docsClient()->importDocumentation(7);
    $changelog = docsClient()->importChangelog(7);

    expect($docs->queued)->toBeTrue()
        ->and($docs->source)->toBe('docs')
        ->and($changelog->queued)->toBeTrue()
        ->and($changelog->message)->toBe('Changelog import queued.');
});

it('maps API errors to admin-friendly messages', function (int $status, array $body, array $headers, string $expected): void {
    Http::fake(['docs.example.test/*' => Http::response($body, $status, $headers)]);

    expect(fn () => docsClient()->importDocumentation(7))
        ->toThrow(fn (DocsSiteException $exception) => expect($exception->status)->toBe($status)
            ->and($exception->getMessage())->toContain($expected));
})->with([
    '401' => [401, ['message' => 'Unauthenticated.'], [], 'rejected the API token'],
    '403' => [403, ['message' => 'Invalid ability provided.'], [], 'needs the "imports:trigger" ability'],
    '404' => [404, ['message' => 'No query results'], [], 'couldn\'t find that record'],
    '422' => [422, ['message' => 'The package does not have a docs URL or wiki URL configured.'], [], 'does not have a docs URL'],
    '429' => [429, ['message' => 'Too Many Attempts.'], ['Retry-After' => '17'], 'Try again in 17 seconds'],
    '500' => [500, [], [], 'had a problem handling the request (HTTP 500)'],
]);

it('keeps the per-field errors of a 422', function (): void {
    Http::fake(['docs.example.test/*' => Http::sequence()
        ->push(['data' => docsPackagePayload()])
        ->push(['message' => 'The version field must be a string.', 'errors' => ['version' => ['The version field must be a string.']]], 422)]);

    try {
        docsClient()->updatePackage(7, ['version' => 2]);
        $this->fail('Expected a validation failure.');
    } catch (DocsSiteException $exception) {
        expect($exception->errors)->toBe(['version' => ['The version field must be a string.']]);
    }
});

it('reports an unreachable docs site', function (): void {
    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('timeout'));

    expect(fn () => docsClient()->packages())->toThrow(DocsSiteException::class, 'Could not reach the docs site at https://docs.example.test.');
});

it('refuses to run unconfigured', function (): void {
    Http::fake();

    expect(fn () => docsClient(['docs_api_token' => null])->packages())->toThrow(IntegrationNotConfiguredException::class);

    Http::assertNothingSent();
});

it('does not follow redirects away from the docs site', function (): void {
    Http::fake(['docs.example.test/*' => Http::response('', 302, ['Location' => 'https://elsewhere.test/steal'])]);

    expect(fn () => docsClient()->packages())->toThrow(DocsSiteException::class);

    Http::assertSentCount(1);
});
