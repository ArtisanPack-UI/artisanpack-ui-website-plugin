/**
 * Packages board — federated remote `artisanpack-ui`, exposed as
 * `./packages-board`, resolved by the host at
 * `plugins/artisanpack-ui/packages-board`.
 *
 * The plugin's landing page at `/admin/artisanpack-ui`, open to anyone with
 * a plugin permission. The board itself needs `artisanpack-ui.issues.manage`.
 *
 * Roadmap 5.4: every package's issues on the org GitHub Project. "Group by
 * package" splits the board into one swimlane per package across the shared
 * Status columns; ungrouped, cards in each column sort by package,
 * milestone, last updated or created. Filters narrow by package, milestone
 * and label. The grouping, sort and filters are remembered per user in this
 * browser; a `?packages=` link (the dashboard board widget's, 5.5) replaces
 * the saved filters with that package filter. Dragging a card changes only
 * its Status, never its package.
 * Reuses the package board and issue modal (5.2, 5.3).
 */

import { usePage } from '@inertiajs/react';
import { useEffect, useId, useMemo, useState } from 'react';

import { BoardView } from '../components/board/BoardView';
import {
    filterItems,
    MilestoneLabelFilters,
    SORT_LABELS,
    sortItems,
    type BoardFilter,
    type BoardSort,
} from '../components/board/filters';
import type { BoardLane } from '../components/board/KanbanBoard';
import { useBoard } from '../components/board/useBoard';
import { NoAccess, PluginPage, useSharedEndpoints } from '../components/ui';
import type { BoardItem, PluginPageProps } from '../lib/types';

interface BoardPreferences extends BoardFilter {
    grouped: boolean;
    sort: BoardSort;
}

const DEFAULT_PREFERENCES: BoardPreferences = { grouped: false, sort: 'updated', packages: [], milestone: '', label: '' };

/**
 * The saved preferences, ignoring anything malformed. Storage can be
 * unavailable (private windows, blocked site data), so every access is
 * guarded and the board works without it.
 */
function loadPreferences(key: string): BoardPreferences {
    try {
        const stored = JSON.parse(window.localStorage.getItem(key) ?? 'null') as Partial<BoardPreferences> | null;

        if (stored === null || typeof stored !== 'object') {
            return DEFAULT_PREFERENCES;
        }

        const grouped = stored.grouped === true;
        const sort = typeof stored.sort === 'string' && stored.sort in SORT_LABELS ? stored.sort : DEFAULT_PREFERENCES.sort;

        return {
            grouped,
            sort: grouped && sort === 'package' ? DEFAULT_PREFERENCES.sort : sort,
            packages: Array.isArray(stored.packages) ? stored.packages.filter((id): id is number => Number.isInteger(id)) : [],
            milestone: typeof stored.milestone === 'string' ? stored.milestone : '',
            label: typeof stored.label === 'string' ? stored.label : '',
        };
    } catch {
        return DEFAULT_PREFERENCES;
    }
}

/**
 * The package filter a link asked for with `?packages=1,2` (the dashboard
 * board widget's link), or null when the URL names none.
 */
function linkedPackages(): number[] | null {
    try {
        const value = new URLSearchParams(window.location.search).get('packages');

        if (value === null) {
            return null;
        }

        return value
            .split(',')
            .map((id) => Number(id))
            .filter((id) => Number.isInteger(id) && id > 0);
    } catch {
        return null;
    }
}

/**
 * Drop `?packages=` once it's applied, so a reload keeps whatever the
 * filters were changed to rather than reapplying the link's.
 */
function forgetLinkedPackages(): void {
    try {
        const url = new URL(window.location.href);

        if (url.searchParams.has('packages')) {
            url.searchParams.delete('packages');
            window.history.replaceState(window.history.state, '', url);
        }
    } catch {
        // The filter still applies; only a reload would reapply it.
    }
}

/**
 * The saved preferences, with a linked package filter taking the place of
 * the saved filters, so the board shows what the link counted.
 */
function initialPreferences(key: string): BoardPreferences {
    const preferences = loadPreferences(key);
    const packages = linkedPackages();

    return packages === null ? preferences : { ...preferences, packages, milestone: '', label: '' };
}

function savePreferences(key: string, preferences: BoardPreferences): void {
    try {
        window.localStorage.setItem(key, JSON.stringify(preferences));
    } catch {
        // Not remembered this time; the board still works.
    }
}

/**
 * One swimlane per package, A–Z, then the issues whose repo isn't mapped
 * to a package.
 */
function swimlanes(items: BoardItem[]): BoardLane[] {
    const lanes = new Map<string, BoardLane>();

    for (const item of items) {
        const key = item.package === null ? 'none' : String(item.package.id);
        const lane = lanes.get(key) ?? { key, title: item.package?.title ?? 'No package', items: [] };
        lane.items.push(item);
        lanes.set(key, lane);
    }

    return [...lanes.values()].sort((a, b) =>
        a.key === 'none' ? 1 : b.key === 'none' ? -1 : (a.title ?? '').localeCompare(b.title ?? '', undefined, { sensitivity: 'base' }),
    );
}

export default function PackagesBoardPage({ nav, can }: PluginPageProps) {
    return (
        <PluginPage
            nav={nav}
            can={can}
            active="board"
            title="ArtisanPack UI"
            description="Every ArtisanPack UI package's issues, across the org GitHub Project."
        >
            {can.issuesManage ? <GlobalBoard /> : <NoAccess title="Packages board" />}
        </PluginPage>
    );
}

function GlobalBoard() {
    const endpoints = useSharedEndpoints();
    const { props } = usePage<{ auth?: { user?: { id?: number | string } | null } }>();
    const storageKey = `artisanpack-ui:board:${props.auth?.user?.id ?? 'guest'}`;
    const [preferences, setPreferences] = useState<BoardPreferences>(() => initialPreferences(storageKey));
    const state = useBoard(endpoints?.board.index ?? null, endpoints?.board.move ?? null);
    const groupId = useId();
    const sortId = useId();

    useEffect(() => forgetLinkedPackages(), []);
    useEffect(() => savePreferences(storageKey, preferences), [storageKey, preferences]);

    const update = (changes: Partial<BoardPreferences>) => setPreferences((current) => ({ ...current, ...changes }));

    const lanes = useMemo(() => {
        if (state.board === null) {
            return [];
        }

        const items = sortItems(filterItems(state.board.items, preferences), preferences.sort);

        return preferences.grouped ? swimlanes(items) : [{ key: 'all', title: null, items }];
    }, [state.board, preferences]);

    if (!endpoints) {
        return null;
    }

    const board = state.board;
    const filtered = preferences.packages.length > 0 || preferences.milestone !== '' || preferences.label !== '';

    return (
        <BoardView
            state={state}
            endpoints={endpoints.board}
            lanes={lanes}
            showPackage={!preferences.grouped}
            emptyMessage={filtered ? 'No issues match these filters.' : 'The org project has no issues yet.'}
            toolbar={
                board !== null && (
                    <>
                        <label htmlFor={groupId} className="flex items-center gap-2 text-sm text-base-content/70">
                            <input
                                id={groupId}
                                type="checkbox"
                                className="toggle toggle-sm"
                                checked={preferences.grouped}
                                onChange={(event) =>
                                    // Swimlanes already group by package, so that sort is dropped.
                                    update({
                                        grouped: event.target.checked,
                                        sort: event.target.checked && preferences.sort === 'package' ? 'updated' : preferences.sort,
                                    })
                                }
                            />
                            Group by package
                        </label>
                        <span className="flex items-center gap-2">
                            <label htmlFor={sortId} className="text-sm text-base-content/70">
                                Sort by
                            </label>
                            <select
                                id={sortId}
                                className="select select-sm"
                                value={preferences.sort}
                                onChange={(event) => update({ sort: event.target.value as BoardSort })}
                            >
                                {(Object.keys(SORT_LABELS) as BoardSort[])
                                    .filter((sort) => !(preferences.grouped && sort === 'package'))
                                    .map((sort) => (
                                        <option key={sort} value={sort}>
                                            {SORT_LABELS[sort]}
                                        </option>
                                    ))}
                            </select>
                        </span>
                        <PackageFilter
                            packages={board.packages}
                            selected={preferences.packages}
                            onChange={(packages) => update({ packages })}
                        />
                        <MilestoneLabelFilters
                            milestones={board.milestones}
                            labels={board.labels}
                            filter={preferences}
                            onChange={(filter) => update({ milestone: filter.milestone, label: filter.label })}
                        />
                        {filtered && (
                            <button
                                type="button"
                                className="btn btn-ghost btn-sm"
                                onClick={() => update({ packages: [], milestone: '', label: '' })}
                            >
                                Clear filters
                            </button>
                        )}
                    </>
                )
            }
        />
    );
}

/**
 * The multi-select package filter, as a disclosure of checkboxes.
 */
function PackageFilter({
    packages,
    selected,
    onChange,
}: {
    packages: { id: number; title: string }[];
    selected: number[];
    onChange: (selected: number[]) => void;
}) {
    const summary = selected.length === 0 ? 'All packages' : `${selected.length} package${selected.length === 1 ? '' : 's'}`;

    return (
        <details className="dropdown">
            <summary className="btn btn-sm">Packages: {summary}</summary>
            <fieldset
                className="dropdown-content z-10 mt-1 space-y-1 overflow-y-auto rounded-md border border-base-300/60 bg-base-100 p-3 shadow-lg"
                style={{ width: '16rem', maxHeight: '20rem' }}
            >
                <legend className="sr-only">Packages</legend>
                {packages.length === 0 && <p className="text-xs text-base-content/55">No issues map to a package yet.</p>}
                {packages.map((item) => (
                    <label key={item.id} className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            className="checkbox checkbox-sm"
                            checked={selected.includes(item.id)}
                            onChange={() =>
                                onChange(selected.includes(item.id) ? selected.filter((id) => id !== item.id) : [...selected, item.id])
                            }
                        />
                        {item.title}
                    </label>
                ))}
            </fieldset>
        </details>
    );
}
