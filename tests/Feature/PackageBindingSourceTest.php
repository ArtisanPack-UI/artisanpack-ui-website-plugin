<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Bindings\PackageBindingSource;
use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Models\PackageStatSnapshot;
use ArtisanPackUI\Site\Tests\Fixtures\User;
use ArtisanPackUI\VisualEditor\Services\Bindings\BindingContext;

/**
 * The `package` binding source: the computed values package cards bind
 * to, read from whichever model is in scope over the `packages` table.
 */

function bindPackage(?Illuminate\Database\Eloquent\Model $model, string $key): mixed
{
    return (new PackageBindingSource)->resolve(new BindingContext($model), ['key' => $key]);
}

it('builds the install command for the package registry', function (): void {
    $composer = Package::query()->create(['title' => 'Core', 'registry' => 'packagist', 'composer_name' => 'artisanpack-ui/core']);
    $npm      = Package::query()->create(['title' => 'React', 'registry' => 'npm', 'npm_name' => '@artisanpack-ui/react']);
    $unnamed  = Package::query()->create(['title' => 'Blank', 'registry' => 'packagist']);

    expect(bindPackage($composer, 'install_command'))->toBe('composer require artisanpack-ui/core')
        ->and(bindPackage($npm, 'install_command'))->toBe('npm install @artisanpack-ui/react')
        ->and(bindPackage($unnamed, 'install_command'))->toBeNull();
});

it('links the GitHub repo only when it is owner/name', function (string $repo, ?string $url): void {
    $package = Package::query()->create(['title' => 'Core', 'github_repo' => $repo]);

    expect(bindPackage($package, 'github_url'))->toBe($url);
})->with([
    'owner/name'   => ['ArtisanPack-UI/core', 'https://github.com/ArtisanPack-UI/core'],
    'a full URL'   => ['https://evil.example/x', null],
    'a climb'      => ['ArtisanPack-UI/..', null],
    'missing name' => ['ArtisanPack-UI', null],
]);

it('reads the version, licence and latest stats into the meta line', function (): void {
    $package = Package::query()->create(['title' => 'Core', 'version' => '1.3.1', 'license' => 'MIT']);
    PackageStatSnapshot::query()->create(['package_id' => $package->id, 'date' => '2026-10-15', 'downloads_total' => 9000, 'stars' => 40]);
    PackageStatSnapshot::query()->create(['package_id' => $package->id, 'date' => '2026-10-16', 'downloads_total' => 12_345, 'stars' => 41]);

    expect(bindPackage($package, 'version_label'))->toBe('v1.3.1')
        ->and(bindPackage($package, 'license'))->toBe('MIT')
        ->and(bindPackage($package, 'downloads'))->toBe('12k')
        ->and(bindPackage($package, 'stars'))->toBe('41')
        ->and(bindPackage($package, 'meta'))->toBe('v1.3.1 · MIT · 12k installs');
});

it('labels the licence and stars badges and writes the install transcript', function (): void {
    $package = Package::query()->create(['title' => 'Core', 'registry' => 'packagist', 'composer_name' => 'artisanpack-ui/core', 'version' => '1.2.0', 'license' => 'MIT']);
    PackageStatSnapshot::query()->create(['package_id' => $package->id, 'date' => '2026-10-16', 'downloads_total' => 10, 'stars' => 41]);

    expect(bindPackage($package, 'license_label'))->toBe('MIT License')
        ->and(bindPackage($package, 'stars_label'))->toBe('★ 41')
        ->and(bindPackage($package, 'terminal'))->toBe("$ composer require artisanpack-ui/core\n# Using version ^1.2.0\n# ✓ Installed Core");
});

it('leaves out the badges and transcript a package cannot fill', function (): void {
    $package = Package::query()->create(['title' => 'Blank', 'registry' => 'packagist']);

    expect(bindPackage($package, 'license_label'))->toBeNull()
        ->and(bindPackage($package, 'stars_label'))->toBeNull()
        ->and(bindPackage($package, 'terminal'))->toBeNull();
});

it('leaves out what a package does not have', function (): void {
    $package = Package::query()->create(['title' => 'Core', 'version' => '1.0.0']);

    expect(bindPackage($package, 'downloads'))->toBeNull()
        ->and(bindPackage($package, 'license'))->toBeNull()
        ->and(bindPackage($package, 'meta'))->toBe('v1.0.0');
});

it('resolves nothing for a record outside the packages table or an unknown key', function (): void {
    $user    = User::query()->create(['email' => 'a@example.test', 'password' => 'x']);
    $package = Package::query()->create(['title' => 'Core', 'version' => '1.0.0']);

    expect(bindPackage($user, 'version_label'))->toBeNull()
        ->and(bindPackage(null, 'version_label'))->toBeNull()
        ->and(bindPackage($package, 'title'))->toBeNull();
});

it('abbreviates counts as the cards show them', function (int $count, string $text): void {
    expect(PackageBindingSource::compact($count))->toBe($text);
})->with([
    [980, '980'],
    [2100, '2.1k'],
    [6249, '6.2k'],
    [9990, '10k'],
    [12_400, '12k'],
    [999_600, '1M'],
    [1_400_000, '1.4M'],
]);

it('lists Packages in the query block post type picker', function (): void {
    $options = applyFilters('ap.visualEditor.query.postTypes', [['value' => 'post', 'label' => 'Posts']]);

    expect($options)->toContain(['value' => 'package', 'label' => 'Packages']);
});

it('lists the packages archive by title and leaves other types alone', function (): void {
    $default = [['published_at', 'desc']];

    expect(applyFilters('keystone.public.contentType.archive.order', $default, (object) ['slug' => 'package']))->toBe([['title', 'asc']])
        ->and(applyFilters('keystone.public.contentType.archive.order', $default, (object) ['slug' => 'case-study']))->toBe($default);
});
