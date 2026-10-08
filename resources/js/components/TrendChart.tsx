/**
 * The plugin's trend chart, shared by the Edit Package Stats tab and the
 * dashboard's downloads trend widget.
 *
 * Each chart is one series on one axis, so the title names it and there is
 * no legend. Every chart has a crosshair tooltip and a data table for
 * keyboard and screen-reader users.
 */

import { useState, type KeyboardEvent, type PointerEvent } from 'react';

import { formatNumber } from '../lib/format';

/** One day on a trend chart. Null means there's no figure for that day. */
export interface TrendPoint {
    date: string;
    value: number | null;
}

/** The chart's drawing box, in SVG user units. */
const WIDTH = 600;
const HEIGHT = 160;
const PAD_Y = 8;

/**
 * A single-series line chart over the snapshot dates, with a crosshair
 * tooltip (pointer or arrow keys) and a collapsible data table. Null
 * values are gaps, never zeroes. `height` is the drawing's CSS height.
 */
export function TrendChart({ title, points, height = HEIGHT }: { title: string; points: TrendPoint[]; height?: number }) {
    const [active, setActive] = useState<number | null>(null);
    const [viaKeyboard, setViaKeyboard] = useState(false);
    const values = points.map((point) => point.value);
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
                    style={{ height, color: 'var(--color-primary)', outlineOffset: 2 }}
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
                            top: (y(activeValue) / HEIGHT) * height,
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
                                <td className="py-1 text-right text-base-content">{formatNumber(point.value)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </details>
        </figure>
    );
}
