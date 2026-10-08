<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Jobs\SyncPackages;
use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Models\PackageSyncState;
use ArtisanPackUI\Site\Support\PackageIconSet;
use ArtisanPackUI\Site\Support\Permissions;
use ArtisanPackUI\VisualEditor\Services\Icon\SvgSanitizer;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * "Sync from docs" (roadmap 2.1–2.3): importing docs packages as drafts,
 * syncing versions from the registries to both sites, and syncing icons.
 * Plus the daily run and per-package "Sync now" with its status (2.4).
 */

beforeEach(function (): void {
    actingAsUserWith([Permissions::SYNC]);

    $this->docsList  = [];
    $this->docsFaked = false;

    IntegrationSettings::create([
        'docs_base_url'  => 'https://docs.example.invalid',
        'docs_api_token' => 'docs-token',
    ]);
});

/**
 * A docs site package as `PackageResource` sends it.
 *
 * @return array<string, mixed>
 */
function docsPackage(int $id, string $slug, array $overrides = []): array
{
    return [
        'id'               => $id,
        'name'             => ucfirst($slug),
        'slug'             => $slug,
        'homepage'         => null,
        'wiki_url'         => null,
        'docs_url'         => "https://github.com/ArtisanPack-UI/{$slug}/tree/main/docs",
        'changelog_url'    => "https://github.com/ArtisanPack-UI/{$slug}/blob/main/CHANGELOG.md",
        'icon'             => null,
        'version'          => '1.0.0',
        'package_registry' => 'packagist',
        ...$overrides,
    ];
}

/**
 * Fake the docs site's package list, plus any other responses. Calling it
 * again in the same test swaps the list, since a second `Http::fake()`
 * can't replace a stub the first one registered.
 *
 * @param  list<array<string, mixed>>  $packages
 * @param  array<string, mixed>        $others
 */
function fakeDocs(array $packages, array $others = []): void
{
    $test           = test();
    $test->docsList = $packages;

    if ($test->docsFaked) {
        return;
    }

    $test->docsFaked = true;

    Http::fake([
        'docs.example.invalid/api/v1/packages' => fn () => Http::response(['data' => $test->docsList]),
        ...$others,
    ]);
}

describe('import', function (): void {
    it('creates a draft for each docs package not here yet', function (): void {
        fakeDocs([docsPackage(1, 'accessibility'), docsPackage(2, 'react', ['package_registry' => 'npm'])]);

        $this->postJson('/admin/artisanpack-ui/sync/import')
            ->assertOk()
            ->assertJsonPath('report.created', 2)
            ->assertJsonPath('report.updated', 0)
            ->assertJsonPath('report.skipped', 0);

        $react = Package::query()->where('docs_package_id', 2)->firstOrFail();

        expect(Package::query()->count())->toBe(2)
            ->and($react->title)->toBe('React')
            ->and($react->status)->toBe('draft')
            ->and($react->registry)->toBe('npm')
            ->and($react->npm_name)->toBe('@artisanpack-ui/react')
            ->and($react->composer_name)->toBeNull()
            ->and($react->github_repo)->toBe('ArtisanPack-UI/react')
            ->and($react->last_synced_at)->not->toBeNull();
    });

    it('never overwrites the title or body of an existing package', function (): void {
        Package::query()->create([
            'title'           => 'Accessibility for Laravel',
            'content'         => [['name' => 'core/paragraph']],
            'status'          => 'published',
            'docs_package_id' => 1,
            'github_repo'     => 'someone/fork',
        ]);
        fakeDocs([docsPackage(1, 'accessibility', ['name' => 'Renamed upstream'])]);

        $this->postJson('/admin/artisanpack-ui/sync/import')
            ->assertOk()
            ->assertJsonPath('report.created', 0)
            ->assertJsonPath('report.updated', 1);

        $package = Package::query()->sole();

        expect($package->title)->toBe('Accessibility for Laravel')
            ->and($package->content)->toBe([['name' => 'core/paragraph']])
            ->and($package->status)->toBe('published')
            ->and($package->github_repo)->toBe('someone/fork')
            ->and($package->composer_name)->toBe('artisanpack-ui/accessibility');
    });

    it('links an unlinked package by its registry name', function (): void {
        $existing = Package::query()->create(['title' => 'Hand made', 'composer_name' => 'artisanpack-ui/accessibility']);
        fakeDocs([docsPackage(9, 'accessibility')]);

        $this->postJson('/admin/artisanpack-ui/sync/import')
            ->assertOk()
            ->assertJsonPath('report.created', 0)
            ->assertJsonPath('report.updated', 1);

        expect($existing->fresh()->docs_package_id)->toBe(9);
    });

    it('skips packages that are already up to date', function (): void {
        fakeDocs([docsPackage(1, 'accessibility')]);

        $this->postJson('/admin/artisanpack-ui/sync/import')->assertJsonPath('report.created', 1);
        $this->postJson('/admin/artisanpack-ui/sync/import')
            ->assertJsonPath('report.created', 0)
            ->assertJsonPath('report.skipped', 1);

        expect(Package::query()->count())->toBe(1);
    });

    it('reports a docs site failure as an admin-friendly 422', function (): void {
        Http::fake(['docs.example.invalid/*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $this->postJson('/admin/artisanpack-ui/sync/import')
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'rejected the API token'));
    });
});

describe('versions', function (): void {
    it('takes the latest stable release from Packagist and npm', function (): void {
        Package::query()->create(['title' => 'A11y', 'registry' => 'packagist', 'composer_name' => 'artisanpack-ui/accessibility', 'version' => '2.0.0']);
        Package::query()->create(['title' => 'React', 'registry' => 'npm', 'npm_name' => '@artisanpack-ui/react']);
        fakeDocs([], [
            'repo.packagist.org/p2/artisanpack-ui/accessibility.json' => Http::response(['packages' => ['artisanpack-ui/accessibility' => [
                ['version' => '3.0.0-beta.1'],
                ['version' => 'v2.4.0'],
                ['version' => '2.10.1'],
            ]]]),
            'registry.npmjs.org/*' => Http::response(['dist-tags' => ['latest' => '1.2.0'], 'versions' => ['1.2.0' => []]]),
        ]);

        $this->postJson('/admin/artisanpack-ui/sync/versions')
            ->assertOk()
            ->assertJsonPath('report.updated', 2)
            ->assertJsonPath('report.failed', 0);

        expect(Package::query()->where('title', 'A11y')->value('version'))->toBe('2.10.1')
            ->and(Package::query()->where('title', 'React')->value('version'))->toBe('1.2.0');

        Http::assertSent(fn (Request $request): bool => 'https://registry.npmjs.org/@artisanpack-ui%2Freact' === $request->url());
    });

    it('falls back to the newest stable npm version when latest is a pre-release', function (): void {
        Package::query()->create(['title' => 'React', 'registry' => 'npm', 'npm_name' => '@artisanpack-ui/react']);
        fakeDocs([], [
            'registry.npmjs.org/*' => Http::response(['dist-tags' => ['latest' => '2.0.0-rc.1'], 'versions' => ['1.9.0' => [], '1.10.0' => [], '2.0.0-rc.1' => []]]),
        ]);

        $this->postJson('/admin/artisanpack-ui/sync/versions')->assertJsonPath('report.updated', 1);

        expect(Package::query()->value('version'))->toBe('1.10.0');
    });

    it('patches the docs site when its version differs', function (): void {
        Package::query()->create(['title' => 'A11y', 'registry' => 'packagist', 'composer_name' => 'artisanpack-ui/accessibility', 'docs_package_id' => 1]);
        Package::query()->create(['title' => 'Forms', 'registry' => 'packagist', 'composer_name' => 'artisanpack-ui/forms', 'docs_package_id' => 2]);
        fakeDocs([docsPackage(1, 'accessibility', ['version' => '2.3.0']), docsPackage(2, 'forms', ['version' => '1.5.0'])], [
            'repo.packagist.org/p2/artisanpack-ui/accessibility.json' => Http::response(['packages' => ['artisanpack-ui/accessibility' => [['version' => '2.4.0']]]]),
            'repo.packagist.org/p2/artisanpack-ui/forms.json'         => Http::response(['packages' => ['artisanpack-ui/forms' => [['version' => '1.5.0']]]]),
            'docs.example.invalid/api/v1/packages/1'                  => Http::response(['data' => docsPackage(1, 'accessibility', ['version' => '2.3.0'])]),
        ]);

        $this->postJson('/admin/artisanpack-ui/sync/versions')
            ->assertOk()
            ->assertJsonPath('report.updated', 2)
            ->assertJsonPath('report.docsUpdated', 1);

        Http::assertSent(fn (Request $request): bool => 'PATCH' === $request->method()
            && str_ends_with($request->url(), '/packages/1')
            && '2.4.0' === $request['version']);
        Http::assertNotSent(fn (Request $request): bool => 'PATCH' === $request->method() && str_ends_with($request->url(), '/packages/2'));
    });

    it('falls back to the latest GitHub release', function (): void {
        IntegrationSettings::current()->update([
            'github_app_id'          => '123',
            'github_installation_id' => '456',
            'github_private_key'     => testPrivateKey(),
        ]);
        Package::query()->create(['title' => 'A11y', 'registry' => 'packagist', 'composer_name' => 'artisanpack-ui/accessibility', 'github_repo' => 'ArtisanPack-UI/accessibility']);
        fakeDocs([], [
            'repo.packagist.org/*'                                  => Http::response([], 404),
            'api.github.com/app/installations/*'                    => Http::response(['token' => 'ghs_1', 'expires_at' => now()->addHour()->toIso8601String()], 201),
            'api.github.com/repos/ArtisanPack-UI/accessibility/releases/latest' => Http::response(['tag_name' => 'v2.5.0']),
        ]);

        $this->postJson('/admin/artisanpack-ui/sync/versions')->assertJsonPath('report.updated', 1);

        expect(Package::query()->value('version'))->toBe('2.5.0');
    });

    it('syncs versions in batches, following the cursor', function (): void {
        foreach (range(1, 7) as $number) {
            Package::query()->create(['title' => "Package {$number}", 'registry' => 'packagist', 'composer_name' => "artisanpack-ui/package-{$number}"]);
        }
        fakeDocs([], ['repo.packagist.org/*' => fn (Request $request) => Http::response(['packages' => [
            str_replace(['https://repo.packagist.org/p2/', '.json'], '', $request->url()) => [['version' => '1.0.0']],
        ]])]);

        $first = $this->postJson('/admin/artisanpack-ui/sync/versions')
            ->assertOk()
            ->assertJsonPath('report.updated', 5)
            ->json('report.next');

        $this->postJson('/admin/artisanpack-ui/sync/versions?after=' . $first)
            ->assertOk()
            ->assertJsonPath('report.updated', 2)
            ->assertJsonPath('report.next', null);

        expect(Package::query()->whereNull('version')->count())->toBe(0);
    });

    it('leaves out links the docs site no longer accepts when patching', function (): void {
        Package::query()->create(['title' => 'A11y', 'registry' => 'packagist', 'composer_name' => 'artisanpack-ui/accessibility', 'docs_package_id' => 1]);
        $docs = docsPackage(1, 'accessibility', ['version' => '2.3.0', 'wiki_url' => 'https://gitlab.com/example/accessibility.wiki.git']);
        fakeDocs([$docs], [
            'repo.packagist.org/*'                   => Http::response(['packages' => ['artisanpack-ui/accessibility' => [['version' => '2.4.0']]]]),
            'docs.example.invalid/api/v1/packages/1' => Http::response(['data' => $docs]),
        ]);

        $this->postJson('/admin/artisanpack-ui/sync/versions')->assertJsonPath('report.docsUpdated', 1);

        Http::assertSent(fn (Request $request): bool => 'PATCH' === $request->method()
            && ! array_key_exists('wiki_url', $request->data())
            && str_contains((string) $request['docs_url'], 'github.com'));
    });

    it('never asks GitHub for a repo path that climbs out of the owner', function (): void {
        IntegrationSettings::current()->update([
            'github_app_id'          => '123',
            'github_installation_id' => '456',
            'github_private_key'     => testPrivateKey(),
        ]);
        Package::query()->create(['title' => 'Sneaky', 'github_repo' => 'ArtisanPack-UI/..']);
        fakeDocs([]);

        $this->postJson('/admin/artisanpack-ui/sync/versions')
            ->assertOk()
            ->assertJsonPath('report.failed', 1)
            ->assertJsonPath('report.messages.0', fn (string $message): bool => str_contains($message, 'isn\'t a valid GitHub repo'));

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.github.com'));
    });

    it('reports packages it could not version', function (): void {
        Package::query()->create(['title' => 'Missing', 'registry' => 'packagist', 'composer_name' => 'artisanpack-ui/missing']);
        Package::query()->create(['title' => 'Bare']);
        fakeDocs([], ['repo.packagist.org/*' => Http::response([], 404)]);

        $this->postJson('/admin/artisanpack-ui/sync/versions')
            ->assertOk()
            ->assertJsonPath('report.failed', 1)
            ->assertJsonPath('report.skipped', 1)
            ->assertJsonPath('report.messages.0', fn (string $message): bool => str_contains($message, 'Packagist has no package named'));
    });
});

describe('icons', function (): void {
    it('stores Font Awesome icons as an iconRef and custom icons in the apui set', function (): void {
        $iconSet = useTemporaryIconSet();
        Package::query()->create(['title' => 'A11y', 'docs_package_id' => 1]);
        Package::query()->create(['title' => 'React', 'docs_package_id' => 2]);
        fakeDocs([
            docsPackage(1, 'accessibility', ['icon' => ['raw' => 'fas.universal-access', 'set' => 'fas', 'name' => 'universal-access', 'svg' => null]]),
            docsPackage(2, 'react', ['icon' => ['raw' => 'ap.puzzle', 'set' => 'ap', 'name' => 'puzzle', 'svg' => testSvg('M9 9h9')]]),
            docsPackage(3, 'unlinked', ['icon' => ['raw' => 'fas.cube', 'set' => 'fas', 'name' => 'cube', 'svg' => null]]),
        ]);

        $this->postJson('/admin/artisanpack-ui/sync/icons')
            ->assertOk()
            ->assertJsonPath('report.updated', 2);

        expect(DB::table('packages')->where('docs_package_id', 1)->value('icon'))->toBe('{"set":"fas","name":"universal-access"}')
            ->and(Package::query()->where('docs_package_id', 2)->first()->icon)->toBe(['set' => 'apui', 'name' => 'puzzle'])
            ->and((string) file_get_contents($iconSet->directory() . '/puzzle.svg'))->toContain('M9 9h9');
    });

    it('keeps a manually chosen icon', function (): void {
        useTemporaryIconSet();
        $package = Package::query()->create(['title' => 'A11y', 'docs_package_id' => 1]);
        fakeDocs([docsPackage(1, 'accessibility', ['icon' => ['raw' => 'fas.cube', 'set' => 'fas', 'name' => 'cube', 'svg' => null]])]);
        $this->postJson('/admin/artisanpack-ui/sync/icons')->assertJsonPath('report.updated', 1);

        $package->update(['icon' => ['set' => 'fab', 'name' => 'github']]);
        fakeDocs([docsPackage(1, 'accessibility', ['icon' => ['raw' => 'fas.box', 'set' => 'fas', 'name' => 'box', 'svg' => null]])]);

        $this->postJson('/admin/artisanpack-ui/sync/icons')
            ->assertJsonPath('report.updated', 0)
            ->assertJsonPath('report.skipped', 1);

        expect($package->fresh()->icon)->toBe(['set' => 'fab', 'name' => 'github']);
    });

    it('replaces an icon that still matches the last synced one', function (): void {
        useTemporaryIconSet();
        $package = Package::query()->create(['title' => 'A11y', 'docs_package_id' => 1]);
        fakeDocs([docsPackage(1, 'accessibility', ['icon' => ['raw' => 'fas.cube', 'set' => 'fas', 'name' => 'cube', 'svg' => null]])]);
        $this->postJson('/admin/artisanpack-ui/sync/icons');

        fakeDocs([docsPackage(1, 'accessibility', ['icon' => ['raw' => 'fas.box', 'set' => 'fas', 'name' => 'box', 'svg' => null]])]);

        $this->postJson('/admin/artisanpack-ui/sync/icons')->assertJsonPath('report.updated', 1);

        expect($package->fresh()->icon)->toBe(['set' => 'fas', 'name' => 'box'])
            ->and(PackageSyncState::for($package)->last_synced_icon)->toBe(['set' => 'fas', 'name' => 'box']);
    });

    it('treats a hand-typed icon value that is not an iconRef as a manual choice', function (): void {
        useTemporaryIconSet();
        $id = DB::table('packages')->insertGetId(['title' => 'A11y', 'docs_package_id' => 1, 'icon' => 'fa-cube']);
        fakeDocs([docsPackage(1, 'accessibility', ['icon' => ['raw' => 'fas.box', 'set' => 'fas', 'name' => 'box', 'svg' => null]])]);

        $this->postJson('/admin/artisanpack-ui/sync/icons')
            ->assertJsonPath('report.updated', 0)
            ->assertJsonPath('report.skipped', 1);

        expect(DB::table('packages')->where('id', $id)->value('icon'))->toBe('fa-cube');
    });

    it('fails only the package whose icon cannot be written', function (): void {
        $blocker = sys_get_temp_dir() . '/artisanpack-ui-blocker-' . bin2hex(random_bytes(6));
        file_put_contents($blocker, 'not a directory');
        $this->beforeApplicationDestroyed(fn () => @unlink($blocker));
        app()->instance(PackageIconSet::class, new PackageIconSet(new SvgSanitizer, $blocker . '/icons'));

        Package::query()->create(['title' => 'React', 'docs_package_id' => 2]);
        Package::query()->create(['title' => 'A11y', 'docs_package_id' => 1]);
        fakeDocs([
            docsPackage(2, 'react', ['icon' => ['raw' => 'ap.puzzle', 'set' => 'ap', 'name' => 'puzzle', 'svg' => testSvg()]]),
            docsPackage(1, 'accessibility', ['icon' => ['raw' => 'fas.cube', 'set' => 'fas', 'name' => 'cube', 'svg' => null]]),
        ]);

        $this->postJson('/admin/artisanpack-ui/sync/icons')
            ->assertOk()
            ->assertJsonPath('report.failed', 1)
            ->assertJsonPath('report.updated', 1);
    });

    it('fails a custom icon whose SVG is unusable', function (): void {
        $iconSet = useTemporaryIconSet();
        $package = Package::query()->create(['title' => 'React', 'docs_package_id' => 2]);
        fakeDocs([docsPackage(2, 'react', ['icon' => ['raw' => 'ap.puzzle', 'set' => 'ap', 'name' => 'puzzle', 'svg' => '<p>not svg</p>']])]);

        $this->postJson('/admin/artisanpack-ui/sync/icons')
            ->assertOk()
            ->assertJsonPath('report.failed', 1);

        expect($package->fresh()->icon)->toBeNull()
            ->and(file_exists($iconSet->directory() . '/puzzle.svg'))->toBeFalse();
    });
});

describe('sync now', function (): void {
    it('links, versions and iconises one package and records a clean check', function (): void {
        useTemporaryIconSet();
        $this->freezeTime();
        $package = Package::query()->create(['title' => 'Hand made', 'registry' => 'packagist', 'composer_name' => 'artisanpack-ui/accessibility']);
        $other   = Package::query()->create(['title' => 'Untouched', 'registry' => 'packagist', 'composer_name' => 'artisanpack-ui/forms']);
        $docs    = docsPackage(1, 'accessibility', ['version' => '2.4.0', 'icon' => ['raw' => 'fas.cube', 'set' => 'fas', 'name' => 'cube', 'svg' => null]]);
        fakeDocs([$docs], [
            'docs.example.invalid/api/v1/packages/1' => Http::response(['data' => $docs]),
            'repo.packagist.org/p2/artisanpack-ui/accessibility.json' => Http::response(['packages' => ['artisanpack-ui/accessibility' => [['version' => '2.4.0']]]]),
        ]);

        $this->postJson("/admin/artisanpack-ui/packages/{$package->id}/sync")
            ->assertOk()
            ->assertJsonPath('message', 'Package synced.')
            ->assertJsonPath('status.linked', true)
            ->assertJsonPath('status.lastError', null)
            ->assertJsonPath('status.lastCheckedAt', now()->toIso8601String());

        $package->refresh();

        expect($package->docs_package_id)->toBe(1)
            ->and($package->version)->toBe('2.4.0')
            ->and($package->icon)->toBe(['set' => 'fas', 'name' => 'cube'])
            ->and($other->fresh()->version)->toBeNull()
            ->and(PackageSyncState::query()->where('package_id', $other->id)->exists())->toBeFalse();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'artisanpack-ui/forms'));
    });

    it('records and shows the last error', function (): void {
        $package = Package::query()->create(['title' => 'Missing', 'registry' => 'packagist', 'composer_name' => 'artisanpack-ui/missing']);
        fakeDocs([], ['repo.packagist.org/*' => Http::response([], 404)]);

        $this->postJson("/admin/artisanpack-ui/packages/{$package->id}/sync")
            ->assertOk()
            ->assertJsonPath('message', 'Sync finished with problems.')
            ->assertJsonPath('status.lastError', fn (string $error): bool => str_contains($error, 'Packagist has no package named'));

        $this->getJson("/admin/artisanpack-ui/packages/{$package->id}/sync")
            ->assertOk()
            ->assertJsonPath('status.linked', false)
            ->assertJsonPath('status.lastError', fn (string $error): bool => str_contains($error, 'Packagist has no package named'));
    });

    it('still syncs the version when the docs site is down, and records why the rest failed', function (): void {
        $package = Package::query()->create(['title' => 'A11y', 'registry' => 'packagist', 'composer_name' => 'artisanpack-ui/accessibility', 'docs_package_id' => 1]);
        Http::fake([
            'docs.example.invalid/*' => Http::response([], 500),
            'repo.packagist.org/*'   => Http::response(['packages' => ['artisanpack-ui/accessibility' => [['version' => '2.4.0']]]]),
        ]);

        $this->postJson("/admin/artisanpack-ui/packages/{$package->id}/sync")
            ->assertOk()
            ->assertJsonPath('status.lastError', fn (string $error): bool => str_starts_with($error, 'Import: The docs site had a problem')
                && str_contains($error, 'Icons: The docs site had a problem'));

        expect($package->fresh()->version)->toBe('2.4.0');
    });

    it('clears the last error once a sync succeeds', function (): void {
        $package = Package::query()->create(['title' => 'A11y', 'registry' => 'packagist', 'composer_name' => 'artisanpack-ui/accessibility']);
        PackageSyncState::for($package)->fill(['last_error' => 'Old failure'])->save();
        fakeDocs([], ['repo.packagist.org/*' => Http::response(['packages' => ['artisanpack-ui/accessibility' => [['version' => '1.0.0']]]])]);

        $this->postJson("/admin/artisanpack-ui/packages/{$package->id}/sync")->assertJsonPath('status.lastError', null);
    });

    it('answers 404 for a package that does not exist', function (): void {
        $this->postJson('/admin/artisanpack-ui/packages/999/sync')->assertNotFound();
    });
});

describe('daily run', function (): void {
    it('syncs every package in every batch and records each outcome', function (): void {
        foreach (range(1, 6) as $number) {
            Package::query()->create(['title' => "Package {$number}", 'registry' => 'packagist', 'composer_name' => "artisanpack-ui/package-{$number}"]);
        }
        $broken = Package::query()->create(['title' => 'Broken', 'registry' => 'packagist', 'composer_name' => 'artisanpack-ui/broken']);
        fakeDocs([docsPackage(10, 'new-one')], [
            'repo.packagist.org/p2/artisanpack-ui/broken.json' => Http::response([], 404),
            'repo.packagist.org/*'                           => fn (Request $request) => Http::response(['packages' => [
                str_replace(['https://repo.packagist.org/p2/', '.json'], '', $request->url()) => [['version' => '1.0.0']],
            ]]),
        ]);

        SyncPackages::dispatchSync();

        expect(Package::query()->where('version', '1.0.0')->count())->toBe(7)
            ->and(Package::query()->where('docs_package_id', 10)->value('status'))->toBe('draft')
            ->and(PackageSyncState::query()->whereNotNull('last_checked_at')->count())->toBe(8)
            ->and(PackageSyncState::for($broken)->last_error)->toContain('Packagist has no package named')
            ->and(PackageSyncState::query()->whereNotNull('last_error')->count())->toBe(1);
    });

    it('schedules the sync and the stats snapshot daily', function (): void {
        $events = collect(app(Schedule::class)->events())->keyBy(fn (Event $event): string => (string) $event->description);

        expect($events->get('artisanpack-ui:sync-packages')?->expression)->toBe('0 3 * * *')
            ->and($events->get('artisanpack-ui:collect-package-stats')?->expression)->toBe('0 4 * * *');
    });
});

it('refuses the sync endpoints without the sync permission', function (string $step): void {
    actingAsUserWith([Permissions::ISSUES_MANAGE]);

    $this->postJson("/admin/artisanpack-ui/sync/{$step}")->assertForbidden();
})->with(['import', 'versions', 'icons']);

it('refuses the package sync status and sync now without the sync permission', function (): void {
    actingAsUserWith([Permissions::STATS_VIEW]);
    $package = Package::query()->create(['title' => 'A11y']);

    $this->getJson("/admin/artisanpack-ui/packages/{$package->id}/sync")->assertForbidden();
    $this->postJson("/admin/artisanpack-ui/packages/{$package->id}/sync")->assertForbidden();
});
