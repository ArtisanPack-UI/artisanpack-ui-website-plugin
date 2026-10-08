/**
 * Edit Package → sync status panel, mounted in the sidebar by `../boot.tsx`.
 *
 * Shows when sync last changed the package (`last_synced_at`), when it last
 * checked it (the daily run or "Sync now") and the last error, plus the
 * "Sync now" action, which runs the import, version and icon steps for
 * this package alone.
 *
 * Sync writes to the database, not to this screen's form, so after a sync
 * that changed something the admin is asked to reload: saving the form as
 * it stands would put the old values back.
 */

import { useEffect, useState } from 'react';

import { CARD_CLASS, useSharedEndpoints } from '../components/ui';
import { apiFetch, formatDateTime, packageUrl } from '../lib/http';
import type { PackageSyncStatus, PackageTabProps, SyncReport } from '../lib/types';

export default function PackageSyncPanel({ context }: PackageTabProps) {
    const endpoints = useSharedEndpoints();
    const packageId = context.record.id as number | undefined;
    const statusUrl = endpoints && packageId !== undefined ? packageUrl(endpoints.package.syncStatus, packageId) : null;
    const [status, setStatus] = useState<PackageSyncStatus | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [syncing, setSyncing] = useState(false);
    const [outcome, setOutcome] = useState<{ message: string; changed: boolean } | null>(null);

    // Keyed on the URL string: the shared endpoints object is rebuilt on
    // every Inertia response, including a save of this screen's form.
    useEffect(() => {
        if (statusUrl === null) {
            return;
        }

        apiFetch<{ status: PackageSyncStatus }>(statusUrl)
            .then((response) => setStatus(response.status))
            .catch((error: unknown) => setLoadError(error instanceof Error ? error.message : 'Could not load the sync status.'));
    }, [statusUrl]);

    if (!endpoints || packageId === undefined) {
        return null;
    }

    async function syncNow(url: string) {
        setSyncing(true);
        setOutcome(null);

        try {
            const response = await apiFetch<{ message: string; report: SyncReport; status: PackageSyncStatus }>(url, {
                method: 'POST',
            });

            setStatus(response.status);
            setOutcome({ message: response.message, changed: response.report.created + response.report.updated > 0 });
        } catch (error) {
            setOutcome({ message: error instanceof Error ? error.message : 'Sync failed.', changed: false });
        } finally {
            setSyncing(false);
        }
    }

    return (
        <section className={CARD_CLASS} aria-labelledby="artisanpack-ui-sync-heading">
            <h2 id="artisanpack-ui-sync-heading" className="text-base font-semibold text-base-content">
                Docs site sync
            </h2>

            {loadError !== null && <p className="mt-2 text-sm text-error">{loadError}</p>}

            {status !== null && (
                <dl className="mt-3 space-y-2 text-sm">
                    <div>
                        <dt className="text-xs text-base-content/60">Last synced</dt>
                        <dd className="text-base-content">{formatDateTime(status.lastSyncedAt)}</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-base-content/60">Last checked</dt>
                        <dd className="text-base-content">{formatDateTime(status.lastCheckedAt)}</dd>
                    </div>
                    {status.lastError !== null && (
                        <div role="alert" className="rounded-md border border-error/40 px-3 py-2">
                            <dt className="text-xs font-medium text-error">Last error</dt>
                            <dd className="mt-1 text-xs text-base-content/70" style={{ whiteSpace: 'pre-line' }}>{status.lastError}</dd>
                        </div>
                    )}
                </dl>
            )}

            {status !== null && !status.linked && (
                <p className="mt-2 text-xs text-base-content/60">Not linked to a docs site package yet.</p>
            )}

            <div className="mt-4 space-y-2">
                <button
                    type="button"
                    className="btn btn-outline btn-sm"
                    disabled={syncing}
                    onClick={() => syncNow(packageUrl(endpoints.package.syncNow, packageId))}
                >
                    {syncing ? 'Syncing…' : 'Sync now'}
                </button>
                {outcome !== null && (
                    <div role="status" className="space-y-1 text-xs text-base-content/70">
                        <p>{outcome.message}</p>
                        {outcome.changed && (
                            <p>
                                Sync updated this package. Reload to see the new values; saving the form as it is
                                would put the old ones back.{' '}
                                <button type="button" className="link" onClick={() => window.location.reload()}>
                                    Reload
                                </button>
                            </p>
                        )}
                    </div>
                )}
            </div>
        </section>
    );
}
