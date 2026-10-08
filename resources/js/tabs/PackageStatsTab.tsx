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
 * Each chart is one series on one axis, so the title names it and there is
 * no legend. Days a source couldn't be read are gaps, never zeroes. Every
 * chart has a crosshair tooltip and a data table for keyboard and
 * screen-reader users.
 */

import { useCallback, useEffect, useState, type KeyboardEvent, type PointerEvent } from 'react';

import { CARD_CLASS, useSharedEndpoints } from '../components/ui';
import { apiFetch, formatDateTime, packageUrl } from '../lib/http';
import type { PackageStatsResponse, PackageTabProps, StatPoint } from '../lib/types';

const NUMBER = new Intl.NumberFormat();

function formatNumber(value: number | null): string {
    return value === null ? '—' : NUMBER.format(value);
}

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
                            <TrendChart key={chart.key} title={chart.title} points={stats.history} metric={chart.key} />
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

/** The chart's drawing box, in SVG user units. */
const WIDTH = 600;
const HEIGHT = 160;
const PAD_Y = 8;

/**
 * A single-series line chart over the snapshot dates, with a crosshair
 * tooltip (pointer or arrow keys) and a collapsible data table.
 */
function TrendChart({ title, points, metric }: { title: string; points: StatPoint[]; metric: keyof Omit<StatPoint, 'date'> }) {
    const [active, setActive] = useState<number | null>(null);
    const [viaKeyboard, setViaKeyboard] = useState(false);
    const values = points.map((point) => point[metric]);
    const known = values.filter((value): value is number => value !== null);
    const max = Math.max(1, ...known);
    const x = (index: number) => (points.length === 1 ? WIDTH / 2 : (index / (points.length - 1)) * WIDTH);
    const y = (value: number) => HEIGHT - PAD_Y - (value / max) * (HEIGHT - PAD_Y * 2);

    // One path segment per run of known values, so a missing day is a gap.
    let path = '';
    values.forEach((value, index) => {
        if (value === null) {
            return;
        }
        const point = `${x(index).toFixed(1)},${y(value).toFixed(1)}`;
        const startsRun = index === 0 || values[index - 1] === null;
        const endsRun = index === values.length - 1 || values[index + 1] === null;

        // A lone value is a zero-length segment, which the round cap draws as a dot.
        path += startsRun ? `M${point}${endsRun ? `L${point}` : ''}` : `L${point}`;
    });

    function pointAt(event: PointerEvent<SVGSVGElement>) {
        const box = event.currentTarget.getBoundingClientRect();
        const ratio = Math.min(1, Math.max(0, (event.clientX - box.left) / box.width));
        setViaKeyboard(false);
        setActive(Math.round(ratio * (points.length - 1)));
    }

    function onKeyDown(event: KeyboardEvent<SVGSVGElement>) {
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            event.preventDefault();
            const step = event.key === 'ArrowLeft' ? -1 : 1;
            setViaKeyboard(true);
            setActive((current) => Math.min(points.length - 1, Math.max(0, (current ?? points.length - 1) + step)));
        }
    }

    const activePoint = active === null ? null : points[active];
    const activeValue = active === null ? null : values[active];

    return (
        <figure className="min-w-0">
            <figcaption className="flex items-baseline justify-between gap-2">
                <span className="text-sm font-medium text-base-content">{title}</span>
                <span className="text-xs text-base-content/55">Peak {formatNumber(known.length > 0 ? Math.max(...known) : null)}</span>
            </figcaption>

            <div className="relative mt-2">
                <svg
                    viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
                    preserveAspectRatio="none"
                    role="img"
                    aria-label={`${title}, ${points[0].date} to ${points[points.length - 1].date}. Use the arrow keys to read values, or open the data table.`}
                    tabIndex={0}
                    className="block w-full"
                    style={{ height: 160, color: 'var(--color-primary)', outlineOffset: 2 }}
                    onPointerMove={pointAt}
                    onPointerLeave={() => setActive(null)}
                    onFocus={() => setActive((current) => current ?? points.length - 1)}
                    onBlur={() => setActive(null)}
                    onKeyDown={onKeyDown}
                >
                    {[0, 0.5, 1].map((fraction) => (
                        <line
                            key={fraction}
                            x1={0}
                            x2={WIDTH}
                            y1={y(max * fraction)}
                            y2={y(max * fraction)}
                            stroke="var(--color-base-content)"
                            strokeOpacity={0.1}
                            strokeWidth={1}
                            vectorEffect="non-scaling-stroke"
                        />
                    ))}
                    <path
                        d={path}
                        fill="none"
                        stroke="currentColor"
                        strokeWidth={2}
                        strokeLinejoin="round"
                        strokeLinecap="round"
                        vectorEffect="non-scaling-stroke"
                    />
                    {active !== null && (
                        <line
                            x1={x(active)}
                            x2={x(active)}
                            y1={0}
                            y2={HEIGHT}
                            stroke="var(--color-base-content)"
                            strokeOpacity={0.35}
                            strokeWidth={1}
                            vectorEffect="non-scaling-stroke"
                        />
                    )}
                </svg>

                {active !== null && activeValue !== null && (
                    <span
                        aria-hidden="true"
                        className="pointer-events-none absolute rounded-full"
                        style={{
                            left: `${(x(active) / WIDTH) * 100}%`,
                            top: y(activeValue),
                            width: 8,
                            height: 8,
                            transform: 'translate(-50%, -50%)',
                            background: 'var(--color-primary)',
                            boxShadow: '0 0 0 2px var(--color-base-100)',
                        }}
                    />
                )}

                {activePoint !== null && (
                    <div
                        aria-hidden="true"
                        className="pointer-events-none absolute top-0 rounded-md border border-base-300/60 bg-base-100 px-2 py-1 text-xs shadow-lg"
                        style={{
                            left: `${(x(active ?? 0) / WIDTH) * 100}%`,
                            transform: (active ?? 0) > points.length / 2 ? 'translateX(calc(-100% - 8px))' : 'translateX(8px)',
                            whiteSpace: 'nowrap',
                        }}
                    >
                        <span className="block text-base-content/60">{new Date(`${activePoint.date}T00:00:00`).toLocaleDateString()}</span>
                        <span className="block font-semibold text-base-content">
                            {activeValue === null ? 'No data' : formatNumber(activeValue)}
                        </span>
                    </div>
                )}
            </div>

            {/* Announces only keyboard reading; pointer hover would flood a screen reader. */}
            <span aria-live="polite" className="sr-only">
                {viaKeyboard && activePoint !== null
                    ? `${new Date(`${activePoint.date}T00:00:00`).toLocaleDateString()}: ${activeValue === null ? 'no data' : formatNumber(activeValue)}`
                    : ''}
            </span>

            <div className="mt-1 flex justify-between text-xs text-base-content/55">
                <span>{new Date(`${points[0].date}T00:00:00`).toLocaleDateString()}</span>
                <span>{new Date(`${points[points.length - 1].date}T00:00:00`).toLocaleDateString()}</span>
            </div>

            <details className="mt-2 text-xs">
                <summary className="cursor-pointer text-base-content/60">Data table</summary>
                <table className="mt-2 w-full text-left">
                    <thead>
                        <tr className="text-base-content/60">
                            <th scope="col" className="py-1 font-medium">Date</th>
                            <th scope="col" className="py-1 text-right font-medium">{title}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {points.map((point) => (
                            <tr key={point.date} className="border-t border-base-300/60">
                                <td className="py-1 text-base-content/70">{point.date}</td>
                                <td className="py-1 text-right text-base-content">{formatNumber(point[metric])}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </details>
        </figure>
    );
}
