<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Docs;

use Illuminate\Support\Carbon;

/**
 * The last docs or changelog import the docs site ran for a package
 * (`imports.docs` / `imports.changelog` on `PackageResource`).
 *
 * @since 1.0.0
 */
final class DocsImportStatus
{
    public const QUEUED = 'queued';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public function __construct(
        public readonly ?string $status,
        public readonly ?string $error,
        public readonly ?Carbon $importedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['status'] ?? null) ? $data['status'] : null,
            is_string($data['error'] ?? null) ? $data['error'] : null,
            DocsPackage::nullableDate($data['imported_at'] ?? null),
        );
    }

    public function isQueued(): bool
    {
        return self::QUEUED === $this->status;
    }
}
