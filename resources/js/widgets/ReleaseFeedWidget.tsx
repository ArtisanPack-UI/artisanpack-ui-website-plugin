/**
 * Dashboard widget body: the latest version of each package and its
 * release date (roadmap 4.3), registered from `../boot.tsx` as
 * `ArtisanPackUIReleaseFeedWidget`. A version no snapshot has a release
 * for yet comes from the package's synced version, without a date.
 */

import { formatDate } from '../lib/format';
import type { ReleaseFeedData, WidgetProps } from '../lib/types';
import { NoSnapshots } from './shared';

export default function ReleaseFeedWidget({ data }: WidgetProps<ReleaseFeedData>) {
    if (data.releases.length === 0) {
        return <NoSnapshots>No releases recorded yet.</NoSnapshots>;
    }

    return (
        <ul className="space-y-1 text-sm">
            {data.releases.map((release) => (
                <li key={release.id} className="flex items-baseline justify-between gap-3 border-t border-base-300/60 pt-1">
                    <span className="min-w-0 truncate text-base-content">{release.title}</span>
                    <span className="shrink-0 text-right">
                        <span className="font-mono font-semibold text-base-content">{release.version}</span>
                        {release.releasedAt !== null && (
                            <span className="ml-2 text-xs text-base-content/55">{formatDate(release.releasedAt)}</span>
                        )}
                    </span>
                </li>
            ))}
        </ul>
    );
}
