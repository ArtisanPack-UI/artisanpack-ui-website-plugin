/**
 * Dashboard widget body: package downloads KPI tile (roadmap 4.3),
 * registered from `../boot.tsx` as `ArtisanPackUIDownloadsKpiWidget`.
 */

import { formatNumber } from '../lib/format';
import type { DownloadsKpiData, WidgetProps } from '../lib/types';
import { AsOf, NoSnapshots } from './shared';

const METRIC_LABELS: Record<DownloadsKpiData['metric'], string> = {
    total: 'Total downloads',
    monthly: 'Monthly downloads',
    daily: 'Daily downloads',
};

export default function DownloadsKpiWidget({ data }: WidgetProps<DownloadsKpiData>) {
    if (data.value === null) {
        return <NoSnapshots />;
    }

    return (
        <div className="space-y-1">
            <p className="text-xs text-base-content/60">
                {METRIC_LABELS[data.metric] ?? METRIC_LABELS.total} · {data.package?.title ?? 'All packages'}
            </p>
            <p className="font-display text-3xl font-semibold text-base-content">{formatNumber(data.value)}</p>
            <AsOf date={data.asOf} />
        </div>
    );
}
