<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Recap;

use ArtisanPackUI\Site\Exceptions\RecapException;

/**
 * Creates the recap's draft post in the host's blog.
 *
 * The plugin binds {@see NullRecapPostWriter} by default and the provider
 * swaps in {@see KeystoneRecapPostWriter} where cms-framework's blog is
 * installed, so the rest of the recap runs (and is tested) without it.
 *
 * @since 1.6.0
 */
interface RecapPostWriter
{
    /**
     * Create the post as a draft, with its category, tags, Packages terms,
     * featured image and SEO meta, all or nothing. `editUrl` is the path to
     * the post's admin editor.
     *
     * @return array{id: int, editUrl: string}
     *
     * @throws RecapException
     */
    public function create(RecapPostData $data): array;
}
