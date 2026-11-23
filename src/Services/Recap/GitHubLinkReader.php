<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Recap;

use ArtisanPackUI\Site\Exceptions\RecapException;
use ArtisanPackUI\Site\Services\GitHub\GitHubAppClient;

/**
 * Reads a GitHub pull request, issue, commit or release linked in the recap
 * notes, through the GitHub App, so private context the App can see (and
 * the details a public page hides behind JavaScript) reach the AI.
 *
 * @phpstan-type GitHubItem array{url: string, kind: string, title: string, text: string}
 *
 * @since 1.6.0
 */
final class GitHubLinkReader
{
    /**
     * The longest body kept, and the most changed files listed.
     */
    public const BODY_LIMIT = 6000;

    public const FILES_LIMIT = 40;

    private const LINK = '#^https?://(?:www\.)?github\.com/([A-Za-z0-9._-]{1,100})/([A-Za-z0-9._-]{1,100})/(pull|issues|commit|releases/tag)/([^?\#]+)#i';

    public function __construct(private readonly GitHubAppClient $gitHub) {}

    /**
     * Whether the URL is a GitHub link this reader understands.
     */
    public static function handles(string $url): bool
    {
        return 1 === preg_match(self::LINK, trim($url));
    }

    /**
     * @return GitHubItem
     *
     * @throws RecapException When the URL isn't a supported GitHub link.
     */
    public function read(string $url): array
    {
        $url = trim($url);

        if (1 !== preg_match(self::LINK, $url, $match)) {
            throw RecapException::invalidUrl($url);
        }

        [, $owner, $name, $kind, $reference] = $match;
        $repo = '/repos/' . $owner . '/' . $name;

        // A release tag may contain slashes (`packages/forms/v1.2.0`); for
        // the rest only the first segment counts (`/pull/12/files` → 12).
        $reference = 'releases/tag' === strtolower($kind) ? rtrim($reference, '/') : strtok($reference, '/');

        return match (strtolower($kind)) {
            'pull'   => $this->pullRequest($url, $repo, self::number($reference, $url)),
            'issues' => $this->issue($url, $repo, self::number($reference, $url)),
            'commit' => $this->commit($url, $repo, $reference),
            default  => $this->release($url, $repo, rawurldecode($reference)),
        };
    }

    /**
     * @return GitHubItem
     */
    private function pullRequest(string $url, string $repo, int $number): array
    {
        $pull  = $this->data($repo . '/pulls/' . $number);
        $files = $this->gitHub->rest('GET', $repo . '/pulls/' . $number . '/files', ['per_page' => self::FILES_LIMIT])->data;
        $state = ! empty($pull['merged_at']) ? 'merged ' . $pull['merged_at'] : (string) ($pull['state'] ?? '');

        $lines = [
            'Pull request #' . $number . ' (' . $state . ')',
            self::labels($pull),
            '',
            mb_substr(trim((string) ($pull['body'] ?? '')), 0, self::BODY_LIMIT),
        ];

        if (is_array($files) && [] !== $files) {
            $lines[] = '';
            $lines[] = 'Changed files:';

            foreach ($files as $file) {
                if (is_array($file)) {
                    $lines[] = '- ' . ($file['filename'] ?? '') . ' (+' . (int) ($file['additions'] ?? 0) . ' -' . (int) ($file['deletions'] ?? 0) . ')';
                }
            }
        }

        return ['url' => $url, 'kind' => 'pull_request', 'title' => (string) ($pull['title'] ?? ''), 'text' => self::join($lines)];
    }

    /**
     * @return GitHubItem
     */
    private function issue(string $url, string $repo, int $number): array
    {
        $issue = $this->data($repo . '/issues/' . $number);

        return ['url' => $url, 'kind' => 'issue', 'title' => (string) ($issue['title'] ?? ''), 'text' => self::join([
            'Issue #' . $number . ' (' . ($issue['state'] ?? '') . ')',
            self::labels($issue),
            '',
            mb_substr(trim((string) ($issue['body'] ?? '')), 0, self::BODY_LIMIT),
        ])];
    }

    /**
     * @return GitHubItem
     */
    private function commit(string $url, string $repo, string $sha): array
    {
        if (1 !== preg_match('/^[0-9a-f]{7,40}$/i', $sha)) {
            throw RecapException::invalidUrl($url);
        }

        $commit  = $this->data($repo . '/commits/' . $sha);
        $message = trim((string) ($commit['commit']['message'] ?? ''));
        $lines   = [mb_substr($message, 0, self::BODY_LIMIT)];
        $files   = is_array($commit['files'] ?? null) ? array_slice($commit['files'], 0, self::FILES_LIMIT) : [];

        if ([] !== $files) {
            $lines[] = '';
            $lines[] = 'Changed files:';

            foreach ($files as $file) {
                if (is_array($file)) {
                    $lines[] = '- ' . ($file['filename'] ?? '');
                }
            }
        }

        return ['url' => $url, 'kind' => 'commit', 'title' => strtok($message, "\n") ?: $sha, 'text' => self::join($lines)];
    }

    /**
     * @return GitHubItem
     */
    private function release(string $url, string $repo, string $tag): array
    {
        $release = $this->data($repo . '/releases/tags/' . rawurlencode($tag));

        return ['url' => $url, 'kind' => 'release', 'title' => (string) (($release['name'] ?? '') ?: $tag), 'text' => self::join([
            'Release ' . $tag . ' (' . ($release['published_at'] ?? '') . ')',
            '',
            mb_substr(trim((string) ($release['body'] ?? '')), 0, self::BODY_LIMIT),
        ])];
    }

    /**
     * @return array<string, mixed>
     */
    private function data(string $path): array
    {
        $data = $this->gitHub->rest('GET', $path)->data;

        return is_array($data) ? $data : [];
    }

    private static function number(string $reference, string $url): int
    {
        if (1 !== preg_match('/^[1-9][0-9]{0,9}$/', $reference)) {
            throw RecapException::invalidUrl($url);
        }

        return (int) $reference;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function labels(array $item): string
    {
        $labels = array_filter(array_map(
            static fn (mixed $label): string => is_array($label) ? (string) ($label['name'] ?? '') : '',
            is_array($item['labels'] ?? null) ? $item['labels'] : [],
        ));

        return [] === $labels ? '' : 'Labels: ' . implode(', ', $labels);
    }

    /**
     * @param  list<string>  $lines
     */
    private static function join(array $lines): string
    {
        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }
}
