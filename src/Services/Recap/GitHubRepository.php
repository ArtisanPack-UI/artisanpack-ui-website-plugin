<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Recap;

/**
 * Normalizes the repository a package names in its `github_repo` field,
 * which may be `owner/name`, a bare `name` (meaning the org's repository),
 * or a github.com URL.
 *
 * @since 1.6.0
 */
final class GitHubRepository
{
    private const SEGMENT = '[A-Za-z0-9._-]{1,100}';

    /**
     * `owner/name`, or null when the value doesn't name a repository.
     */
    public static function normalize(?string $value, string $organization): ?string
    {
        $value = trim((string) $value);

        if ('' === $value) {
            return null;
        }

        if (1 === preg_match('#^(?:https?://)?(?:www\.)?github\.com/(' . self::SEGMENT . ')/(' . self::SEGMENT . ')#i', $value, $match)) {
            return $match[1] . '/' . self::stripGitSuffix($match[2]);
        }

        if (1 === preg_match('#^(' . self::SEGMENT . ')/(' . self::SEGMENT . ')$#', $value, $match)) {
            return $match[1] . '/' . self::stripGitSuffix($match[2]);
        }

        if (1 === preg_match('#^' . self::SEGMENT . '$#', $value)) {
            return $organization . '/' . self::stripGitSuffix($value);
        }

        return null;
    }

    private static function stripGitSuffix(string $name): string
    {
        return str_ends_with(strtolower($name), '.git') ? substr($name, 0, -4) : $name;
    }
}
