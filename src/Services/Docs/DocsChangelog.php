<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Docs;

use Illuminate\Support\Carbon;

/**
 * One changelog entry from `GET /packages/{package}/changelogs`.
 *
 * @since 0.2.0
 */
final class DocsChangelog
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $title,
        public readonly string $content,
        public readonly ?Carbon $createdAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) ($data['id'] ?? 0),
            title: is_string($data['title'] ?? null) ? $data['title'] : null,
            content: (string) ($data['content'] ?? ''),
            createdAt: DocsPackage::nullableDate($data['created_at'] ?? null),
        );
    }
}
