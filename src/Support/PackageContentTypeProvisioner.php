<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Support;

use ArtisanPackUI\CMSFramework\Modules\ContentTypes\Managers\ContentTypeManager;
use ArtisanPackUI\CMSFramework\Modules\ContentTypes\Managers\TaxonomyManager;
use ArtisanPackUI\CMSFramework\Modules\ContentTypes\Models\ContentType;
use ArtisanPackUI\CMSFramework\Modules\ContentTypes\Models\Taxonomy;
use RuntimeException;

/**
 * Brings an existing `package` content type up to {@see PackageContentType}:
 * adds missing `supports` flags, creates the plugin's taxonomies, each bound
 * to its own content type, and gives every package its term in the blog
 * post `package` taxonomy ({@see PackageTermSync}). Idempotent: safe on
 * every boot.
 *
 * The taxonomies are persisted rows rather than filter registrations
 * because Keystone's inline term creation on the edit screen only accepts
 * persisted taxonomies. An existing row with the same slug bound to the
 * definition's type is left as is, so an admin's renames survive. One bound
 * to another type is a collision: taxonomy slugs are unique, so the
 * plugin's can't be created, and provisioning fails rather than report done.
 *
 * Host glue, so it only runs inside Keystone; the definitions themselves are
 * covered by the plugin's test suite.
 *
 * @since 1.1.0
 */
final class PackageContentTypeProvisioner
{
    public function __construct(
        private readonly ContentTypeManager $contentTypes,
        private readonly TaxonomyManager $taxonomies,
        private readonly PackageTermSync $terms,
    ) {}

    /**
     * Whether the type is missing a supports flag or a taxonomy bound to it.
     * A slug taken by another type counts as missing, so a collision is never
     * cached as provisioned. Two small queries, so the provider can call it
     * on every boot.
     */
    public function isOutdated(): bool
    {
        $type = $this->contentType();

        return null === $type
            || [] !== PackageContentType::missingSupports($this->supportsOf($type))
            || [] !== PackageContentType::missingTaxonomies($this->ownedTaxonomySlugs($this->taxonomyOwners()));
    }

    /**
     * @throws RuntimeException When one of the plugin's taxonomy slugs belongs
     *                          to another content type, after provisioning
     *                          everything else.
     */
    public function provision(): void
    {
        $type = $this->contentType();

        if (null !== $type) {
            $supports = $this->supportsOf($type);
            $missing  = PackageContentType::missingSupports($supports);

            if ([] !== $missing) {
                $this->contentTypes->updateContentType(PackageFields::CONTENT_TYPE, [
                    'supports' => [...$supports, ...$missing],
                ]);
            }
        }

        $owners     = $this->taxonomyOwners();
        $collisions = PackageContentType::taxonomyCollisions($owners);

        foreach (PackageContentType::missingTaxonomies(array_keys($owners)) as $taxonomy) {
            $this->taxonomies->createTaxonomy([
                ...$taxonomy,
                'show_in_admin' => true,
            ]);
        }

        // A no-op while the `package` slug is another type's.
        $this->terms->syncAll();

        if ([] !== $collisions) {
            throw new RuntimeException(sprintf(
                'Taxonomy "%s" already belongs to another content type, so the ArtisanPack UI plugin can\'t provision it.',
                implode('", "', $collisions),
            ));
        }
    }

    private function contentType(): ?ContentType
    {
        return $this->contentTypes->getPersistedContentType(PackageFields::CONTENT_TYPE);
    }

    /**
     * @return list<mixed>
     */
    private function supportsOf(ContentType $type): array
    {
        return array_values((array) ($type->supports ?? []));
    }

    /**
     * The plugin's persisted taxonomy slugs, each mapped to the content type
     * it's bound to.
     *
     * @return array<string, string>
     */
    private function taxonomyOwners(): array
    {
        return Taxonomy::query()
            ->whereIn('slug', array_column(PackageContentType::taxonomies(), 'slug'))
            ->pluck('content_type_slug', 'slug')
            ->map(static fn (mixed $owner): string => (string) $owner)
            ->all();
    }

    /**
     * The slugs in `$owners` bound where the plugin's definition binds them.
     *
     * @param  array<string, string>  $owners
     *
     * @return list<string>
     */
    private function ownedTaxonomySlugs(array $owners): array
    {
        return array_keys(array_filter(
            $owners,
            static fn (string $owner, string $slug): bool => PackageContentType::isOwnBinding($slug, $owner),
            ARRAY_FILTER_USE_BOTH,
        ));
    }
}
