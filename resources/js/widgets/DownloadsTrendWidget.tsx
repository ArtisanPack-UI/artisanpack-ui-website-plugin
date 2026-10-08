/**
 * Dashboard widget body: downloads trend chart with a package selector
 * (roadmap 4.3), registered from `../boot.tsx` as
 * `ArtisanPackUIDownloadsTrendWidget`.
 *
 * The payload carries every package's series, so the selector switches
 * in place. The widget's settings pick the package it opens on.
 */

import { useEffect, useId, useState } from 'react';

import { TrendChart } from '../components/TrendChart';
import type { DownloadsTrendData, WidgetProps } from '../lib/types';
import { NoSnapshots } from './shared';

const ALL = 'all';

export default function DownloadsTrendWidget({ data }: WidgetProps<DownloadsTrendData>) {
    const selectId = useId();
    const [selected, setSelected] = useState(data.selected);

    // Follow the settings when they change the package the widget opens on.
    useEffect(() => setSelected(data.selected), [data.selected]);

    const points = data.series[selected] ?? data.series[ALL] ?? [];
    const title =
        selected === ALL ? 'All packages' : (data.packages.find((item) => String(item.id) === selected)?.title ?? 'Package');

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <label htmlFor={selectId} className="text-xs text-base-content/60">
                    Package
                </label>
                <select
                    id={selectId}
                    className="select select-sm"
                    value={selected}
                    onChange={(event) => setSelected(event.target.value)}
                >
                    <option value={ALL}>All packages</option>
                    {data.packages.map((item) => (
                        <option key={item.id} value={String(item.id)}>
                            {item.title}
                        </option>
                    ))}
                </select>
            </div>
            {points.length === 0 || points.every((point) => point.value === null) ? (
                <NoSnapshots>No daily downloads recorded for {title} in the last {data.range} days.</NoSnapshots>
            ) : (
                <TrendChart title={`Daily downloads · ${title}`} points={points} height={140} />
            )}
        </div>
    );
}
