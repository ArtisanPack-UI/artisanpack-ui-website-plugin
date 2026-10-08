/**
 * Dashboard widget body: packages ranked by downloads gained or growth
 * over a window (roadmap 4.3), registered from `../boot.tsx` as
 * `ArtisanPackUITopPackagesWidget`.
 */

import { formatNumber } from '../lib/format';
import type { TopPackagesData, WidgetProps } from '../lib/types';
import { NoSnapshots } from './shared';

function formatGrowth(growth: number | null): string {
    return growth === null ? '—' : `${growth > 0 ? '+' : ''}${growth.toFixed(1)}%`;
}

export default function TopPackagesWidget({ data }: WidgetProps<TopPackagesData>) {
    if (data.packages.length === 0) {
        return <NoSnapshots>Not enough daily stats yet to rank packages. Rankings need two days of snapshots.</NoSnapshots>;
    }

    const byGrowth = data.rankBy === 'growth';

    return (
        <div className="space-y-2">
            <p className="text-xs text-base-content/60">
                By {byGrowth ? 'growth' : 'downloads gained'}, last {data.window} days
            </p>
            <ol className="space-y-1 text-sm">
                {data.packages.map((row, index) => (
                    <li key={row.id} className="flex items-baseline justify-between gap-3 border-t border-base-300/60 pt-1">
                        <span className="min-w-0 truncate text-base-content">
                            <span className="text-base-content/55">{index + 1}.</span> {row.title}
                        </span>
                        <span className="shrink-0 text-right">
                            <span className="font-semibold text-base-content">
                                {byGrowth ? formatGrowth(row.growth) : formatNumber(row.downloads)}
                            </span>
                            <span className="ml-2 text-xs text-base-content/55">
                                {byGrowth ? `${formatNumber(row.downloads)} dl` : formatGrowth(row.growth)}
                            </span>
                        </span>
                    </li>
                ))}
            </ol>
        </div>
    );
}
