<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Sync;

use ArtisanPackUI\Site\Exceptions\DocsSiteException;
use ArtisanPackUI\Site\Exceptions\IntegrationNotConfiguredException;
use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Models\PackageSyncState;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;

/**
 * Scheduled sync and "Sync now" (roadmap 2.4): runs the import, version and
 * icon steps back to back, then records on each package's
 * {@see PackageSyncState} when it was checked and what failed, which the
 * edit screen's sync status panel shows.
 *
 * A step that can't start (the docs site isn't configured or can't be
 * read) doesn't stop the others: the version step still syncs from the
 * registries. Its error is recorded against every package the run covers,
 * because none of them were fully synced.
 *
 * @since 0.4.0
 */
final class PackageSyncRunner
{
    public function __construct(
        private readonly DocsPackageImporter $importer,
        private readonly PackageVersionSync $versions,
        private readonly PackageIconSync $icons,
    ) {}

    /**
     * Sync every package (the daily job). The version step runs every
     * batch in turn, since a queued job has no request time limit to stay
     * inside.
     *
     * @return array{report: SyncReport, errors: list<string>}
     */
    public function runAll(): array
    {
        $run = $this->run([
            __('Import')   => fn (): SyncReport => $this->importer->import(),
            __('Versions') => function (): SyncReport {
                $report = new SyncReport;

                do {
                    $report->merge($this->versions->sync($report->next ?? 0));
                } while (null !== $report->next);

                return $report;
            },
            __('Icons') => fn (): SyncReport => $this->icons->sync(),
        ]);

        foreach (Package::query()->lazyById() as $package) {
            $this->record($package, $run);
        }

        return $run;
    }

    /**
     * Sync one package (its "Sync now" action).
     *
     * @return array{report: SyncReport, errors: list<string>}
     */
    public function runOne(Package $package): array
    {
        $run = $this->run([
            __('Import')   => fn (): SyncReport => $this->importer->importOne($package),
            __('Versions') => fn (): SyncReport => $this->versions->syncOne($package),
            __('Icons')    => fn (): SyncReport => $this->icons->syncOne($package),
        ]);

        $this->record($package, $run);

        return $run;
    }

    /**
     * @param  array<string, Closure(): SyncReport>  $steps  Step label → step.
     *
     * @return array{report: SyncReport, errors: list<string>}
     */
    private function run(array $steps): array
    {
        $report = new SyncReport;
        $errors = [];

        foreach ($steps as $label => $step) {
            try {
                $report->merge($step());
            } catch (IntegrationNotConfiguredException|DocsSiteException $exception) {
                $errors[] = __(':step: :message', ['step' => $label, 'message' => $exception->getMessage()]);
            } catch (LockTimeoutException) {
                $errors[] = __(':step: :message', ['step' => $label, 'message' => __('Another sync was still running, so this step was skipped.')]);
            }
        }

        $report->next = null;

        return ['report' => $report, 'errors' => $errors];
    }

    /**
     * @param  array{report: SyncReport, errors: list<string>}  $run
     */
    private function record(Package $package, array $run): void
    {
        PackageSyncState::for($package)->recordCheck([
            ...$run['errors'],
            ...$run['report']->failuresFor((int) $package->getKey()),
        ]);
    }
}
