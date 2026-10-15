<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Support;

/**
 * The `package` content type's definition beyond its custom fields: the
 * `supports` flags its edit screen needs and the taxonomies bound to it.
 *
 * Pure data, so the definition is covered by the plugin's test suite;
 * {@see PackageContentTypeProvisioner} applies it inside Keystone.
 *
 * @since 1.1.0
 */
final class PackageContentType
{
    /**
     * The hierarchical category taxonomy.
     */
    public const CATEGORY_TAXONOMY = 'package-category';

    /**
     * The flat tag taxonomy.
     */
    public const TAG_TAXONOMY = 'package-tag';

    /**
     * The `supports` flags the type is created with and kept up to date
     * with. `seo` turns on the SEO and SEO insights panels on Edit Package.
     *
     * @var list<string>
     */
    public const SUPPORTS = ['title', 'editor', 'excerpt', 'featured_image', 'seo'];

    /**
     * The taxonomies bound to the `package` type, as
     * `TaxonomyManager::createTaxonomy()` takes them.
     *
     * @return list<array{name: string, slug: string, description: string, hierarchical: bool}>
     */
    public static function taxonomies(): array
    {
        return [
            [
                'name'         => 'Package Categories',
                'slug'         => self::CATEGORY_TAXONOMY,
                'description'  => 'Hierarchical categories for ArtisanPack UI packages.',
                'hierarchical' => true,
            ],
            [
                'name'         => 'Package Tags',
                'slug'         => self::TAG_TAXONOMY,
                'description'  => 'Free-form tags for ArtisanPack UI packages.',
                'hierarchical' => false,
            ],
        ];
    }

    /**
     * The {@see self::SUPPORTS} flags missing from a type's current
     * `supports` array. Flags an admin added beyond these are left alone.
     *
     * @param  array<int, mixed>  $supports
     *
     * @return list<string>
     */
    public static function missingSupports(array $supports): array
    {
        return array_values(array_diff(self::SUPPORTS, $supports));
    }

    /**
     * The taxonomy definitions whose slug isn't in `$existingSlugs`.
     *
     * @param  list<string>  $existingSlugs
     *
     * @return list<array{name: string, slug: string, description: string, hierarchical: bool}>
     */
    public static function missingTaxonomies(array $existingSlugs): array
    {
        return array_values(array_filter(
            self::taxonomies(),
            static fn (array $taxonomy): bool => ! in_array($taxonomy['slug'], $existingSlugs, true),
        ));
    }

    /**
     * The package taxonomy slugs already taken by another content type.
     * Taxonomy slugs are unique across types, so these can't be created
     * for `package`, and Keystone only shows a type the taxonomies bound
     * to it.
     *
     * @param  array<string, string>  $owners  Taxonomy slug → the `content_type_slug` it's bound to.
     *
     * @return list<string>
     */
    public static function taxonomyCollisions(array $owners): array
    {
        $collisions = [];
        foreach (array_column(self::taxonomies(), 'slug') as $slug) {
            if (isset($owners[$slug]) && PackageFields::CONTENT_TYPE !== $owners[$slug]) {
                $collisions[] = $slug;
            }
        }

        return $collisions;
    }

    /**
     * Cache key marking the whole package type, fields included, as
     * provisioned. Keyed on every definition, so a release that changes a
     * field, a supports flag or a taxonomy provisions again.
     */
    public static function provisionedMarkerKey(): string
    {
        return 'artisanpack-ui:content-type-provisioned:' . sha1((string) json_encode([
            PackageFields::provisionedMarkerKey(),
            self::SUPPORTS,
            self::taxonomies(),
        ]));
    }
}
