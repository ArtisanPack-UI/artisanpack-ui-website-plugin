/**
 * Edit Package → Issues tab — federated remote `artisanpack-ui`, exposed as
 * `./package-issues`, mounted on the `package` edit screen by `../boot.tsx`.
 *
 * The package-scoped kanban board lands in roadmap item 5.2.
 */

import { ComingSoon } from '../components/ui';
import type { PackageTabProps } from '../lib/types';

export default function PackageIssuesTab({ context }: PackageTabProps) {
    return (
        <ComingSoon title="Issues" roadmapItem="5.2">
            <p className="mt-1 text-xs text-base-content/45">Package #{String(context.record.id ?? '')}</p>
        </ComingSoon>
    );
}
