<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Sync;

use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Services\Docs\DocsPackage;
use ArtisanPackUI\Site\Services\Docs\DocsSiteClient;
use ArtisanPackUI\Site\Support\PackageFields;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * "Sync from docs" (roadmap 2.1): the docs site owns which packages exist,
 * so each docs package with no marketing package yet is created here as a
 * **draft**, ready for marketing copy.
 *
 * A docs package is matched to a marketing package by `docs_package_id`.
 * Marketing packages have no slug, so the fallback is the registry name
 * derived from the docs slug (`artisanpack-ui/{slug}` / `@artisanpack-ui/{slug}`)
 * against `composer_name` / `npm_name`, which links packages that were
 * created by hand before sync existed. Only unlinked packages are
 * considered for the fallback, so a package already linked to one docs
 * package is never claimed by another.
 *
 * Existing packages only have their empty sync fields filled in (docs id,
 * registry, registry name, GitHub repo). Title, content and excerpt are
 * never touched, and neither is a sync field an admin already set.
 *
 * Imports hold {@see self::LOCK}, so the daily job, "Sync from docs" and
 * "Sync now" never run one at the same time and create the same docs
 * package twice. A caller that can't get the lock within ten seconds gets a
 * {@see LockTimeoutException}.
 *
 * @since 1.0.0
 */
final class DocsPackageImporter
{
    public const LOCK = 'artisanpack-ui:sync:import';

    /** Seconds the lock is held at most, should an import die holding it. */
    private const LOCK_SECONDS = 300;

    /** Seconds to wait for a running import to finish. */
    private const LOCK_WAIT = 10;

    public function __construct(private readonly DocsSiteClient $docs) {}

    /**
     * @throws LockTimeoutException When another import is still running.
     */
    public function import(): SyncReport
    {
        return Cache::lock(self::LOCK, self::LOCK_SECONDS)->block(self::LOCK_WAIT, fn (): SyncReport => $this->doImport());
    }

    /**
     * Link one marketing package to its docs package and fill its empty
     * sync fields, for its "Sync now" action. A linked package reads its
     * own docs package; an unlinked one is matched by registry name. No
     * package is created.
     *
     * @throws LockTimeoutException When another import is still running.
     */
    public function importOne(Package $package): SyncReport
    {
        return Cache::lock(self::LOCK, self::LOCK_SECONDS)->block(self::LOCK_WAIT, fn (): SyncReport => $this->doImportOne($package));
    }

    private function doImport(): SyncReport
    {
        $report = new SyncReport;

        foreach ($this->docs->packages() as $docsPackage) {
            $package = $this->match($docsPackage);

            if (null === $package) {
                $this->create($docsPackage);
                $report->created++;

                continue;
            }

            $this->fillSyncFields($package, $docsPackage);

            if (! $package->isDirty()) {
                $report->skipped++;

                continue;
            }

            $package->last_synced_at = Carbon::now();
            $package->save();
            $report->updated++;
        }

        return $report;
    }

    private function doImportOne(Package $package): SyncReport
    {
        $report      = new SyncReport;
        $docsPackage = null === $package->docs_package_id
            ? $this->unlinkedMatch($package)
            : $this->docs->package($package->docs_package_id);

        if (null === $docsPackage) {
            $report->skipped++;
            $report->note(__(':package: no docs site package matches it.', ['package' => $package->title ?: '#' . $package->id]));

            return $report;
        }

        $this->fillSyncFields($package, $docsPackage);

        if (! $package->isDirty()) {
            $report->skipped++;

            return $report;
        }

        $package->last_synced_at = Carbon::now();
        $package->save();
        $report->updated++;

        return $report;
    }

    /**
     * The docs package whose registry name is this unlinked package's
     * Composer or npm name, unless another package already links to it.
     */
    private function unlinkedMatch(Package $package): ?DocsPackage
    {
        foreach ($this->docs->packages() as $docsPackage) {
            $column = PackageFields::registryNameColumn($docsPackage->registry);

            if (null === $column || null === $docsPackage->registryName() || $docsPackage->registryName() !== $package->getAttribute($column)) {
                continue;
            }

            return Package::query()->where('docs_package_id', $docsPackage->id)->exists() ? null : $docsPackage;
        }

        return null;
    }

    private function match(DocsPackage $docsPackage): ?Package
    {
        $linked = Package::query()->where('docs_package_id', $docsPackage->id)->first();

        if (null !== $linked) {
            return $linked;
        }

        $registryName = $docsPackage->registryName();
        $column       = PackageFields::registryNameColumn($docsPackage->registry);

        if (null === $registryName || null === $column) {
            return null;
        }

        return Package::query()
            ->whereNull('docs_package_id')
            ->where($column, $registryName)
            ->oldest('id')
            ->first();
    }

    private function create(DocsPackage $docsPackage): void
    {
        $package         = new Package;
        $package->title  = $docsPackage->name;
        $package->status = 'draft';
        $this->fillSyncFields($package, $docsPackage);
        $package->last_synced_at = Carbon::now();
        $package->save();
    }

    /**
     * Set each sync field the package doesn't have yet from the docs
     * package.
     */
    private function fillSyncFields(Package $package, DocsPackage $docsPackage): void
    {
        $values = [
            'docs_package_id' => $docsPackage->id,
            'registry'        => $docsPackage->registry,
            'github_repo'     => $docsPackage->githubRepo(),
        ];

        $column = PackageFields::registryNameColumn($docsPackage->registry);

        if (null !== $column) {
            $values[$column] = $docsPackage->registryName();
        }

        foreach ($values as $key => $value) {
            if (null !== $value && blank($package->getAttribute($key))) {
                $package->setAttribute($key, $value);
            }
        }
    }
}
