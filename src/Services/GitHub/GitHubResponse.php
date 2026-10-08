<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\GitHub;

/**
 * A successful GitHub REST or GraphQL response: the decoded body (for
 * GraphQL, just its `data`) plus the rate-limit headers GitHub sent with it.
 *
 * @since 1.0.0
 */
final class GitHubResponse
{
    /**
     * @param  mixed     $data                The decoded JSON body, or GraphQL's `data`.
     * @param  int|null  $rateLimitRemaining  `X-RateLimit-Remaining`, when sent.
     * @param  int|null  $rateLimitReset      `X-RateLimit-Reset` (Unix seconds), when sent.
     */
    public function __construct(
        public readonly int $status,
        public readonly mixed $data,
        public readonly ?int $rateLimitRemaining = null,
        public readonly ?int $rateLimitReset = null,
    ) {}
}
