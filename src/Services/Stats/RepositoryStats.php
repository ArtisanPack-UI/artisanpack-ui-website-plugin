<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Stats;

use Illuminate\Support\Carbon;

/**
 * What GitHub says about a package's repo. `openIssues` excludes pull
 * requests, unlike the REST API's `open_issues_count`.
 *
 * @since 1.0.0
 */
final class RepositoryStats
{
    public function __construct(
        public readonly int $stars,
        public readonly int $forks,
        public readonly int $watchers,
        public readonly int $openIssues,
        public readonly int $openPullRequests,
        public readonly ?string $latestRelease,
        public readonly ?Carbon $latestReleaseAt,
    ) {}
}
