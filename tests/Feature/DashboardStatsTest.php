<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Models\PackageStatSnapshot;
use ArtisanPackUI\Site\Services\Stats\DashboardStats;
use Illuminate\Support\Carbon;

/**
 * The dashboard stats widgets' payloads (roadmap 4.3), read from the
 * daily snapshots. The widget classes themselves implement a host
 * interface and are checked in a running install.
 */

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-07 09:00:00'));
    $this->stats = new DashboardStats;
});

function dashboardPackage(string $title, array $attributes = []): Package
{
    return Package::query()->create(['title' => $title, ...$attributes]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function snapshot(Package $package, string $date, array $attributes): PackageStatSnapshot
{
    return PackageStatSnapshot::record($package->id, Carbon::parse($date), $attributes);
}

it('lists every package as a widget option after "All packages"', function (): void {
    $b = dashboardPackage('Icons');
    $a = dashboardPackage('Accessibility');

    expect(DashboardStats::packageOptions())->toBe([
        ['value' => 'all', 'label' => 'All packages'],
        ['value' => (string) $a->id, 'label' => 'Accessibility'],
        ['value' => (string) $b->id, 'label' => 'Icons'],
    ]);
});

describe('downloads KPI', function (): void {
    it('sums each package\'s latest snapshot', function (): void {
        $a = dashboardPackage('Accessibility');
        $b = dashboardPackage('Icons');
        snapshot($a, '2026-10-05', ['downloads_total' => 100, 'downloads_monthly' => 10]);
        snapshot($a, '2026-10-06', ['downloads_total' => 120, 'downloads_monthly' => 12]);
        snapshot($b, '2026-10-04', ['downloads_total' => 50, 'downloads_monthly' => null]);

        expect($this->stats->downloads('total', null))->toBe(['metric' => 'total', 'package' => null, 'value' => 170, 'asOf' => '2026-10-06'])
            ->and($this->stats->downloads('monthly', null)['value'])->toBe(12);
    });

    it('reads one package', function (): void {
        $a = dashboardPackage('Accessibility');
        $b = dashboardPackage('Icons');
        snapshot($a, '2026-10-06', ['downloads_daily' => 7]);
        snapshot($b, '2026-10-06', ['downloads_daily' => 3]);

        expect($this->stats->downloads('daily', $b->id))->toBe([
            'metric'  => 'daily',
            'package' => ['id' => $b->id, 'title' => 'Icons'],
            'value'   => 3,
            'asOf'    => '2026-10-06',
        ]);
    });

    it('falls back to every package when the chosen one was deleted', function (): void {
        $a = dashboardPackage('Accessibility');
        snapshot($a, '2026-10-06', ['downloads_total' => 9]);

        expect($this->stats->downloads('total', 999))->toMatchArray(['package' => null, 'value' => 9]);
    });

    it('is null, not zero, without snapshots', function (): void {
        dashboardPackage('Accessibility');

        expect($this->stats->downloads('total', null))->toMatchArray(['value' => null, 'asOf' => null]);
    });

    it('ignores snapshots of deleted packages', function (): void {
        $gone = dashboardPackage('Gone');
        snapshot($gone, '2026-10-06', ['downloads_total' => 500]);
        $gone->delete();

        expect($this->stats->downloads('total', null)['value'])->toBeNull();
    });
});

describe('downloads trend', function (): void {
    it('charts every day in the range, with gaps where there\'s no figure', function (): void {
        $a = dashboardPackage('Accessibility');
        $b = dashboardPackage('Icons');
        snapshot($a, '2026-10-01', ['downloads_daily' => 4]);
        snapshot($a, '2026-10-07', ['downloads_daily' => 6]);
        snapshot($b, '2026-10-07', ['downloads_daily' => 1]);
        snapshot($b, '2026-08-01', ['downloads_daily' => 99]);

        $trend = $this->stats->downloadsTrend($b->id, 30);
        $all   = collect($trend['series']['all'])->keyBy('date');

        expect($trend['range'])->toBe(30)
            ->and($trend['selected'])->toBe((string) $b->id)
            ->and($trend['packages'])->toHaveCount(2)
            ->and($trend['series']['all'])->toHaveCount(30)
            ->and($trend['series']['all'][0]['date'])->toBe('2026-09-08')
            ->and($all['2026-10-07']['value'])->toBe(7)
            ->and($all['2026-10-01']['value'])->toBe(4)
            ->and($all['2026-10-02']['value'])->toBeNull()
            ->and(collect($trend['series'][(string) $b->id])->whereNotNull('value')->pluck('value')->all())->toBe([1]);
    });

    it('opens on every package and an offered range by default', function (): void {
        dashboardPackage('Accessibility');

        expect($this->stats->downloadsTrend(null, 12))->toMatchArray(['range' => 30, 'selected' => 'all']);
    });
});

it('lists stars, open issues and PRs per package, most-starred first', function (): void {
    $a = dashboardPackage('Accessibility');
    $b = dashboardPackage('Icons');
    $c = dashboardPackage('Registry only');
    snapshot($a, '2026-10-05', ['stars' => 1, 'open_issues' => 9, 'open_prs' => 9]);
    snapshot($a, '2026-10-06', ['stars' => 10, 'open_issues' => 3, 'open_prs' => 1]);
    snapshot($b, '2026-10-06', ['stars' => 25, 'open_issues' => 0, 'open_prs' => 2]);
    snapshot($c, '2026-10-06', ['downloads_total' => 5]);

    expect($this->stats->gitHubOverview())->toBe([
        'packages' => [
            ['id' => $b->id, 'title' => 'Icons', 'stars' => 25, 'openIssues' => 0, 'openPullRequests' => 2],
            ['id' => $a->id, 'title' => 'Accessibility', 'stars' => 10, 'openIssues' => 3, 'openPullRequests' => 1],
        ],
        'totals' => ['stars' => 35, 'openIssues' => 3, 'openPullRequests' => 3],
        'asOf'   => '2026-10-06',
    ]);
});

describe('top packages', function (): void {
    beforeEach(function (): void {
        $this->a = dashboardPackage('Accessibility');
        $this->b = dashboardPackage('Icons');
        $this->c = dashboardPackage('New');

        // Accessibility: 1,000 → 1,300 over the window (+300, +30%).
        snapshot($this->a, '2026-09-01', ['downloads_total' => 900]);
        snapshot($this->a, '2026-09-07', ['downloads_total' => 1000]);
        snapshot($this->a, '2026-10-07', ['downloads_total' => 1300]);
        // Icons: 100 → 200 (+100, +100%).
        snapshot($this->b, '2026-09-07', ['downloads_total' => 100]);
        snapshot($this->b, '2026-10-07', ['downloads_total' => 200]);
        // New: a single snapshot can't be ranked.
        snapshot($this->c, '2026-10-07', ['downloads_total' => 5000]);
    });

    it('ranks by downloads gained over the window', function (): void {
        expect($this->stats->topPackages('downloads', 30, 5))->toBe([
            'rankBy'   => 'downloads',
            'window'   => 30,
            'windows'  => [7, 30, 90],
            'packages' => [
                ['id' => $this->a->id, 'title' => 'Accessibility', 'downloads' => 300, 'growth' => 30.0],
                ['id' => $this->b->id, 'title' => 'Icons', 'downloads' => 100, 'growth' => 100.0],
            ],
        ]);
    });

    it('ranks by growth', function (): void {
        expect(array_column($this->stats->topPackages('growth', 30, 5)['packages'], 'title'))->toBe(['Icons', 'Accessibility']);
    });

    it('measures from the first snapshot in the window, not before it', function (): void {
        // Nothing on 2026-09-30, the 7-day window's first day: Icons starts
        // from 2026-10-03, and the older snapshots are out of reach.
        snapshot($this->b, '2026-10-03', ['downloads_total' => 150]);

        expect($this->stats->topPackages('downloads', 7, 5)['packages'])->toBe([
            ['id' => $this->b->id, 'title' => 'Icons', 'downloads' => 50, 'growth' => 33.3],
        ]);
    });

    it('limits the list', function (): void {
        expect($this->stats->topPackages('downloads', 30, 1)['packages'])->toHaveCount(1);
    });
});

it('lists each package\'s latest release, newest first, then synced versions', function (): void {
    $a = dashboardPackage('Accessibility');
    $b = dashboardPackage('Icons');
    $c = dashboardPackage('Unreleased', ['version' => '0.1.0']);
    dashboardPackage('Nothing');
    snapshot($a, '2026-10-06', ['latest_release' => '2.4.0', 'latest_release_at' => Carbon::parse('2026-09-01 10:00:00')]);
    snapshot($b, '2026-10-06', ['latest_release' => '1.1.0', 'latest_release_at' => Carbon::parse('2026-10-02 10:00:00')]);

    expect($this->stats->releaseFeed(10)['releases'])->toBe([
        ['id' => $b->id, 'title' => 'Icons', 'version' => '1.1.0', 'releasedAt' => '2026-10-02T10:00:00+00:00'],
        ['id' => $a->id, 'title' => 'Accessibility', 'version' => '2.4.0', 'releasedAt' => '2026-09-01T10:00:00+00:00'],
        ['id' => $c->id, 'title' => 'Unreleased', 'version' => '0.1.0', 'releasedAt' => null],
    ])->and($this->stats->releaseFeed(1)['releases'])->toHaveCount(1);
});
