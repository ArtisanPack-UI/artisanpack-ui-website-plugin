/**
 * The board's frame, shared by the package board and the global board:
 * the loading and error states, the rolled-back-move notice, the board
 * itself and the issue modal over it.
 */

import { useState, type ReactNode } from 'react';

import type { BoardEndpoints, BoardItem } from '../../lib/types';
import { CARD_CLASS } from '../ui';
import { itemChangesFrom } from './filters';
import { IssueModal } from './IssueModal';
import { KanbanBoard, type BoardLane } from './KanbanBoard';
import type { BoardState } from './useBoard';

export function BoardView({
    state,
    endpoints,
    lanes,
    toolbar,
    showPackage = false,
    emptyMessage,
    retryable = true,
}: {
    state: BoardState;
    endpoints: BoardEndpoints;
    lanes: BoardLane[];
    toolbar?: ReactNode;
    showPackage?: boolean;
    emptyMessage: string;
    /** Whether a failed load offers "Try again"; false when retrying can't help. */
    retryable?: boolean;
}) {
    const [open, setOpen] = useState<BoardItem | null>(null);
    const { board, loading, error, notice } = state;

    if (board === null) {
        return (
            <section className={CARD_CLASS}>
                {error !== null ? (
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p role="alert" className="text-sm text-error">
                            {error}
                        </p>
                        {retryable && (
                            <button type="button" className="btn btn-sm" onClick={state.reload}>
                                Try again
                            </button>
                        )}
                    </div>
                ) : (
                    <div className="flex animate-pulse gap-3" aria-label="Loading the board">
                        {[0, 1, 2, 3].map((column) => (
                            <div key={column} className="h-40 rounded-lg bg-base-200/60" style={{ flex: '0 0 18rem' }} />
                        ))}
                    </div>
                )}
            </section>
        );
    }

    const count = lanes.reduce((total, lane) => total + lane.items.length, 0);

    return (
        <section className={`${CARD_CLASS} space-y-4`}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-3">{toolbar}</div>
                <span className="flex items-center gap-2">
                    <a href={board.project.url} target="_blank" rel="noreferrer" className="text-xs text-base-content/60 hover:underline">
                        {board.project.title} on GitHub
                    </a>
                    <button type="button" className="btn btn-ghost btn-sm" disabled={loading} onClick={state.reload}>
                        {loading ? 'Refreshing…' : 'Refresh'}
                    </button>
                </span>
            </div>

            {error !== null && (
                <p role="alert" className="text-sm text-error">
                    {error}
                </p>
            )}
            <div aria-live="polite">
                {notice !== null && (
                    <div className="flex items-start justify-between gap-2 rounded-md border border-error/40 px-3 py-2 text-sm">
                        <p className="text-error">{notice}</p>
                        <button type="button" className="btn btn-ghost btn-xs" onClick={state.dismissNotice}>
                            Dismiss
                        </button>
                    </div>
                )}
            </div>

            {board.truncated && (
                <p className="rounded-md border border-warning/40 px-3 py-2 text-sm text-base-content/70">
                    Showing the first {board.items.length} items; the project has more.
                </p>
            )}

            {count === 0 ? (
                <p className="text-sm text-base-content/60">{emptyMessage}</p>
            ) : (
                <KanbanBoard
                    columns={board.columns}
                    lanes={lanes}
                    showPackage={showPackage}
                    canMove
                    isPending={state.isPending}
                    onMove={(item, statusId) => void state.moveItem(item, statusId)}
                    onOpen={setOpen}
                />
            )}

            {open !== null && (
                <IssueModal
                    key={open.id}
                    endpoints={endpoints}
                    repo={open.repo}
                    number={open.number}
                    onClose={() => setOpen(null)}
                    onUpdated={(issue) => state.updateItem(open.id, itemChangesFrom(issue))}
                />
            )}
        </section>
    );
}
