<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Jobs\CollectPackageStats;
use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Models\PackageStatSnapshot;
use ArtisanPackUI\Site\Support\Permissions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Package stats (roadmap 4.1–4.2): the daily snapshot job and the Edit
 * Package Stats tab endpoint.
 */

beforeEach(function (): void {
    actingAsUserWith([Permissions::STATS_VIEW]);
    Http::preventStrayRequests();
    $this->travelTo(Carbon::parse('2026-10-07 04:00:00'));

    IntegrationSettings::create([
        'github_app_id'          => '123',
        'github_installation_id' => '456',
        'github_private_key'     => testPrivateKey(),
    ]);
});

/**
 * Packagist's package API for `artisanpack-ui/accessibility`.
 *
 * @return array<string, mixed>
 */
function packagistPackage(int $total = 5000): array
{
    return ['package' => [
        'downloads'  => ['total' => $total, 'monthly' => 400, 'daily' => 12],
        'dependents' => 3,
        'versions'   => [
            'dev-main' => ['version' => 'dev-main', 'time' => '2026-10-01T00:00:00+00:00', 'require' => ['php' => '^8.4']],
            'v2.4.0'   => ['version' => 'v2.4.0', 'time' => '2026-09-01T10:00:00+00:00', 'require' => [
                'php'                 => '^8.2',
                'illuminate/support'  => '^12.0|^13.0',
                'artisanpack-ui/core' => '^1.0',
            ]],
            '2.3.0' => ['version' => '2.3.0', 'time' => '2026-06-01T10:00:00+00:00', 'require' => ['php' => '^8.1']],
        ],
    ]];
}

/**
 * GitHub's GraphQL answer for a repo.
 *
 * @return array<string, mixed>
 */
function gitHubRepository(int $stars = 40): array
{
    return ['data' => ['repository' => [
        'stargazerCount' => $stars,
        'forkCount'      => 5,
        'watchers'       => ['totalCount' => 7],
        'issues'         => ['totalCount' => 3],
        'pullRequests'   => ['totalCount' => 2],
        'latestRelease'  => ['tagName' => 'v2.4.0', 'publishedAt' => '2026-09-01T10:05:00Z'],
    ]]];
}

/**
 * @param  array<string, mixed>  $others
 */
function fakeStatsSources(array $others = []): void
{
    Http::fake([
        ...$others,
        'packagist.org/packages/artisanpack-ui/accessibility.json' => Http::response(packagistPackage()),
        'api.github.com/app/installations/*'                       => Http::response(['token' => 'ghs_1', 'expires_at' => now()->addHour()->toIso8601String()], 201),
        'api.github.com/graphql'                                   => Http::response(gitHubRepository()),
    ]);
}

function statsPackage(array $attributes = []): Package
{
    return Package::query()->create([
        'title'         => 'A11y',
        'registry'      => 'packagist',
        'composer_name' => 'artisanpack-ui/accessibility',
        'github_repo'   => 'ArtisanPack-UI/accessibility',
        ...$attributes,
    ]);
}

describe('daily snapshot', function (): void {
    it('records today\'s figures from Packagist and GitHub', function (): void {
        $package = statsPackage();
        fakeStatsSources();

        CollectPackageStats::dispatchSync();

        $snapshot = PackageStatSnapshot::query()->sole();

        expect($snapshot->package_id)->toBe($package->id)
            ->and($snapshot->date->toDateString())->toBe('2026-10-07')
            ->and($snapshot->downloads_daily)->toBe(12)
            ->and($snapshot->downloads_monthly)->toBe(400)
            ->and($snapshot->downloads_total)->toBe(5000)
            ->and($snapshot->stars)->toBe(40)
            ->and($snapshot->forks)->toBe(5)
            ->and($snapshot->watchers)->toBe(7)
            ->and($snapshot->open_issues)->toBe(3)
            ->and($snapshot->open_prs)->toBe(2)
            ->and($snapshot->dependents)->toBe(3)
            ->and($snapshot->latest_release)->toBe('2.4.0')
            ->and($snapshot->latest_release_at?->toIso8601String())->toBe('2026-09-01T10:00:00+00:00');

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/graphql')
            && ['owner' => 'ArtisanPack-UI', 'name' => 'accessibility'] === (array) $request['variables']);
    });

    it('overwrites the same day instead of adding a row', function (): void {
        statsPackage();
        $calls = 0;
        Http::fake([
            'packagist.org/packages/*'           => function () use (&$calls) {
                return Http::response(packagistPackage(5000 + (++$calls)));
            },
            'api.github.com/app/installations/*' => Http::response(['token' => 'ghs_1', 'expires_at' => now()->addHour()->toIso8601String()], 201),
            'api.github.com/graphql'             => Http::response(gitHubRepository()),
        ]);

        CollectPackageStats::dispatchSync();
        CollectPackageStats::dispatchSync();

        expect(PackageStatSnapshot::query()->count())->toBe(1)
            ->and(PackageStatSnapshot::query()->value('downloads_total'))->toBe(5002);

        $this->travel(1)->day();
        CollectPackageStats::dispatchSync();

        expect(PackageStatSnapshot::query()->count())->toBe(2);
    });

    it('sums npm\'s lifetime downloads from range queries', function (): void {
        statsPackage(['registry' => 'npm', 'composer_name' => null, 'npm_name' => '@artisanpack-ui/react', 'github_repo' => null]);
        Http::fake([
            'registry.npmjs.org/*' => Http::response([
                'dist-tags' => ['latest' => '1.2.0'],
                'versions'  => ['1.1.0' => [], '1.2.0' => ['peerDependencies' => ['react' => '^19.0.0']]],
                'time'      => ['created' => '2025-01-01T00:00:00Z', '1.2.0' => '2026-08-01T00:00:00Z'],
            ]),
            'api.npmjs.org/downloads/point/last-day/*'   => Http::response(['downloads' => 5]),
            'api.npmjs.org/downloads/point/last-month/*' => Http::response(['downloads' => 150]),
            'api.npmjs.org/downloads/point/*'            => Http::response(['downloads' => 1000]),
        ]);

        CollectPackageStats::dispatchSync();

        $snapshot = PackageStatSnapshot::query()->sole();

        expect($snapshot->downloads_daily)->toBe(5)
            ->and($snapshot->downloads_monthly)->toBe(150)
            ->and($snapshot->downloads_total)->toBe(2000)
            ->and($snapshot->dependents)->toBeNull()
            ->and($snapshot->stars)->toBeNull()
            ->and($snapshot->latest_release)->toBe('1.2.0');

        // 2025-01-01 → 2026-10-06 is 644 days: one full 540-day range and the rest.
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/point/2025-01-01:2026-06-24/@artisanpack-ui/react'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/point/2026-06-25:2026-10-06/@artisanpack-ui/react'));
    });

    it('keeps the GitHub figures when the registry fails, and skips packages with nothing to read', function (): void {
        statsPackage(['composer_name' => 'artisanpack-ui/missing']);
        Package::query()->create(['title' => 'Bare']);
        fakeStatsSources(['packagist.org/packages/artisanpack-ui/missing.json' => Http::response([], 404)]);

        CollectPackageStats::dispatchSync();

        $snapshot = PackageStatSnapshot::query()->sole();

        expect($snapshot->downloads_total)->toBeNull()
            ->and($snapshot->stars)->toBe(40)
            ->and($snapshot->latest_release)->toBe('2.4.0');
    });
});

describe('Stats tab', function (): void {
    it('returns live figures, compatibility and the snapshots in range', function (): void {
        $package = statsPackage();
        $other   = statsPackage(['title' => 'Other']);
        fakeStatsSources();
        PackageStatSnapshot::record($package->id, Carbon::parse('2026-10-06'), ['stars' => 39]);
        PackageStatSnapshot::record($package->id, Carbon::parse('2026-09-08'), ['stars' => 30]);
        PackageStatSnapshot::record($package->id, Carbon::parse('2026-09-07'), ['stars' => 29]);
        PackageStatSnapshot::record($other->id, Carbon::parse('2026-10-06'), ['stars' => 1]);

        $this->getJson("/admin/artisanpack-ui/packages/{$package->id}/stats?range=30")
            ->assertOk()
            ->assertJsonPath('range', 30)
            ->assertJsonPath('live.downloads.total', 5000)
            ->assertJsonPath('live.openPullRequests', 2)
            ->assertJsonPath('live.latestRelease.version', '2.4.0')
            ->assertJsonPath('compatibility.registry', 'packagist')
            ->assertJsonPath('compatibility.requires', ['illuminate/support' => '^12.0|^13.0', 'php' => '^8.2'])
            ->assertJsonPath('compatibility.dependents', 3)
            ->assertJsonPath('errors', [])
            ->assertJsonPath('history', [
                ['date' => '2026-09-08', 'downloadsDaily' => null, 'downloadsMonthly' => null, 'downloadsTotal' => null, 'stars' => 30, 'forks' => null, 'watchers' => null, 'openIssues' => null, 'openPullRequests' => null, 'dependents' => null],
                ['date' => '2026-10-06', 'downloadsDaily' => null, 'downloadsMonthly' => null, 'downloadsTotal' => null, 'stars' => 39, 'forks' => null, 'watchers' => null, 'openIssues' => null, 'openPullRequests' => null, 'dependents' => null],
            ]);
    });

    it('caches complete live figures but retries partial ones', function (): void {
        $package = statsPackage();
        fakeStatsSources();

        $this->getJson("/admin/artisanpack-ui/packages/{$package->id}/stats")->assertOk()->assertJsonPath('range', 90);
        $this->getJson("/admin/artisanpack-ui/packages/{$package->id}/stats?range=365")->assertOk();

        Http::assertSentCount(3);

        $broken = statsPackage(['title' => 'Broken', 'github_repo' => 'ArtisanPack-UI/..']);

        $this->getJson("/admin/artisanpack-ui/packages/{$broken->id}/stats")
            ->assertOk()
            ->assertJsonPath('errors.0', '"ArtisanPack-UI/.." isn\'t a valid GitHub repo (expected owner/name).');
        $this->getJson("/admin/artisanpack-ui/packages/{$broken->id}/stats")->assertOk();

        Http::assertSentCount(5);
    });

    it('rejects a range the selector does not offer', function (): void {
        $package = statsPackage();

        $this->getJson("/admin/artisanpack-ui/packages/{$package->id}/stats?range=7")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['range']);

        Http::assertNothingSent();
    });

    it('refuses the Stats tab without the stats permission', function (): void {
        actingAsUserWith([Permissions::SYNC]);
        $package = statsPackage();

        $this->getJson("/admin/artisanpack-ui/packages/{$package->id}/stats")->assertForbidden();
    });
});
