<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Recap;

use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Services\GitHub\GitHubAppClient;

/**
 * Finds the pull requests merged across the org inside a
 * {@see RecapWindow}, through GitHub's issue search, so a recap can mention
 * work that hasn't been released yet.
 *
 * @phpstan-type MergedPullRequest array{repo: string, number: int, title: string, url: string, labels: list<string>, closedAt: string|null, body: string}
 *
 * @since 1.6.0
 */
final class MergedPullRequestSearch
{
    /**
     * The most results one search returns.
     */
    public const MAX_RESULTS = 50;

    /**
     * The longest body excerpt kept per pull request.
     */
    public const BODY_LIMIT = 600;

    public function __construct(
        private readonly GitHubAppClient $gitHub,
        private readonly IntegrationSettings $settings,
    ) {}

    /**
     * @param  string|null  $keywords  Words to narrow the search; anything but letters, digits, spaces, dots, dashes and underscores is dropped.
     * @param  string|null  $repo      A repository name in the org to narrow to.
     *
     * @return list<MergedPullRequest>
     */
    public function search(RecapWindow $window, ?string $keywords = null, ?string $repo = null, int $limit = self::MAX_RESULTS): array
    {
        $response = $this->gitHub->rest('GET', '/search/issues', [
            'q'        => $this->query($window, $keywords, $repo),
            'sort'     => 'updated',
            'order'    => 'desc',
            'per_page' => max(1, min(self::MAX_RESULTS, $limit)),
        ]);

        $items = is_array($response->data) && is_array($response->data['items'] ?? null) ? $response->data['items'] : [];

        return array_values(array_map(static fn (array $item): array => [
            'repo'     => self::repoFrom((string) ($item['repository_url'] ?? '')),
            'number'   => (int) ($item['number'] ?? 0),
            'title'    => (string) ($item['title'] ?? ''),
            'url'      => (string) ($item['html_url'] ?? ''),
            'labels'   => array_values(array_filter(array_map(
                static fn (mixed $label): string => is_array($label) ? (string) ($label['name'] ?? '') : '',
                is_array($item['labels'] ?? null) ? $item['labels'] : [],
            ))),
            'closedAt' => is_string($item['closed_at'] ?? null) ? $item['closed_at'] : null,
            'body'     => mb_substr(trim((string) ($item['body'] ?? '')), 0, self::BODY_LIMIT),
        ], array_filter($items, 'is_array')));
    }

    /**
     * The search query: merged pull requests in the org (or one of its
     * repositories) inside the window, optionally narrowed by keywords.
     */
    public function query(RecapWindow $window, ?string $keywords = null, ?string $repo = null): string
    {
        $organization = $this->settings->githubOrganization();
        $scope        = 'org:' . $organization;
        $repo         = trim((string) $repo);

        if (1 === preg_match('/^[A-Za-z0-9._-]{1,100}$/', $repo)) {
            $scope = 'repo:' . $organization . '/' . $repo;
        }

        $range = $window->start->utc()->format('Y-m-d\TH:i:s\Z') . '..' . $window->end->utc()->format('Y-m-d\TH:i:s\Z');
        $words = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^\p{L}\p{N}\s._-]/u', ' ', (string) $keywords)));
        $words = mb_substr($words, 0, 100);

        return trim($scope . ' is:pr is:merged merged:' . $range . ' ' . $words);
    }

    /**
     * `owner/name` from an API repository URL.
     */
    private static function repoFrom(string $repositoryUrl): string
    {
        return 1 === preg_match('#/repos/([^/]+/[^/]+)$#', $repositoryUrl, $match) ? $match[1] : '';
    }
}
