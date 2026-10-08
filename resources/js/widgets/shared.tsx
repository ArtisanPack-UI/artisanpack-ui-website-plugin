/**
 * Pieces the dashboard stats widgets share.
 */

import type { ReactNode } from 'react';

import { formatDate } from '../lib/format';

/**
 * Shown when no daily stats run has recorded anything the widget needs.
 */
export function NoSnapshots({ children }: { children?: ReactNode }) {
    return (
        <p className="text-sm text-base-content/60">
            {children ?? 'No stats yet. The daily stats job records them each morning.'}
        </p>
    );
}

/**
 * "As of {date}": the widgets read the daily snapshots, never live figures.
 */
export function AsOf({ date }: { date: string | null }) {
    return date === null ? null : <p className="text-xs text-base-content/55">As of {formatDate(date)}</p>;
}
