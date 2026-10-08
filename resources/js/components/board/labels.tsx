/**
 * GitHub label chips, coloured as GitHub colours them.
 */

import type { GitHubLabel } from '../../lib/types';

/**
 * Black or white text, whichever reads better on the label's colour.
 */
function textColorFor(hex: string): string {
    const value = /^[0-9a-f]{6}$/i.test(hex) ? hex : 'cccccc';
    const [r, g, b] = [0, 2, 4].map((offset) => parseInt(value.slice(offset, offset + 2), 16) / 255);
    const luminance = 0.2126 * r + 0.7152 * g + 0.0722 * b;

    return luminance > 0.55 ? '#1f2328' : '#ffffff';
}

export function LabelChip({ label }: { label: GitHubLabel }) {
    const color = /^[0-9a-f]{6}$/i.test(label.color) ? `#${label.color}` : 'var(--color-base-300)';

    return (
        <span
            className="inline-block rounded-full px-2 text-xs font-medium"
            style={{ background: color, color: textColorFor(label.color), lineHeight: '1.25rem' }}
        >
            {label.name}
        </span>
    );
}
