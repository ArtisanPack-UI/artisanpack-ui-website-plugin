/**
 * Edit Package → Stats tab — federated remote `artisanpack-ui`, exposed as
 * `./package-stats`, mounted on the `package` edit screen by `../boot.tsx`.
 *
 * KPI tiles, trend charts and the compatibility panel land in roadmap item 4.2.
 */

import { ComingSoon } from '../components/ui';
import type { PackageTabProps } from '../lib/types';

export default function PackageStatsTab({ context }: PackageTabProps) {
    return (
        <ComingSoon title="Stats" roadmapItem="4.2">
            <p className="mt-1 text-xs text-base-content/45">Package #{String(context.record.id ?? '')}</p>
        </ComingSoon>
    );
}
