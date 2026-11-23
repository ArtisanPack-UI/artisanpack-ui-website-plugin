<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Recap;

/**
 * Everything a recap draft post is created with, worked out by
 * {@see RecapPostBuilder} and written by a {@see RecapPostWriter}.
 *
 * `tagIds` are existing blog tags; `newTagNames` are tags to create.
 * `termIds` are the blog post Packages taxonomy terms.
 *
 * @since 1.6.0
 */
final class RecapPostData
{
    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<int>  $tagIds
     * @param  list<string>  $newTagNames
     * @param  list<int>  $termIds
     */
    public function __construct(
        public readonly string $title,
        public readonly string $excerpt,
        public readonly array $blocks,
        public readonly ?int $featuredImageId,
        public readonly int $authorId,
        public readonly string $categoryName,
        public readonly string $categorySlug,
        public readonly array $tagIds,
        public readonly array $newTagNames,
        public readonly array $termIds,
        public readonly string $focusKeyword,
        public readonly string $metaDescription,
    ) {}
}
