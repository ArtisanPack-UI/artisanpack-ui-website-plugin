<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Recap;

use ArtisanPackUI\Site\Exceptions\RecapException;

/**
 * The {@see RecapWriter} on an install without Keystone's AI module: never
 * available, so the recap button stays hidden and the endpoints refuse.
 *
 * @since 1.6.0
 */
final class NullRecapWriter implements RecapWriter
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function outline(array $input): array
    {
        throw RecapException::unavailable();
    }

    public function draft(array $input): array
    {
        throw RecapException::unavailable();
    }
}
