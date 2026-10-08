<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Sync;

use ArtisanPackUI\Site\Exceptions\DocsSiteException;
use ArtisanPackUI\Site\Exceptions\GitHubException;
use ArtisanPackUI\Site\Exceptions\GitHubRateLimitException;
use ArtisanPackUI\Site\Exceptions\RegistryException;
use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Services\Docs\DocsSiteClient;
use ArtisanPackUI\Site\Services\GitHub\GitHubAppClient;
use ArtisanPackUI\Site\Services\Registry\PackageRegistryClient;
use ArtisanPackUI\Site\Support\PackageFields;
use Illuminate\Support\Carbon;

/**
 * Version sync (roadmap 2.2): versions are hand-typed on the docs site and
 * drift, so each package's latest stable release is read from its registry
 * and written to both sites.
 *
 * The version comes from Packagist (`composer_name`) or npm (`npm_name`),
 * per the package's `registry`. When the registry lookup fails or lists no
 * stable release, the latest GitHub release of `github_repo` is used
 * instead, provided the GitHub App is configured.
 *
 * The marketing package's `version` is updated when it differs. When the
 * package is linked to the docs site and the docs site's version differs,
 * the docs package is PATCHed too. A docs site that isn't configured or
 * can't be read only skips that half; the marketing side still syncs.
 *
 * Every package can cost a registry call, a GitHub call and a docs site
 * write, so a run is split into batches of {@see self::BATCH_SIZE}
 * packages, each its own request, to stay well inside PHP's request time
 * limit. The report's `next` is the cursor for the following batch.
 *
 * @since 0.3.0
 */
final class PackageVersionSync
{
    /** Packages per batch. */
    public const BATCH_SIZE = 5;

    /**
     * Set once GitHub rate-limits this run, so the remaining packages skip
     * the GitHub fallback instead of spending more of the quota.
     */
    private bool $gitHubRateLimited = false;

    public function __construct(
        private readonly IntegrationSettings $settings,
        private readonly PackageRegistryClient $registries,
        private readonly DocsSiteClient $docs,
        private readonly GitHubAppClient $github,
    ) {}

    /**
     * Sync one batch: the packages after `$after` (a package id), in id
     * order.
     *
     * @param  int  $after  The previous batch's `next`, or 0 to start.
     */
    public function sync(int $after = 0, int $batchSize = self::BATCH_SIZE): SyncReport
    {
        $report   = new SyncReport;
        $packages = Package::query()->where('id', '>', $after)->orderBy('id')->limit($batchSize + 1)->get();

        if ($packages->isEmpty()) {
            return $report;
        }

        $docsVersions = $this->docsVersions($report);

        foreach ($packages->take($batchSize) as $package) {
            $this->syncPackage($package, $docsVersions, $report);
        }

        if ($packages->count() > $batchSize) {
            $report->next = (int) $packages[$batchSize - 1]->getKey();
        }

        return $report;
    }

    /**
     * Sync one package, for its "Sync now" action.
     */
    public function syncOne(Package $package): SyncReport
    {
        $report = new SyncReport;

        $this->syncPackage($package, $this->docsVersions($report), $report);

        return $report;
    }

    /**
     * @param  array<int, string|null>|null  $docsVersions  Docs package id → version, or null when the docs site is unavailable.
     */
    private function syncPackage(Package $package, ?array $docsVersions, SyncReport $report): void
    {
        $label  = $package->title ?: '#' . $package->id;
        $column = PackageFields::registryNameColumn($package->registry);
        $name   = null === $column ? '' : trim((string) $package->getAttribute($column));
        $repo   = trim((string) $package->github_repo);

        if ('' === $name && '' === $repo) {
            $report->skipped++;
            $report->note(__(':package: skipped, it has no registry name or GitHub repo.', ['package' => $label]));

            return;
        }

        try {
            $version = $this->latestVersion((string) $package->registry, $name, $repo);
        } catch (RegistryException|GitHubException $exception) {
            $report->fail(__(':package: :message', ['package' => $label, 'message' => $exception->getMessage()]), $package->id);

            return;
        }

        if (null === $version) {
            $report->fail(__(':package: no stable release was found.', ['package' => $label]), $package->id);

            return;
        }

        if ($version === $package->version) {
            $report->skipped++;
        } else {
            $package->version        = $version;
            $package->last_synced_at = Carbon::now();
            $package->save();
            $report->updated++;
        }

        $this->syncDocsVersion($package, $version, $docsVersions, $report, $label);
    }

    /**
     * The registry's latest stable version, falling back to the latest
     * GitHub release. A registry failure is only rethrown when there is
     * no fallback to try.
     */
    private function latestVersion(string $registry, string $name, string $repo): ?string
    {
        $registryFailure = null;

        if ('' !== $name) {
            try {
                $version = $this->registries->latestStableVersion($registry, $name);

                if (null !== $version) {
                    return $version;
                }
            } catch (RegistryException $exception) {
                $registryFailure = $exception;
            }
        }

        if ('' === $repo || ! $this->settings->hasGitHubApp() || $this->gitHubRateLimited) {
            if (null !== $registryFailure) {
                throw $registryFailure;
            }

            return null;
        }

        return $this->latestGitHubRelease($repo);
    }

    /**
     * The latest GitHub release's tag as a stable version. GitHub's
     * "latest" release already excludes drafts and pre-releases.
     */
    private function latestGitHubRelease(string $repo): ?string
    {
        if (1 !== preg_match('#^[A-Za-z0-9-]+/[A-Za-z0-9._-]+$#', $repo) || str_contains($repo, '..')) {
            throw new GitHubException(__('":repo" isn\'t a valid GitHub repo (expected owner/name).', ['repo' => $repo]));
        }

        try {
            $response = $this->github->rest('GET', '/repos/' . $repo . '/releases/latest');
        } catch (GitHubRateLimitException $exception) {
            $this->gitHubRateLimited = true;

            throw $exception;
        } catch (GitHubException $exception) {
            if (404 === $exception->status) {
                return null;
            }

            throw $exception;
        }

        return PackageRegistryClient::stable(is_array($response->data) ? ($response->data['tag_name'] ?? null) : null);
    }

    /**
     * @param  array<int, string|null>|null  $docsVersions
     */
    private function syncDocsVersion(Package $package, string $version, ?array $docsVersions, SyncReport $report, string $label): void
    {
        $docsId = $package->docs_package_id;

        if (null === $docsVersions || null === $docsId || ! array_key_exists($docsId, $docsVersions) || $docsVersions[$docsId] === $version) {
            return;
        }

        try {
            $this->docs->updatePackage($docsId, ['version' => $version]);
            $report->docsUpdated++;
        } catch (DocsSiteException $exception) {
            $report->fail(__(':package: the docs site wasn\'t updated. :message', ['package' => $label, 'message' => $exception->getMessage()]), $package->id);
        }
    }

    /**
     * Docs package id → version, or null when the docs site isn't
     * configured or can't be read (noted on the report).
     *
     * @return array<int, string|null>|null
     */
    private function docsVersions(SyncReport $report): ?array
    {
        if (! $this->settings->hasDocsSite()) {
            return null;
        }

        try {
            $docsPackages = $this->docs->packages();
        } catch (DocsSiteException $exception) {
            $report->note(__('Docs site versions weren\'t updated: :message', ['message' => $exception->getMessage()]));

            return null;
        }

        $versions = [];

        foreach ($docsPackages as $docsPackage) {
            $versions[$docsPackage->id] = $docsPackage->version;
        }

        return $versions;
    }
}
