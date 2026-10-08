<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Docs;

/**
 * One node of a package's documentation tree, as
 * `GET /packages/{package}/documentation` returns it. `parent` is 0 for a
 * root, and siblings arrive ordered by `menu_order`.
 *
 * @since 1.0.0
 */
final class DocsDocumentation
{
    /**
     * @param  list<self>  $children
     */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $slug,
        public readonly int $parent,
        public readonly int $menuOrder,
        public readonly array $children,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $children = is_array($data['children'] ?? null) ? $data['children'] : [];

        return new self(
            id: (int) ($data['id'] ?? 0),
            title: (string) ($data['title'] ?? ''),
            slug: (string) ($data['slug'] ?? ''),
            parent: (int) ($data['parent'] ?? 0),
            menuOrder: (int) ($data['menu_order'] ?? 0),
            children: array_values(array_map(
                static fn (array $child): self => self::fromArray($child),
                array_filter($children, 'is_array'),
            )),
        );
    }
}
