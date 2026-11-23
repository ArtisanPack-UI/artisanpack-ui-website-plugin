<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The weekly recap post settings (#59): the featured image, the title
 * pattern and the style guide.
 */

beforeEach(function (): void {
    actingAsUserWith([Permissions::SETTINGS_MANAGE]);
});

/**
 * Add a media library row and return its id.
 */
function mediaRow(array $attributes = []): int
{
    return (int) DB::table('media')->insertGetId([
        'title'      => 'Recap cover',
        'file_name'  => 'recap-cover.jpg',
        'file_path'  => '2026/11/recap-cover.jpg',
        'disk'       => 'public',
        'mime_type'  => 'image/jpeg',
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ]);
}

it('saves the recap settings and presents the chosen image', function (): void {
    $image = mediaRow();

    $this->putJson('/admin/artisanpack-ui/settings', [
        'recap_featured_image_id' => $image,
        'recap_title_pattern'     => '  Weekly ArtisanPack UI – {date}  ',
        'recap_style_guide'       => 'Friendly, short sections.',
    ])
        ->assertOk()
        ->assertJsonPath('settings.recapFeaturedImage.id', $image)
        ->assertJsonPath('settings.recapFeaturedImage.title', 'Recap cover')
        ->assertJsonPath('settings.recapTitlePattern', 'Weekly ArtisanPack UI – {date}')
        ->assertJsonPath('settings.recapStyleGuide', 'Friendly, short sections.');

    $settings = IntegrationSettings::current();

    expect($settings->recapTitleFor(CarbonImmutable::parse('2026-11-21')))->toBe('Weekly ArtisanPack UI – November 21, 2026')
        ->and($settings->recapTitlePrefix())->toBe('Weekly ArtisanPack UI');
});

it('uses the default title pattern until one is saved', function (): void {
    $settings = IntegrationSettings::current();

    expect($settings->recapTitleFor(CarbonImmutable::parse('2026-11-21')))->toBe('This Week in ArtisanPack UI: November 21, 2026')
        ->and($settings->recapTitlePrefix())->toBe('This Week in ArtisanPack UI');
});

it('keeps the recap settings when a save leaves them out', function (): void {
    $image = mediaRow();
    IntegrationSettings::create([
        'recap_featured_image_id' => $image,
        'recap_title_pattern'     => 'Recap {date}',
        'recap_style_guide'       => 'Guide',
    ]);

    $this->putJson('/admin/artisanpack-ui/settings', ['github_organization' => 'ArtisanPack-UI'])->assertOk();

    $settings = IntegrationSettings::current();
    expect($settings->recap_featured_image_id)->toBe($image)
        ->and($settings->recap_title_pattern)->toBe('Recap {date}')
        ->and($settings->recap_style_guide)->toBe('Guide');
});

it('rejects invalid recap settings', function (Closure $value, string $field): void {
    $this->putJson('/admin/artisanpack-ui/settings', [$field => $value()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'a pattern without {date}' => [fn () => 'This Week in ArtisanPack UI', 'recap_title_pattern'],
    'nothing before {date}'    => [fn () => '{date} — Weekly recap', 'recap_title_pattern'],
    'titles over 255 chars'    => [fn () => str_repeat('a', 240) . ' {date}', 'recap_title_pattern'],
    'a missing image'          => [fn () => 9999, 'recap_featured_image_id'],
    'a document, not an image' => [fn () => mediaRow(['mime_type' => 'application/pdf']), 'recap_featured_image_id'],
    'a trashed image'          => [fn () => mediaRow(['deleted_at' => now()]), 'recap_featured_image_id'],
]);

it('lists and searches the media library images', function (): void {
    $cover = mediaRow(['title' => 'Weekly recap cover']);
    mediaRow(['title' => 'Team photo', 'file_name' => 'team.jpg']);
    mediaRow(['title' => 'Release notes PDF', 'mime_type' => 'application/pdf']);

    $this->getJson('/admin/artisanpack-ui/settings/recap-images')
        ->assertOk()
        ->assertJsonCount(2, 'images');

    $this->getJson('/admin/artisanpack-ui/settings/recap-images?search=recap')
        ->assertOk()
        ->assertJsonCount(1, 'images')
        ->assertJsonPath('images.0.id', $cover);
});

it('matches underscores in an image search literally', function (): void {
    $cover = mediaRow(['title' => 'Cover', 'file_name' => 'recap_cover.jpg']);
    mediaRow(['title' => 'Other', 'file_name' => 'recapXcover.jpg']);

    $this->getJson('/admin/artisanpack-ui/settings/recap-images?search=recap_cover')
        ->assertOk()
        ->assertJsonCount(1, 'images')
        ->assertJsonPath('images.0.id', $cover);
});

it('refuses the image list without the settings permission', function (): void {
    actingAsUserWith([Permissions::RECAPS_CREATE]);

    $this->getJson('/admin/artisanpack-ui/settings/recap-images')->assertForbidden();
});
