<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Docs;

/**
 * The docs site's answer to an import trigger. Imports run on the docs
 * site's queue, so a `202 Accepted` means queued, not done: poll the
 * package's {@see DocsPackage::$imports} for the outcome.
 *
 * @since 0.2.0
 */
final class QueuedImport
{
    public function __construct(
        public readonly bool $queued,
        public readonly string $message,
        public readonly ?string $package,
        public readonly ?string $source,
    ) {}
}
