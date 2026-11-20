<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Recap;

use ArtisanPackUI\Site\Exceptions\GitHubException;
use ArtisanPackUI\Site\Exceptions\GitHubRateLimitException;
use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Services\GitHub\GitHubAppClient;
use Carbon\CarbonImmutable;

/**
 * Collects every stable release published inside a {@see RecapWindow},
 * across the GitHub repositories the site's packages name.
 *
 * Drafts and pre-releases are left out. Releases are listed newest first,
 * so each repository's pages are read only until one ends before the
 * window starts. A repository GitHub won't list (renamed, private, gone)
 * becomes a warning rather than failing the recap; a spent rate limit
 * still throws, since every later repository would fail the same way.
 *
 * @phpstan-type RecapRelease array{packageId: int, package: string, repo: string, tag: string, name: string, url: string, publishedAt: string, notes: string}
 *
 * @since 1.6.0
 */
final class RecapReleaseCollector
{
    /**
     * Releases requested per page.
     */
    public const PER_PAGE = 30;

    /**
     * Pages read per repository at most, which covers months of releases.
     */
    public const MAX_PAGES = 5;

    /**
     * The longest release notes kept per release.
     */
    public const NOTES_LIMIT = 4000;

    public function __construct(
        private readonly GitHubAppClient $gitHub,
        private readonly IntegrationSettings $settings,
    ) {}

    /**
     * @return array{releases: list<RecapRelease>, warnings: list<string>}
     *
     * @throws GitHubRateLimitException
     */
    public function collect(RecapWindow $window): array
    {
        $releases = [];
        $warnings = [];

        foreach ($this->repositories() as $repo => $package) {
            try {
                foreach ($this->releasesIn($repo, $window) as $release) {
                    $releases[] = [
                        'packageId' => (int) $package->getKey(),
                        'package'   => (string) $package->title,
                        'repo'      => $repo,
                        ...$release,
                    ];
                }
            } catch (GitHubRateLimitException $exception) {
                throw $exception;
            } catch (GitHubException $exception) {
                $warnings[] = __('Releases for :repo couldn\'t be read: :message', ['repo' => $repo, 'message' => $exception->getMessage()]);
            }
        }

        usort($releases, static fn (array $a, array $b): int => [$a['publishedAt'], $a['repo']] <=> [$b['publishedAt'], $b['repo']]);

        return ['releases' => $releases, 'warnings' => $warnings];
    }

    /**
     * Each repository named by a package, `owner/name` → its package. When
     * two packages name one repository (compared case-insensitively), the
     * first keeps it.
     *
     * @return array<string, Package>
     */
    private function repositories(): array
    {
        $repositories = [];
        $seen         = [];

        Package::query()
            ->whereNotNull('github_repo')
            ->where('github_repo', '!=', '')
            ->orderBy('id')
            ->get(['id', 'title', 'github_repo'])
            ->each(function (Package $package) use (&$repositories, &$seen): void {
                $repo = GitHubRepository::normalize($package->github_repo, $this->settings->githubOrganization());

                if (null === $repo || isset($seen[strtolower($repo)])) {
                    return;
                }

                $seen[strtolower($repo)] = true;
                $repositories[$repo]     = $package;
            });

        return $repositories;
    }

    /**
     * The repository's stable releases inside the window.
     *
     * @return list<array{tag: string, name: string, url: string, publishedAt: string, notes: string}>
     */
    private function releasesIn(string $repo, RecapWindow $window): array
    {
        $found = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = $this->gitHub->rest('GET', '/repos/' . $repo . '/releases', ['per_page' => self::PER_PAGE, 'page' => $page]);
            $items    = is_array($response->data) ? $response->data : [];
            $older    = false;

            foreach ($items as $item) {
                if (! is_array($item) || ! empty($item['draft']) || ! is_string($item['published_at'] ?? null)) {
                    continue;
                }

                $publishedAt = CarbonImmutable::parse($item['published_at'])->setTimezone($window->end->getTimezone());

                if ($publishedAt->lessThan($window->start)) {
                    $older = true;

                    continue;
                }

                if (! empty($item['prerelease']) || ! $window->contains($publishedAt)) {
                    continue;
                }

                $tag     = (string) ($item['tag_name'] ?? '');
                $name    = trim((string) ($item['name'] ?? ''));
                $found[] = [
                    'tag'         => $tag,
                    'name'        => '' === $name ? $tag : $name,
                    'url'         => (string) ($item['html_url'] ?? ''),
                    'publishedAt' => $publishedAt->toIso8601String(),
                    'notes'       => mb_substr(trim((string) ($item['body'] ?? '')), 0, self::NOTES_LIMIT),
                ];
            }

            if ($older || count($items) < self::PER_PAGE) {
                break;
            }
        }

        return $found;
    }
}
