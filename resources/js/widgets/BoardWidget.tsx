/**
 * Dashboard widget body: card counts per Status column on the org project,
 * for every package or the ones the widget's settings pick (roadmap 5.5),
 * registered from `../boot.tsx` as `ArtisanPackUIBoardWidget`. Links to
 * the global board with the same packages filtered.
 */

import { formatNumber } from '../lib/format';
import type { BoardWidgetData, WidgetProps } from '../lib/types';

export default function BoardWidget({ data }: WidgetProps<BoardWidgetData>) {
    if (data.error !== null) {
        return <p className="text-sm text-base-content/60">{data.error}</p>;
    }

    // "No status" only earns a row when something is in it, as on GitHub.
    const columns = data.columns.filter((column) => column.id !== null || column.count > 0);
    const scope = data.packages.length === 0 ? 'All packages' : data.packages.map((item) => item.title).join(', ');

    return (
        <div className="space-y-2">
            <p className="truncate text-xs text-base-content/60" title={scope}>
                {scope}
            </p>
            <ul className="space-y-1 text-sm">
                {columns.map((column) => (
                    <li
                        key={column.id ?? 'none'}
                        className="flex items-baseline justify-between gap-3 border-t border-base-300/60 pt-1"
                    >
                        <span className="min-w-0 truncate text-base-content">{column.name}</span>
                        <span className="shrink-0 font-semibold text-base-content">{formatNumber(column.count)}</span>
                    </li>
                ))}
                <li className="flex items-baseline justify-between gap-3 border-t border-base-300 pt-1 font-semibold">
                    <span className="text-base-content">Total</span>
                    <span className="text-base-content">{formatNumber(data.total)}</span>
                </li>
            </ul>
            <a href={data.boardUrl} className="link link-primary text-xs">
                Open the board
            </a>
        </div>
    );
}
