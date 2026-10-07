<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Sync;

use ArtisanPackUI\Site\Exceptions\InvalidIconException;
use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Models\PackageSyncState;
use ArtisanPackUI\Site\Services\Docs\DocsPackage;
use ArtisanPackUI\Site\Services\Docs\DocsSiteClient;
use ArtisanPackUI\Site\Support\IconRef;
use ArtisanPackUI\Site\Support\PackageIconSet;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Icon sync (roadmap 2.3): each linked package's icon is pulled from the
 * docs site and stored as an iconRef, so both sites show the same icon.
 *
 * The docs site sends `{raw, set, name, svg}`. Font Awesome icons map to
 * an iconRef as-is. Icons from the docs site's custom `ap` set exist only
 * there, so their sanitized SVG (which the docs site includes for exactly
 * this) is written into {@see PackageIconSet} and the iconRef points at
 * `apui` instead.
 *
 * An icon chosen by hand is never overwritten: the package's icon is only
 * replaced when it is empty or still equals what sync last wrote, which
 * {@see PackageSyncState} remembers. A hand-typed value that isn't a valid
 * iconRef counts as a manual choice too. Custom SVGs are still written to the
 * `apui` set either way, so the picker can offer them.
 *
 * @since 0.3.0
 */
final class PackageIconSync
{
    public function __construct(
        private readonly DocsSiteClient $docs,
        private readonly PackageIconSet $iconSet,
    ) {}

    public function sync(): SyncReport
    {
        $report = new SyncReport;

        foreach ($this->docs->packages() as $docsPackage) {
            $package = Package::query()->where('docs_package_id', $docsPackage->id)->first();

            if (null === $package) {
                continue;
            }

            $this->syncPackage($package, $docsPackage, $report);
        }

        return $report;
    }

    private function syncPackage(Package $package, DocsPackage $docsPackage, SyncReport $report): void
    {
        $label = $package->title ?: $docsPackage->name;

        if (null === $docsPackage->icon) {
            $report->skipped++;

            return;
        }

        try {
            $synced = $this->iconRef($docsPackage->icon);
        } catch (InvalidIconException $exception) {
            $report->fail(__(':package: :message', ['package' => $label, 'message' => $exception->getMessage()]));

            return;
        } catch (Throwable $exception) {
            // A write failure (unwritable storage, say) fails this package,
            // not the whole step.
            report($exception);
            $report->fail(__(':package: the icon couldn\'t be saved.', ['package' => $label]));

            return;
        }

        $state   = PackageSyncState::for($package);
        $raw     = trim((string) $package->getRawOriginal('icon'));
        $current = IconRef::normalize($raw);

        if ($current === $synced) {
            $report->skipped++;
        } elseif ('' === $raw || (null !== $current && $current === IconRef::normalize($state->last_synced_icon))) {
            $package->icon           = $synced;
            $package->last_synced_at = Carbon::now();
            $package->save();
            $report->updated++;
        } else {
            $report->skipped++;
            $report->note(__(':package: kept its manually chosen icon.', ['package' => $label]));

            return;
        }

        $state->last_synced_icon = $synced;
        $state->save();
    }

    /**
     * The iconRef for a docs site icon, writing a custom icon's SVG into
     * the `apui` set first.
     *
     * @param  array{raw: string|null, set: string|null, name: string|null, svg: string|null}  $icon
     *
     * @return array{set: string, name: string}
     *
     * @throws InvalidIconException
     */
    private function iconRef(array $icon): array
    {
        $isCustom = PackageIconSet::DOCS_SET === $icon['set'];
        $ref      = IconRef::normalize([
            'set'  => $isCustom ? PackageIconSet::PREFIX : $icon['set'],
            'name' => $icon['name'],
        ]);

        if (null === $ref) {
            throw new InvalidIconException(__('the docs site icon ":icon" isn\'t a valid icon reference.', ['icon' => (string) ($icon['raw'] ?? '')]));
        }

        if ($isCustom) {
            if (null === $icon['svg']) {
                throw new InvalidIconException(__('the docs site sent no SVG for the custom ":name" icon.', ['name' => $ref['name']]));
            }

            $this->iconSet->write($ref['name'], $icon['svg']);
        }

        return $ref;
    }
}
