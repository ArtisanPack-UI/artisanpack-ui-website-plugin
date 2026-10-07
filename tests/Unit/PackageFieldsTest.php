<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Support\PackageFields;

it('defines the fields sync, stats and the boards rely on', function (): void {
    expect(PackageFields::keys())->toBe([
        'docs_package_id', 'registry', 'composer_name', 'npm_name', 'github_repo',
        'version', 'icon', 'docs_url', 'last_synced_at',
    ]);
});

it('limits the registry to packagist and npm', function (): void {
    $registry = collect(PackageFields::definitions())->firstWhere('key', 'registry');

    expect($registry['type'])->toBe('select')
        ->and(array_column($registry['options']['choices'], 'value'))->toBe(['packagist', 'npm']);
});

it('uses column types Keystone can materialize', function (): void {
    $columnTypes = ['string', 'text', 'integer', 'bigInteger', 'decimal', 'float', 'double', 'boolean', 'date', 'dateTime', 'time', 'json', 'binary'];

    foreach (PackageFields::definitions() as $field) {
        expect($columnTypes)->toContain($field['column_type']);
    }
});

it('avoids the keys Keystone reserves for its own columns', function (): void {
    $reserved = ['author_id', 'created_at', 'deleted_at', 'id', 'metadata', 'parent_id', 'password', 'published_at', 'remember_token', 'slug', 'status', 'updated_at', 'user_id', 'uuid', 'title', 'content', 'excerpt', 'featured_image_id'];

    expect(array_intersect(PackageFields::keys(), $reserved))->toBeEmpty();
});

it('lists only the fields still to register', function (): void {
    expect(array_column(PackageFields::missing(['registry', 'icon']), 'key'))->not->toContain('registry', 'icon')
        ->toHaveCount(7)
        ->and(PackageFields::missing(PackageFields::keys()))->toBe([]);
});
