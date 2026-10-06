/**
 * Edit Package → Docs tab — federated remote `artisanpack-ui`, exposed as
 * `./package-docs`, mounted on the `package` edit screen by `../boot.tsx`.
 *
 * Import buttons for the package's docs and changelog, plus the docs
 * reorder tree, land in roadmap items 3.1 and 3.2.
 */

import { ComingSoon } from '../components/ui';
import type { PackageTabProps } from '../lib/types';

export default function PackageDocsTab({ context }: PackageTabProps) {
    return (
        <ComingSoon title="Docs" roadmapItem="3.1–3.2">
            <p className="mt-1 text-xs text-base-content/45">Package #{String(context.record.id ?? '')}</p>
        </ComingSoon>
    );
}
