/**
 * "Sync from docs" on the Packages list (`/admin/content/package`).
 *
 * The list page has no slot for header actions, so the boot module puts
 * this in the admin top bar (`keystone.admin.topbar.right`) and it renders
 * only on that page. A host `index.actions` hook would be the proper home.
 *
 * One click runs the three sync steps in turn: import new docs packages as
 * drafts, sync versions, then sync icons. The version step comes back in
 * batches (`next` is the cursor for the following one), which are repeated
 * and added up. A step that can't start stops the run; per-package
 * problems are listed under the counts. The list reloads afterwards so new
 * drafts show up.
 */

import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { useAbilities, useSharedEndpoints } from './ui';
import { apiFetch } from '../lib/http';
import type { SharedEndpoints, SyncReport } from '../lib/types';

/** The Packages list path, without a query string. */
const PACKAGES_LIST_PATH = '/admin/content/package';

interface Step {
    key: keyof Pick<SharedEndpoints, 'syncImport' | 'syncVersions' | 'syncIcons'>;
    label: string;
}

const STEPS: Step[] = [
    { key: 'syncImport', label: 'Import' },
    { key: 'syncVersions', label: 'Versions' },
    { key: 'syncIcons', label: 'Icons' },
];

type StepResult = { label: string; report: SyncReport } | { label: string; error: string };

/**
 * Run one step to completion, following `next` through its batches and
 * adding the batches' counts together.
 */
async function runStep(url: string): Promise<SyncReport> {
    let total: SyncReport | null = null;
    let after: number | null = 0;

    while (after !== null) {
        const batchUrl = new URL(url, window.location.origin);
        if (after > 0) {
            batchUrl.searchParams.set('after', String(after));
        }

        const { report }: { report: SyncReport } = await apiFetch(batchUrl.toString(), { method: 'POST' });

        total =
            total === null
                ? report
                : {
                      created: total.created + report.created,
                      updated: total.updated + report.updated,
                      skipped: total.skipped + report.skipped,
                      failed: total.failed + report.failed,
                      docsUpdated: total.docsUpdated + report.docsUpdated,
                      next: report.next,
                      messages: [...total.messages, ...report.messages],
                  };
        after = report.next;
    }

    return total as SyncReport;
}

function summary(report: SyncReport): string {
    const parts = [
        report.created > 0 && `${report.created} created`,
        `${report.updated} updated`,
        report.docsUpdated > 0 && `${report.docsUpdated} docs updated`,
        `${report.skipped} skipped`,
        report.failed > 0 && `${report.failed} failed`,
    ];

    return parts.filter(Boolean).join(', ');
}

export function SyncFromDocsButton() {
    const { url } = usePage();
    const can = useAbilities();
    const endpoints = useSharedEndpoints();
    const [running, setRunning] = useState<string | null>(null);
    const [results, setResults] = useState<StepResult[] | null>(null);

    if (url.split('?')[0] !== PACKAGES_LIST_PATH || !can.sync || endpoints === null) {
        return null;
    }

    async function run(urls: SharedEndpoints) {
        const collected: StepResult[] = [];
        setResults(collected);

        for (const step of STEPS) {
            setRunning(step.label);

            try {
                collected.push({ label: step.label, report: await runStep(urls[step.key]) });
            } catch (error) {
                collected.push({ label: step.label, error: error instanceof Error ? error.message : 'The step failed.' });
                setResults([...collected]);
                break;
            }

            setResults([...collected]);
        }

        setRunning(null);
        router.reload();
    }

    return (
        <div className="relative">
            <button
                type="button"
                className="btn btn-outline btn-sm"
                disabled={running !== null}
                onClick={() => run(endpoints)}
            >
                {running ? `Syncing ${running.toLowerCase()}…` : 'Sync from docs'}
            </button>

            {results !== null && running === null && (
                <div
                    role="status"
                    className="absolute right-0 z-50 mt-2 w-80 space-y-2 rounded-lg border border-base-300/60 bg-base-100 p-4 text-sm shadow-lg"
                >
                    <div className="flex items-center justify-between">
                        <p className="font-semibold text-base-content">Sync from docs</p>
                        <button type="button" className="btn btn-ghost btn-xs" onClick={() => setResults(null)}>
                            Dismiss
                        </button>
                    </div>
                    <ul className="space-y-2">
                        {results.map((result) => (
                            <li key={result.label}>
                                <span className="font-medium text-base-content">{result.label}: </span>
                                {'error' in result ? (
                                    <span className="text-error">{result.error}</span>
                                ) : (
                                    <span className="text-base-content/70">{summary(result.report)}</span>
                                )}
                                {'report' in result && result.report.messages.length > 0 && (
                                    <ul className="mt-1 list-disc space-y-1 pl-5 text-xs text-base-content/60">
                                        {result.report.messages.map((message, index) => (
                                            <li key={index}>{message}</li>
                                        ))}
                                    </ul>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
