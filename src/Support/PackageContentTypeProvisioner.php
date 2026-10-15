<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Support;

use ArtisanPackUI\CMSFramework\Modules\ContentTypes\Managers\ContentTypeManager;
use ArtisanPackUI\CMSFramework\Modules\ContentTypes\Managers\TaxonomyManager;
use ArtisanPackUI\CMSFramework\Modules\ContentTypes\Models\ContentType;
use ArtisanPackUI\CMSFramework\Modules\ContentTypes\Models\Taxonomy;

/**
 * Brings an existing `package` content type up to {@see PackageContentType}:
 * adds missing `supports` flags and creates the package taxonomies.
 * Idempotent: safe on every boot.
 *
 * The taxonomies are persisted rows rather than filter registrations
 * because Keystone's inline term creation on the edit screen only accepts
 * persisted taxonomies. An existing row with the same slug is left as is,
 * so an admin's renames survive.
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
    ) {}

    /**
     * Whether the type is missing a supports flag or a taxonomy. Two small
     * queries, so the provider can call it on every boot.
     */
    public function isOutdated(): bool
    {
        $type = $this->contentType();

        return null === $type
            || [] !== PackageContentType::missingSupports($this->supportsOf($type))
            || [] !== PackageContentType::missingTaxonomies($this->persistedTaxonomySlugs());
    }

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

        foreach (PackageContentType::missingTaxonomies($this->persistedTaxonomySlugs()) as $taxonomy) {
            $this->taxonomies->createTaxonomy([
                ...$taxonomy,
                'content_type_slug' => PackageFields::CONTENT_TYPE,
                'show_in_admin'     => true,
            ]);
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
     * @return list<string>
     */
    private function persistedTaxonomySlugs(): array
    {
        return Taxonomy::query()
            ->whereIn('slug', array_column(PackageContentType::taxonomies(), 'slug'))
            ->pluck('slug')
            ->map(static fn (mixed $slug): string => (string) $slug)
            ->all();
    }
}
