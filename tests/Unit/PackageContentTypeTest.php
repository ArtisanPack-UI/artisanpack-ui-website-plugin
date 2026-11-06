<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Support\PackageContentType;

it('turns on the SEO panels for packages', function (): void {
    expect(PackageContentType::SUPPORTS)->toContain('seo');
});

it('binds a hierarchical category and a flat tag taxonomy to packages', function (): void {
    $taxonomies = collect(PackageContentType::taxonomies())->keyBy('slug');

    expect($taxonomies->keys()->all())->toBe([PackageContentType::CATEGORY_TAXONOMY, PackageContentType::TAG_TAXONOMY, PackageContentType::POST_TAXONOMY])
        ->and($taxonomies[PackageContentType::CATEGORY_TAXONOMY]['hierarchical'])->toBeTrue()
        ->and($taxonomies[PackageContentType::CATEGORY_TAXONOMY]['content_type_slug'])->toBe('package')
        ->and($taxonomies[PackageContentType::TAG_TAXONOMY]['hierarchical'])->toBeFalse()
        ->and($taxonomies[PackageContentType::TAG_TAXONOMY]['content_type_slug'])->toBe('package');
});

it('binds a flat Packages taxonomy to blog posts', function (): void {
    $packages = collect(PackageContentType::taxonomies())->firstWhere('slug', PackageContentType::POST_TAXONOMY);

    expect($packages['name'])->toBe('Packages')
        ->and($packages['hierarchical'])->toBeFalse()
        ->and($packages['content_type_slug'])->toBe('posts');
});

it('uses taxonomy slugs Keystone accepts', function (): void {
    foreach (PackageContentType::taxonomies() as $taxonomy) {
        expect($taxonomy['slug'])->toMatch('/^[a-z0-9]+(?:-[a-z0-9]+)*$/');
    }
});

it('lists only the supports flags still to add', function (): void {
    expect(PackageContentType::missingSupports(['title', 'editor', 'excerpt', 'featured_image', 'author']))->toBe(['seo'])
        ->and(PackageContentType::missingSupports(PackageContentType::SUPPORTS))->toBe([]);
});

it('lists only the taxonomies still to create', function (): void {
    expect(array_column(PackageContentType::missingTaxonomies([PackageContentType::CATEGORY_TAXONOMY]), 'slug'))
        ->toBe([PackageContentType::TAG_TAXONOMY, PackageContentType::POST_TAXONOMY])
        ->and(PackageContentType::missingTaxonomies([PackageContentType::CATEGORY_TAXONOMY, PackageContentType::TAG_TAXONOMY, PackageContentType::POST_TAXONOMY]))->toBe([]);
});

it('flags a package taxonomy slug bound to another content type', function (): void {
    expect(PackageContentType::taxonomyCollisions([
        PackageContentType::CATEGORY_TAXONOMY => 'post',
        PackageContentType::TAG_TAXONOMY      => 'package',
        'unrelated'                           => 'post',
    ]))->toBe([PackageContentType::CATEGORY_TAXONOMY])
        ->and(PackageContentType::taxonomyCollisions([PackageContentType::TAG_TAXONOMY => 'package']))->toBe([]);
});

it('flags a package taxonomy on posts only when another type holds its slug', function (): void {
    expect(PackageContentType::taxonomyCollisions([PackageContentType::POST_TAXONOMY => 'package']))->toBe([PackageContentType::POST_TAXONOMY])
        ->and(PackageContentType::taxonomyCollisions([PackageContentType::POST_TAXONOMY => 'posts']))->toBe([])
        ->and(PackageContentType::taxonomyCollisions([PackageContentType::POST_TAXONOMY => 'post']))->toBe([]);
});
