<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Support\PackageContentType;

it('turns on the SEO panels for packages', function (): void {
    expect(PackageContentType::SUPPORTS)->toContain('seo');
});

it('binds a hierarchical category and a flat tag taxonomy', function (): void {
    $taxonomies = collect(PackageContentType::taxonomies())->keyBy('slug');

    expect($taxonomies->keys()->all())->toBe([PackageContentType::CATEGORY_TAXONOMY, PackageContentType::TAG_TAXONOMY])
        ->and($taxonomies[PackageContentType::CATEGORY_TAXONOMY]['hierarchical'])->toBeTrue()
        ->and($taxonomies[PackageContentType::TAG_TAXONOMY]['hierarchical'])->toBeFalse();
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
        ->toBe([PackageContentType::TAG_TAXONOMY])
        ->and(PackageContentType::missingTaxonomies([PackageContentType::CATEGORY_TAXONOMY, PackageContentType::TAG_TAXONOMY]))->toBe([]);
});
