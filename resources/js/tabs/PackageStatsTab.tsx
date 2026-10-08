/**
 * Edit Package → Stats tab — federated remote `artisanpack-ui`, exposed as
 * `./package-stats`, mounted on the `package` edit screen by `../boot.tsx`.
 *
 * Roadmap 4.2: live KPI tiles (downloads, stars, forks, open issues and
 * PRs, latest release), trend charts drawn from the daily snapshots over a
 * chosen range, and a compatibility panel with the latest release's
 * PHP/Laravel requirements from Packagist (peer dependencies for npm) and
 * the dependents count.
 *
 * Days a source couldn't be read are gaps, never zeroes (see
 * `../components/TrendChart`).
 */

import { useCallback, useEffect, useState } from 'react';

import { TrendChart } from '../components/TrendChart';
import { CARD_CLASS, useSharedEndpoints } from '../components/ui';
import { formatNumber } from '../lib/format';
import { apiFetch, formatDateTime, packageUrl } from '../lib/http';
import type { PackageStatsResponse, PackageTabProps, StatPoint } from '../lib/types';

const RANGE_LABELS: Record<number, string> = { 30: '30 days', 90: '90 days', 365: '1 year' };

const CHARTS: { key: keyof Omit<StatPoint, 'date'>; title: string }[] = [
    { key: 'downloadsDaily', title: 'Daily downloads' },
    { key: 'stars', title: 'Stars' },
    { key: 'forks', title: 'Forks' },
    { key: 'openIssues', title: 'Open issues' },
];

export default function PackageStatsTab({ context }: PackageTabProps) {
    const endpoints = useSharedEndpoints();
    const packageId = context.record.id as number | undefined;
    const [range, setRange] = useState<number | null>(null);
    const [stats, setStats] = useState<PackageStatsResponse | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    const load = useCallback(
        async (template: string, id: number, selected: number | null) => {
            setLoading(true);

            try {
                const url = new URL(packageUrl(template, id), window.location.origin);
                if (selected !== null) {
                    url.searchParams.set('range', String(selected));
                }

                const response = await apiFetch<PackageStatsResponse>(url.toString());
                setStats(response);
                setRange(response.range);
                setError(null);
            } catch (loadError) {
                setError(loadError instanceof Error ? loadError.message : 'Could not load the stats.');
            } finally {
                setLoading(false);
            }
        },
        [],
    );

    // Keyed on the template string: the shared endpoints object is rebuilt
    // on every Inertia response, including a save of this screen's form.
    const statsTemplate = endpoints?.package.stats ?? null;

    useEffect(() => {
        if (statsTemplate !== null && packageId !== undefined) {
            void load(statsTemplate, packageId, null);
        }
    }, [statsTemplate, packageId, load]);

    if (!endpoints || packageId === undefined) {
        return null;
    }

    if (stats === null) {
        return (
            <section className={CARD_CLASS}>
                {error !== null ? (
                    <p className="text-sm text-error">{error}</p>
                ) : (
                    <p className="animate-pulse text-sm text-base-content/55">Loading stats…</p>
                )}
            </section>
        );
    }

    const { live } = stats;
    const tiles: { label: string; value: string; detail?: string }[] = [
        { label: 'Total downloads', value: formatNumber(live.downloads.total) },
        { label: 'Monthly downloads', value: formatNumber(live.downloads.monthly) },
        { label: 'Daily downloads', value: formatNumber(live.downloads.daily) },
        { label: 'Stars', value: formatNumber(live.stars) },
        { label: 'Forks', value: formatNumber(live.forks) },
        { label: 'Open issues', value: formatNumber(live.openIssues) },
        { label: 'Open PRs', value: formatNumber(live.openPullRequests) },
        {
            label: 'Latest release',
            value: live.latestRelease.version ?? '—',
            detail: live.latestRelease.releasedAt ? new Date(live.latestRelease.releasedAt).toLocaleDateString() : undefined,
        },
    ];

    return (
        <div className="space-y-4">
            {stats.errors.length > 0 && (
                <div role="alert" className="rounded-md border border-error/40 px-3 py-2 text-sm">
                    <p className="font-medium text-error">Some stats couldn't be read.</p>
                    <ul className="mt-1 list-disc space-y-1 pl-5 text-xs text-base-content/70">
                        {stats.errors.map((message) => (
                            <li key={message}>{message}</li>
                        ))}
                    </ul>
                </div>
            )}

            <section className={CARD_CLASS} aria-labelledby="artisanpack-ui-kpis-heading">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="artisanpack-ui-kpis-heading" className="text-base font-semibold text-base-content">
                        Right now
                    </h2>
                    <p className="text-xs text-base-content/55">As of {formatDateTime(stats.collectedAt)}</p>
                </div>
                <dl className="mt-4 grid grid-cols-2 gap-4 md:grid-cols-4">
                    {tiles.map((tile) => (
                        <div key={tile.label} className="rounded-md border border-base-300/60 px-3 py-2">
                            <dt className="text-xs text-base-content/60">{tile.label}</dt>
                            <dd className="mt-1 text-xl font-semibold text-base-content">{tile.value}</dd>
                            {tile.detail && <dd className="text-xs text-base-content/55">{tile.detail}</dd>}
                        </div>
                    ))}
                </dl>
            </section>

            <section className={CARD_CLASS} aria-labelledby="artisanpack-ui-trends-heading">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="artisanpack-ui-trends-heading" className="text-base font-semibold text-base-content">
                        Trends
                    </h2>
                    <div role="group" aria-label="Range" className="flex gap-1">
                        {stats.ranges.map((option) => (
                            <button
                                key={option}
                                type="button"
                                aria-pressed={range === option}
                                disabled={loading}
                                className={`btn btn-xs ${range === option ? 'btn-primary' : 'btn-ghost'}`}
                                onClick={() => load(endpoints.package.stats, packageId, option)}
                            >
                                {RANGE_LABELS[option] ?? `${option} days`}
                            </button>
                        ))}
                    </div>
                </div>

                {stats.history.length === 0 ? (
                    <p className="mt-4 text-sm text-base-content/60">
                        No snapshots yet. The daily stats job records one per day, so trends appear from tomorrow.
                    </p>
                ) : (
                    <div className="mt-4 grid gap-6 md:grid-cols-2">
                        {CHARTS.map((chart) => (
                            <TrendChart
                                key={chart.key}
                                title={chart.title}
                                points={stats.history.map((point) => ({ date: point.date, value: point[chart.key] }))}
                            />
                        ))}
                    </div>
                )}
                {error !== null && <p className="mt-3 text-sm text-error">{error}</p>}
            </section>

            <section className={CARD_CLASS} aria-labelledby="artisanpack-ui-compat-heading">
                <h2 id="artisanpack-ui-compat-heading" className="text-base font-semibold text-base-content">
                    Compatibility
                </h2>
                {stats.compatibility === null ? (
                    <p className="mt-2 text-sm text-base-content/60">
                        The registry couldn't be read, or the package has no registry name.
                    </p>
                ) : (
                    <>
                        <p className="mt-1 text-sm text-base-content/60">
                            {stats.compatibility.registry === 'npm'
                                ? 'Peer dependencies of the latest release on npm.'
                                : 'Requirements of the latest release on Packagist.'}
                        </p>
                        {Object.keys(stats.compatibility.requires).length === 0 ? (
                            <p className="mt-3 text-sm text-base-content/60">None declared.</p>
                        ) : (
                            <dl className="mt-3 divide-y divide-base-300/60">
                                {Object.entries(stats.compatibility.requires).map(([dependency, constraint]) => (
                                    <div key={dependency} className="flex justify-between gap-4 py-2 text-sm">
                                        <dt className="font-mono text-base-content">{dependency}</dt>
                                        <dd className="font-mono text-base-content/70">{constraint}</dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                        <p className="mt-3 text-sm text-base-content/70">
                            Dependents: {stats.compatibility.dependents === null ? 'not available on npm' : formatNumber(stats.compatibility.dependents)}
                        </p>
                    </>
                )}
            </section>
        </div>
    );
}
