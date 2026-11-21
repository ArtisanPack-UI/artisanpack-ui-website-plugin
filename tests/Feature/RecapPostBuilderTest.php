<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Services\Recap\RecapPostBuilder;
use ArtisanPackUI\Site\Support\PackageContentType;
use ArtisanPackUI\Site\Support\PackageTermSync;
use Illuminate\Support\Facades\DB;

/**
 * Working out the recap draft post (#63): title, category, featured image,
 * tags and Packages terms.
 */

beforeEach(function (): void {
    DB::table('taxonomies')->insert([
        'name'              => 'Packages',
        'slug'              => PackageContentType::POST_TAXONOMY,
        'content_type_slug' => 'posts',
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);

    foreach (['Laravel', 'Forms', 'Accessibility'] as $name) {
        DB::table('post_tags')->insert(['name' => $name, 'slug' => strtolower($name), 'created_at' => now(), 'updated_at' => now()]);
    }
});

/**
 * An AI draft, normalized.
 *
 * @return array<string, mixed>
 */
function recapDraft(array $overrides = []): array
{
    return [
        'body'            => "Intro.\n\n## Forms 1.6\n\nUploads.",
        'excerpt'         => 'A busy week.',
        'focusKeyword'    => 'ArtisanPack UI updates',
        'metaDescription' => 'What shipped this week.',
        'tags'            => ['laravel', 'Forms'],
        'newTags'         => [],
        'packages'        => [],
        ...$overrides,
    ];
}

function termIdOf(Package $package): int
{
    return (int) DB::table(PackageTermSync::MAP_TABLE)->where('package_id', $package->getKey())->value('term_id');
}

it('builds the post from the context and the draft', function (): void {
    $image = (int) DB::table('media')->insertGetId(['title' => 'Cover', 'file_name' => 'c.jpg', 'file_path' => 'c.jpg', 'mime_type' => 'image/jpeg']);
    IntegrationSettings::create(['recap_featured_image_id' => $image]);

    $forms = Package::query()->create(['title' => 'Forms']);
    $media = Package::query()->create(['title' => 'Media Library']);
    Package::query()->create(['title' => 'Icons']);

    $data = app(RecapPostBuilder::class)->build([
        'title'    => 'This Week in ArtisanPack UI: November 20, 2026',
        'releases' => [['packageId' => $forms->getKey(), 'tag' => 'v1.6.0']],
    ], recapDraft(['packages' => ['media library', 'Unknown']]), 7);

    expect($data->title)->toBe('This Week in ArtisanPack UI: November 20, 2026')
        ->and($data->excerpt)->toBe('A busy week.')
        ->and(array_column($data->blocks, 'name'))->toBe(['artisanpack/paragraph', 'artisanpack/heading', 'artisanpack/paragraph'])
        ->and($data->featuredImageId)->toBe($image)
        ->and($data->authorId)->toBe(7)
        ->and($data->categoryName)->toBe('Updates')
        ->and($data->categorySlug)->toBe('updates')
        ->and($data->tagIds)->toBe(DB::table('post_tags')->whereIn('slug', ['laravel', 'forms'])->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all())
        ->and($data->newTagNames)->toBe([])
        ->and($data->termIds)->toBe([termIdOf($forms), termIdOf($media)])
        ->and($data->focusKeyword)->toBe('ArtisanPack UI updates')
        ->and($data->metaDescription)->toBe('What shipped this week.');
});

it('creates at most three new tags and eight in all', function (): void {
    $data = app(RecapPostBuilder::class)->build(['title' => 'T'], recapDraft([
        'tags'    => ['Laravel', 'Forms', 'Accessibility', 'Livewire'],
        'newTags' => ['Uploads', 'forms', 'Media', 'Testing', 'Icons'],
    ]), 1);

    expect($data->tagIds)->toHaveCount(3)
        ->and($data->newTagNames)->toBe(['Uploads', 'Media', 'Testing']);
});

it('caps the tags at eight even when they all exist', function (): void {
    foreach (range(1, 10) as $number) {
        DB::table('post_tags')->insert(['name' => 'Tag ' . $number, 'slug' => 'tag-' . $number]);
    }

    $data = app(RecapPostBuilder::class)->build(['title' => 'T'], recapDraft([
        'tags'    => array_map(static fn (int $number): string => 'Tag ' . $number, range(1, 10)),
        'newTags' => ['Brand new'],
    ]), 1);

    expect($data->tagIds)->toHaveCount(8)
        ->and($data->newTagNames)->toBe([]);
});

it('leaves the featured image off when the saved one is gone', function (): void {
    IntegrationSettings::create(['recap_featured_image_id' => 999]);

    expect(app(RecapPostBuilder::class)->build(['title' => 'T'], recapDraft(), 1)->featuredImageId)->toBeNull();
});
