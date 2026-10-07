<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Exceptions;

/**
 * GitHub refused a request because a rate limit was hit: either the primary
 * hourly quota (`X-RateLimit-Remaining: 0`) or a secondary, abuse-detection
 * limit (a `Retry-After` header, or a "secondary rate limit" message).
 *
 * The client never sleeps and retries inside a web request; callers decide
 * whether to surface {@see self::$retryAfter} or release a queued job.
 *
 * @since 0.2.0
 */
final class GitHubRateLimitException extends GitHubException
{
    public function __construct(
        string $message,
        int $status,
        public readonly int $retryAfter,
        public readonly bool $secondary,
    ) {
        parent::__construct($message, $status);
    }

    public static function primary(int $status, int $retryAfter): self
    {
        return new self(
            __('The GitHub API rate limit is used up. It resets in about :minutes minute(s).', ['minutes' => max(1, (int) ceil($retryAfter / 60))]),
            $status,
            $retryAfter,
            false,
        );
    }

    public static function secondary(int $status, int $retryAfter): self
    {
        return new self(
            __('GitHub is temporarily limiting requests. Try again in :seconds seconds.', ['seconds' => $retryAfter]),
            $status,
            $retryAfter,
            true,
        );
    }
}
