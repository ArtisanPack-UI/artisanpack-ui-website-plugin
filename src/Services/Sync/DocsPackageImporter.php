<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Sync;

use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Services\Docs\DocsPackage;
use ArtisanPackUI\Site\Services\Docs\DocsSiteClient;
use ArtisanPackUI\Site\Support\PackageFields;
use Illuminate\Support\Carbon;

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
 * @since 0.3.0
 */
final class DocsPackageImporter
{
    public function __construct(private readonly DocsSiteClient $docs) {}

    public function import(): SyncReport
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
