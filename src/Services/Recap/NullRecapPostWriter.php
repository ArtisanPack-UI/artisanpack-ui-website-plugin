<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Recap;

use ArtisanPackUI\Site\Exceptions\RecapException;

/**
 * The {@see RecapPostWriter} on an install without cms-framework's blog.
 *
 * @since 1.6.0
 */
final class NullRecapPostWriter implements RecapPostWriter
{
    public function create(RecapPostData $data): array
    {
        throw new RecapException(__('The blog isn\'t available, so the recap post can\'t be created.'));
    }
}
