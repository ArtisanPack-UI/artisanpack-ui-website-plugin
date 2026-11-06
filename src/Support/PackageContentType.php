<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Support;

/**
 * The `package` content type's definition beyond its custom fields: the
 * `supports` flags its edit screen needs and the taxonomies the plugin
 * provisions, the package ones bound to `package` and {@see self::POST_TAXONOMY}
 * bound to blog posts.
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
     * The flat taxonomy on blog posts holding one term per package, so a
     * post can be tagged with the packages it covers. Its terms archive at
     * `/blog/package/{term}`.
     *
     * @since 1.5.0
     */
    public const POST_TAXONOMY = 'package';

    /**
     * The content type slug Keystone binds a taxonomy to blog posts with:
     * cms-framework registers posts as `posts`.
     *
     * @since 1.5.0
     */
    public const POST_BINDING = 'posts';

    /**
     * Every content type slug Keystone reads as blog posts. It accepts the
     * singular `post` as a synonym, so a `package` taxonomy an admin bound
     * to `post` is the plugin's, not a collision.
     *
     * @var list<string>
     */
    private const POST_BINDINGS = [self::POST_BINDING, 'post'];

    /**
     * The `supports` flags the type is created with and kept up to date
     * with. `seo` turns on the SEO and SEO insights panels on Edit Package.
     *
     * @var list<string>
     */
    public const SUPPORTS = ['title', 'editor', 'excerpt', 'featured_image', 'seo'];

    /**
     * The taxonomies the plugin provisions, each with the content type it's
     * bound to, as `TaxonomyManager::createTaxonomy()` takes them.
     *
     * @return list<array{name: string, slug: string, description: string, hierarchical: bool, content_type_slug: string}>
     */
    public static function taxonomies(): array
    {
        return [
            [
                'name'              => 'Package Categories',
                'slug'              => self::CATEGORY_TAXONOMY,
                'description'       => 'Hierarchical categories for ArtisanPack UI packages.',
                'hierarchical'      => true,
                'content_type_slug' => PackageFields::CONTENT_TYPE,
            ],
            [
                'name'              => 'Package Tags',
                'slug'              => self::TAG_TAXONOMY,
                'description'       => 'Free-form tags for ArtisanPack UI packages.',
                'hierarchical'      => false,
                'content_type_slug' => PackageFields::CONTENT_TYPE,
            ],
            [
                'name'              => 'Packages',
                'slug'              => self::POST_TAXONOMY,
                'description'       => 'The ArtisanPack UI packages a blog post covers. The plugin keeps one term per package.',
                'hierarchical'      => false,
                'content_type_slug' => self::POST_BINDING,
            ],
        ];
    }

    /**
     * Whether a taxonomy bound to `$owner` counts as bound where the
     * definition `$slug` belongs.
     */
    public static function isOwnBinding(string $slug, string $owner): bool
    {
        $definition = collect(self::taxonomies())->firstWhere('slug', $slug);

        if (null === $definition) {
            return false;
        }

        return self::POST_BINDING === $definition['content_type_slug']
            ? in_array($owner, self::POST_BINDINGS, true)
            : $definition['content_type_slug'] === $owner;
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
     * @return list<array{name: string, slug: string, description: string, hierarchical: bool, content_type_slug: string}>
     */
    public static function missingTaxonomies(array $existingSlugs): array
    {
        return array_values(array_filter(
            self::taxonomies(),
            static fn (array $taxonomy): bool => ! in_array($taxonomy['slug'], $existingSlugs, true),
        ));
    }

    /**
     * The plugin's taxonomy slugs already taken by a content type other
     * than the one each is bound to. Taxonomy slugs are unique across
     * types, so these can't be created, and Keystone only shows a type the
     * taxonomies bound to it.
     *
     * @param  array<string, string>  $owners  Taxonomy slug → the `content_type_slug` it's bound to.
     *
     * @return list<string>
     */
    public static function taxonomyCollisions(array $owners): array
    {
        $collisions = [];
        foreach (array_column(self::taxonomies(), 'slug') as $slug) {
            if (isset($owners[$slug]) && ! self::isOwnBinding($slug, $owners[$slug])) {
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
