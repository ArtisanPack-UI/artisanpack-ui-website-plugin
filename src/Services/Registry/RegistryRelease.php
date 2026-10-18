<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Registry;

/**
 * A package's latest stable release on Packagist or npm: its version and
 * the licence that release declares.
 *
 * `license` is the release's SPDX identifier, several joined with " / "
 * when a Packagist release lists more than one, or null when the release
 * declares none.
 *
 * @since 1.2.0
 */
final class RegistryRelease
{
    public function __construct(
        public readonly string $version,
        public readonly ?string $license,
    ) {}
}
