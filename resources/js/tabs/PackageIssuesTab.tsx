/**
 * Edit Package → Issues tab — federated remote `artisanpack-ui`, exposed as
 * `./package-issues`, mounted on the `package` edit screen by `../boot.tsx`.
 *
 * Roadmap 5.2: the org project's board scoped to the package's repo, with
 * the project's Status options as columns and milestone and label filters.
 * Dragging a card (or using its Status select) moves it on GitHub; clicking
 * its title opens the issue modal (5.3). Gated on
 * `artisanpack-ui.issues.manage` by the boot module and the endpoints.
 */

import { useMemo, useState } from 'react';

import { BoardView } from '../components/board/BoardView';
import { filterItems, MilestoneLabelFilters, sortItems, type BoardFilter } from '../components/board/filters';
import { useBoard } from '../components/board/useBoard';
import { useSharedEndpoints } from '../components/ui';
import { packageUrl } from '../lib/http';
import type { PackageTabProps } from '../lib/types';

const NO_FILTER: BoardFilter = { packages: [], milestone: '', label: '' };

export default function PackageIssuesTab({ context }: PackageTabProps) {
    const endpoints = useSharedEndpoints();
    const packageId = context.record.id as number | undefined;
    const [filter, setFilter] = useState<BoardFilter>(NO_FILTER);

    // Keyed on the template string: the shared endpoints object is rebuilt
    // on every Inertia response, including a save of this screen's form.
    const boardTemplate = endpoints?.package.board ?? null;
    const url = boardTemplate !== null && packageId !== undefined ? packageUrl(boardTemplate, packageId) : null;
    const state = useBoard(url, endpoints?.board.move ?? null);

    const items = useMemo(
        () => (state.board === null ? [] : sortItems(filterItems(state.board.items, filter), 'updated')),
        [state.board, filter],
    );

    if (!endpoints || packageId === undefined) {
        return null;
    }

    return (
        <BoardView
            state={state}
            endpoints={endpoints.board}
            lanes={[{ key: 'package', title: null, items }]}
            emptyMessage={
                state.board !== null && state.board.items.length > 0
                    ? 'No issues match these filters.'
                    : "None of this package's issues are on the org project yet."
            }
            toolbar={
                state.board !== null && (
                    <MilestoneLabelFilters
                        milestones={state.board.milestones}
                        labels={state.board.labels}
                        filter={filter}
                        onChange={setFilter}
                    />
                )
            }
        />
    );
}
