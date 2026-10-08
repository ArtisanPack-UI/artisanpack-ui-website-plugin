/**
 * Filtering and sorting for the boards, and the filter controls the
 * package board and the global board share.
 */

import { useId } from 'react';

import type { BoardItem, GitHubLabel, IssueDetail } from '../../lib/types';

/** The milestone filter's value for cards with no milestone. */
export const NO_MILESTONE = '__none__';

export type BoardSort = 'package' | 'milestone' | 'updated' | 'created';

export const SORT_LABELS: Record<BoardSort, string> = {
    package: 'Package',
    milestone: 'Milestone',
    updated: 'Last updated',
    created: 'Created',
};

export interface BoardFilter {
    /** Package ids to show; empty shows every package. */
    packages: number[];
    /** A milestone title, {@link NO_MILESTONE}, or '' for any. */
    milestone: string;
    /** A label name, or '' for any. */
    label: string;
}

export function filterItems(items: BoardItem[], filter: BoardFilter): BoardItem[] {
    return items.filter(
        (item) =>
            (filter.packages.length === 0 || (item.package !== null && filter.packages.includes(item.package.id))) &&
            (filter.milestone === '' ||
                (filter.milestone === NO_MILESTONE ? item.milestone === null : item.milestone?.title === filter.milestone)) &&
            (filter.label === '' || item.labels.some((label) => label.name === filter.label)),
    );
}

function byText(a: string | null | undefined, b: string | null | undefined): number {
    // Cards missing the field sort last.
    if (!a || !b) {
        return a ? -1 : b ? 1 : 0;
    }

    return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
}

/**
 * A sorted copy: by package or milestone A–Z, or by updated or created
 * date, newest first. Ties fall back to the issue number.
 */
export function sortItems(items: BoardItem[], sort: BoardSort): BoardItem[] {
    const compare = (a: BoardItem, b: BoardItem): number => {
        switch (sort) {
            case 'package':
                return byText(a.package?.title, b.package?.title);
            case 'milestone':
                return byText(a.milestone?.title, b.milestone?.title);
            case 'created':
                return byText(b.createdAt, a.createdAt);
            default:
                return byText(b.updatedAt, a.updatedAt);
        }
    };

    return [...items].sort((a, b) => compare(a, b) || a.number - b.number);
}

/**
 * The card fields the issue modal can change, from its saved issue.
 */
export function itemChangesFrom(issue: IssueDetail): Partial<BoardItem> {
    return {
        title: issue.title,
        state: issue.state,
        labels: issue.labels,
        milestone: issue.milestone,
        assignees: issue.assignees,
        updatedAt: issue.updatedAt,
    };
}

/**
 * The milestone and label selects.
 */
export function MilestoneLabelFilters({
    milestones,
    labels,
    filter,
    onChange,
}: {
    milestones: string[];
    labels: GitHubLabel[];
    filter: BoardFilter;
    onChange: (filter: BoardFilter) => void;
}) {
    const milestoneId = useId();
    const labelId = useId();

    return (
        <>
            <span className="flex items-center gap-2">
                <label htmlFor={milestoneId} className="text-sm text-base-content/70">
                    Milestone
                </label>
                <select
                    id={milestoneId}
                    className="select select-sm"
                    value={filter.milestone}
                    onChange={(event) => onChange({ ...filter, milestone: event.target.value })}
                >
                    <option value="">Any</option>
                    <option value={NO_MILESTONE}>No milestone</option>
                    {milestones.map((title) => (
                        <option key={title} value={title}>
                            {title}
                        </option>
                    ))}
                </select>
            </span>
            <span className="flex items-center gap-2">
                <label htmlFor={labelId} className="text-sm text-base-content/70">
                    Label
                </label>
                <select
                    id={labelId}
                    className="select select-sm"
                    value={filter.label}
                    onChange={(event) => onChange({ ...filter, label: event.target.value })}
                >
                    <option value="">Any</option>
                    {labels.map((label) => (
                        <option key={label.name} value={label.name}>
                            {label.name}
                        </option>
                    ))}
                </select>
            </span>
        </>
    );
}
