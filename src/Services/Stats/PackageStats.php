<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Stats;

use Illuminate\Support\Carbon;

/**
 * One package's stats as collected right now: the registry half, the
 * GitHub half (either may be missing) and why any half couldn't be read.
 *
 * @since 1.0.0
 */
final class PackageStats
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly ?RegistryStats $registry,
        public readonly ?RepositoryStats $repository,
        public readonly array $errors,
        public readonly Carbon $collectedAt,
    ) {}

    /**
     * Whether either half was read.
     */
    public function hasData(): bool
    {
        return null !== $this->registry || null !== $this->repository;
    }

    /**
     * The latest release, from the registry, falling back to GitHub.
     */
    public function latestRelease(): ?string
    {
        return $this->registry?->latestRelease ?? $this->repository?->latestRelease;
    }

    public function latestReleaseAt(): ?Carbon
    {
        return null !== $this->registry?->latestRelease
            ? $this->registry->latestReleaseAt
            : $this->repository?->latestReleaseAt;
    }

    /**
     * The live figures and compatibility for the Stats tab. Figures a
     * source didn't provide are null, never zero.
     *
     * @return array{live: array<string, mixed>, compatibility: array{registry: string, requires: array<string, string>, dependents: int|null}|null, errors: list<string>, collectedAt: string}
     */
    public function toArray(): array
    {
        return [
            'live' => [
                'downloads' => [
                    'daily'   => $this->registry?->downloadsDaily,
                    'monthly' => $this->registry?->downloadsMonthly,
                    'total'   => $this->registry?->downloadsTotal,
                ],
                'stars'            => $this->repository?->stars,
                'forks'            => $this->repository?->forks,
                'watchers'         => $this->repository?->watchers,
                'openIssues'       => $this->repository?->openIssues,
                'openPullRequests' => $this->repository?->openPullRequests,
                'latestRelease'    => [
                    'version'    => $this->latestRelease(),
                    'releasedAt' => $this->latestReleaseAt()?->toIso8601String(),
                ],
            ],
            'compatibility' => null === $this->registry ? null : [
                'registry'   => $this->registry->registry,
                'requires'   => $this->registry->requires,
                'dependents' => $this->registry->dependents,
            ],
            'errors'      => $this->errors,
            'collectedAt' => $this->collectedAt->toIso8601String(),
        ];
    }

    /**
     * The columns of a {@see \ArtisanPackUI\Site\Models\PackageStatSnapshot}.
     *
     * @return array{downloads_daily: int|null, downloads_monthly: int|null, downloads_total: int|null, stars: int|null, forks: int|null, watchers: int|null, open_issues: int|null, open_prs: int|null, dependents: int|null, latest_release: string|null, latest_release_at: Carbon|null}
     */
    public function toSnapshotAttributes(): array
    {
        return [
            'downloads_daily'   => $this->registry?->downloadsDaily,
            'downloads_monthly' => $this->registry?->downloadsMonthly,
            'downloads_total'   => $this->registry?->downloadsTotal,
            'stars'             => $this->repository?->stars,
            'forks'             => $this->repository?->forks,
            'watchers'          => $this->repository?->watchers,
            'open_issues'       => $this->repository?->openIssues,
            'open_prs'          => $this->repository?->openPullRequests,
            'dependents'        => $this->registry?->dependents,
            'latest_release'    => $this->latestRelease(),
            'latest_release_at' => $this->latestReleaseAt(),
        ];
    }
}
