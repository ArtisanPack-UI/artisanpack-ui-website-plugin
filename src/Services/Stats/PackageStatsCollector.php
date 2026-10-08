<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Stats;

use ArtisanPackUI\Site\Exceptions\GitHubException;
use ArtisanPackUI\Site\Exceptions\GitHubRateLimitException;
use ArtisanPackUI\Site\Exceptions\RegistryException;
use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Services\GitHub\GitHubAppClient;
use ArtisanPackUI\Site\Services\Registry\PackageRegistryClient;
use ArtisanPackUI\Site\Support\PackageFields;
use Illuminate\Support\Carbon;

/**
 * Reads a package's current stats (roadmap 4.1–4.2): downloads,
 * dependents, the latest release and its requirements from Packagist or
 * npm, and stars, forks, watchers, open issues and open PRs from GitHub in
 * one GraphQL query.
 *
 * The two halves fail independently: a registry outage still leaves the
 * GitHub numbers, and each failure is reported in
 * {@see PackageStats::$errors}. Once GitHub rate-limits a collector, later
 * packages skip GitHub rather than spend more of the quota.
 *
 * @since 1.0.0
 */
class PackageStatsCollector
{
    private const REPOSITORY_QUERY = <<<'GRAPHQL'
        query ($owner: String!, $name: String!) {
          repository(owner: $owner, name: $name) {
            stargazerCount
            forkCount
            watchers { totalCount }
            issues(states: OPEN) { totalCount }
            pullRequests(states: OPEN) { totalCount }
            latestRelease { tagName publishedAt }
          }
        }
        GRAPHQL;

    private bool $gitHubRateLimited = false;

    public function __construct(
        private readonly IntegrationSettings $settings,
        private readonly PackageRegistryClient $registries,
        private readonly GitHubAppClient $github,
    ) {}

    public function collect(Package $package): PackageStats
    {
        $errors     = [];
        $registry   = null;
        $repository = null;
        $column     = PackageFields::registryNameColumn($package->registry);
        $name       = null === $column ? '' : trim((string) $package->getAttribute($column));
        $repo       = trim((string) $package->github_repo);

        if ('' !== $name) {
            try {
                $registry = $this->registries->stats((string) $package->registry, $name);
            } catch (RegistryException $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        if ('' !== $repo) {
            try {
                $repository = $this->repository($repo);
            } catch (GitHubException $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        if ('' === $name && '' === $repo) {
            $errors[] = __('The package has no registry name or GitHub repo to read stats from.');
        }

        return new PackageStats($registry, $repository, $errors, Carbon::now());
    }

    /**
     * @throws GitHubException
     */
    private function repository(string $repo): ?RepositoryStats
    {
        if (1 !== preg_match('#^([A-Za-z0-9-]+)/([A-Za-z0-9._-]+)$#', $repo, $matches) || str_contains($repo, '..')) {
            throw new GitHubException(__('":repo" isn\'t a valid GitHub repo (expected owner/name).', ['repo' => $repo]));
        }

        if (! $this->settings->hasGitHubApp()) {
            throw new GitHubException(__('GitHub stats need the GitHub App, which isn\'t configured yet.'));
        }

        if ($this->gitHubRateLimited) {
            throw new GitHubException(__('GitHub stats were skipped: the GitHub API rate limit was hit earlier in this run.'));
        }

        try {
            $data = $this->github->graphql(self::REPOSITORY_QUERY, ['owner' => $matches[1], 'name' => $matches[2]])->data;
        } catch (GitHubRateLimitException $exception) {
            $this->gitHubRateLimited = true;

            throw $exception;
        }

        $repository = is_array($data) && is_array($data['repository'] ?? null) ? $data['repository'] : null;

        if (null === $repository) {
            throw new GitHubException(__('GitHub couldn\'t find the ":repo" repo, or the App can\'t see it.', ['repo' => $repo]), 404);
        }

        $release = is_array($repository['latestRelease'] ?? null) ? $repository['latestRelease'] : [];

        return new RepositoryStats(
            stars: (int) ($repository['stargazerCount'] ?? 0),
            forks: (int) ($repository['forkCount'] ?? 0),
            watchers: (int) ($repository['watchers']['totalCount'] ?? 0),
            openIssues: (int) ($repository['issues']['totalCount'] ?? 0),
            openPullRequests: (int) ($repository['pullRequests']['totalCount'] ?? 0),
            latestRelease: PackageRegistryClient::stable($release['tagName'] ?? null),
            latestReleaseAt: is_string($release['publishedAt'] ?? null) ? Carbon::parse($release['publishedAt']) : null,
        );
    }
}
