/**
 * The kanban board over the org project's Status columns, shared by the
 * Edit Package Issues tab (roadmap 5.2) and the global board (5.4).
 *
 * Cards move by drag and drop, or with each card's Status select, which is
 * the keyboard and screen-reader path. Either way only the Status
 * changes: a card dropped into another package's swimlane stays in its
 * own. Clicking a card's title opens the issue modal.
 */

import { useState, type DragEvent } from 'react';

import { formatDate } from '../../lib/format';
import type { BoardColumn, BoardItem } from '../../lib/types';
import { LabelChip } from './labels';

/** A row of columns: the whole board, or one package's swimlane. */
export interface BoardLane {
    key: string;
    title: string | null;
    items: BoardItem[];
}

const COLUMN_WIDTH = '18rem';

const DRAG_TYPE = 'application/x-artisanpack-ui-board-item';

export function KanbanBoard({
    columns,
    lanes,
    showPackage = false,
    canMove,
    onMove,
    onOpen,
}: {
    columns: BoardColumn[];
    lanes: BoardLane[];
    showPackage?: boolean;
    canMove: boolean;
    onMove: (item: BoardItem, statusId: string | null) => void;
    onOpen: (item: BoardItem) => void;
}) {
    const [dragging, setDragging] = useState<BoardItem | null>(null);
    const [target, setTarget] = useState<string | null>(null);
    const all = lanes.flatMap((lane) => lane.items);

    function drop(event: DragEvent<HTMLElement>, statusId: string | null) {
        event.preventDefault();
        const id = event.dataTransfer.getData(DRAG_TYPE);
        const item = all.find((candidate) => candidate.id === id);

        setDragging(null);
        setTarget(null);

        if (item) {
            onMove(item, statusId);
        }
    }

    return (
        <div className="space-y-6">
            {lanes.map((lane) => (
                <section key={lane.key} aria-label={lane.title ?? undefined}>
                    {lane.title !== null && (
                        <h3 className="mb-2 text-sm font-semibold text-base-content">
                            {lane.title} <span className="font-normal text-base-content/55">({lane.items.length})</span>
                        </h3>
                    )}
                    <div className="flex gap-3 overflow-x-auto pb-2">
                        {columns.map((column) => {
                            const cards = lane.items.filter((item) => item.statusId === column.id);
                            const dropKey = `${lane.key}:${column.id ?? ''}`;

                            return (
                                <div
                                    key={column.id ?? 'none'}
                                    role="group"
                                    aria-label={`${column.name}, ${cards.length} issue${cards.length === 1 ? '' : 's'}`}
                                    className={`rounded-lg border p-2 ${
                                        target === dropKey ? 'border-primary bg-primary/5' : 'border-base-300/60 bg-base-200/40'
                                    }`}
                                    style={{ flex: `0 0 ${COLUMN_WIDTH}`, width: COLUMN_WIDTH }}
                                    onDragOver={(event) => {
                                        if (canMove && dragging !== null) {
                                            event.preventDefault();
                                            setTarget(dropKey);
                                        }
                                    }}
                                    onDragLeave={(event) => {
                                        // Moving over a card inside the column isn't leaving it.
                                        if (!event.currentTarget.contains(event.relatedTarget as Node | null)) {
                                            setTarget((current) => (current === dropKey ? null : current));
                                        }
                                    }}
                                    onDrop={(event) => canMove && drop(event, column.id)}
                                >
                                    <h4 className="mb-2 flex items-center justify-between gap-2 px-1 text-xs font-semibold uppercase text-base-content/70">
                                        <span>{column.name}</span>
                                        <span className="font-normal text-base-content/55">{cards.length}</span>
                                    </h4>
                                    <ul className="space-y-2" style={{ minHeight: '3rem' }}>
                                        {cards.map((item) => (
                                            <BoardCard
                                                key={item.id}
                                                item={item}
                                                columns={columns}
                                                showPackage={showPackage}
                                                canMove={canMove}
                                                dragging={dragging?.id === item.id}
                                                onDragStart={(event) => {
                                                    event.dataTransfer.setData(DRAG_TYPE, item.id);
                                                    event.dataTransfer.effectAllowed = 'move';
                                                    setDragging(item);
                                                }}
                                                onDragEnd={() => {
                                                    setDragging(null);
                                                    setTarget(null);
                                                }}
                                                onMove={onMove}
                                                onOpen={onOpen}
                                            />
                                        ))}
                                    </ul>
                                </div>
                            );
                        })}
                    </div>
                </section>
            ))}
        </div>
    );
}

function BoardCard({
    item,
    columns,
    showPackage,
    canMove,
    dragging,
    onDragStart,
    onDragEnd,
    onMove,
    onOpen,
}: {
    item: BoardItem;
    columns: BoardColumn[];
    showPackage: boolean;
    canMove: boolean;
    dragging: boolean;
    onDragStart: (event: DragEvent<HTMLLIElement>) => void;
    onDragEnd: () => void;
    onMove: (item: BoardItem, statusId: string | null) => void;
    onOpen: (item: BoardItem) => void;
}) {
    return (
        <li
            draggable={canMove}
            onDragStart={onDragStart}
            onDragEnd={onDragEnd}
            className={`rounded-md border border-base-300/60 bg-base-100 p-2 shadow-sm ${canMove ? 'cursor-grab' : ''}`}
            style={{ opacity: dragging ? 0.5 : 1 }}
        >
            <p className="text-xs text-base-content/55">
                {item.repo.slice(item.repo.indexOf('/') + 1)}#{item.number}
                {item.state === 'CLOSED' && <span className="badge badge-ghost badge-xs ml-2">Closed</span>}
            </p>
            <button
                type="button"
                className="mt-1 block w-full text-left text-sm font-medium text-base-content hover:underline"
                onClick={() => onOpen(item)}
            >
                {item.title}
            </button>

            {(item.labels.length > 0 || item.milestone !== null || (showPackage && item.package !== null)) && (
                <div className="mt-2 flex flex-wrap gap-1">
                    {showPackage && item.package !== null && (
                        <span className="badge badge-outline badge-sm">{item.package.title}</span>
                    )}
                    {item.milestone !== null && <span className="badge badge-ghost badge-sm">{item.milestone.title}</span>}
                    {item.labels.map((label) => (
                        <LabelChip key={label.name} label={label} />
                    ))}
                </div>
            )}

            <div className="mt-2 flex items-center justify-between gap-2">
                <span className="flex items-center gap-1">
                    {item.assignees.map((user) =>
                        user.avatarUrl ? (
                            <img
                                key={user.login}
                                src={user.avatarUrl}
                                alt={user.login}
                                title={user.login}
                                className="rounded-full"
                                style={{ width: 20, height: 20 }}
                            />
                        ) : (
                            <span key={user.login} className="text-xs text-base-content/60">
                                @{user.login}
                            </span>
                        ),
                    )}
                    {item.assignees.length === 0 && (
                        <span className="text-xs text-base-content/45">Updated {formatDate(item.updatedAt)}</span>
                    )}
                </span>
                {canMove && (
                    <select
                        aria-label={`Status of #${item.number}`}
                        className="select select-xs"
                        style={{ maxWidth: '8rem' }}
                        value={item.statusId ?? ''}
                        onChange={(event) => onMove(item, event.target.value === '' ? null : event.target.value)}
                    >
                        {columns.map((column) => (
                            <option key={column.id ?? 'none'} value={column.id ?? ''}>
                                {column.name}
                            </option>
                        ))}
                    </select>
                )}
            </div>
        </li>
    );
}
