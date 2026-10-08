/**
 * Loads a board from the plugin's board endpoints and moves its cards,
 * shared by the Edit Package Issues tab and the global board page.
 *
 * Boards are kept in a module-level cache for the life of the page, so
 * switching Edit Package tabs (which unmounts them) shows the last board at
 * once; it is only fetched again when it is older than {@link CACHE_TTL}.
 * Refresh always asks the server for a fresh read (`?fresh=1`).
 *
 * A move is optimistic: the card changes column at once, and goes back
 * (with a message) if GitHub refuses the change. A card with a move in
 * flight can't be moved again until it settles, and a reload that lands
 * mid-move keeps showing the card where it is going.
 */

import { useCallback, useEffect, useRef, useState } from 'react';

import { ApiError, apiFetch, boardItemUrl } from '../../lib/http';
import type { BoardItem, BoardResponse } from '../../lib/types';

/** Milliseconds a cached board is shown without fetching it again. */
const CACHE_TTL = 60_000;

const boardCache = new Map<string, { at: number; data: BoardResponse }>();

export interface BoardState {
    board: BoardResponse | null;
    loading: boolean;
    /** Why the board couldn't be loaded. */
    error: string | null;
    /** The HTTP status of the failed load, when there was one. */
    errorStatus: number | null;
    /** Why the last move or edit was rolled back, for the live region. */
    notice: string | null;
    /** Fetch the board again, bypassing the server's short cache. */
    reload: () => void;
    moveItem: (item: BoardItem, statusId: string | null) => Promise<void>;
    /** Whether a card has a move in flight. */
    isPending: (itemId: string) => boolean;
    /** Merge fields into one card, e.g. after the issue modal saves. */
    updateItem: (itemId: string, changes: Partial<BoardItem>) => void;
    dismissNotice: () => void;
}

function withFresh(url: string): string {
    return `${url}${url.includes('?') ? '&' : '?'}fresh=1`;
}

export function useBoard(url: string | null, moveTemplate: string | null): BoardState {
    const [board, setBoard] = useState<BoardResponse | null>(() => (url === null ? null : (boardCache.get(url)?.data ?? null)));
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [errorStatus, setErrorStatus] = useState<number | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [, setPendingVersion] = useState(0);
    const request = useRef(0);
    const pending = useRef(new Map<string, string | null>());

    /** Show each card with a move in flight in the column it is moving to. */
    const overlayPending = useCallback((response: BoardResponse): BoardResponse => {
        if (pending.current.size === 0) {
            return response;
        }

        return {
            ...response,
            items: response.items.map((item) =>
                pending.current.has(item.id) ? { ...item, statusId: pending.current.get(item.id) ?? null } : item,
            ),
        };
    }, []);

    const load = useCallback(
        async (target: string, fresh: boolean) => {
            const current = ++request.current;
            setLoading(true);

            try {
                const response = await apiFetch<BoardResponse>(fresh ? withFresh(target) : target);
                boardCache.set(target, { at: Date.now(), data: response });

                if (current === request.current) {
                    setBoard(overlayPending(response));
                    setError(null);
                    setErrorStatus(null);
                }
            } catch (loadError) {
                if (current === request.current) {
                    setError(loadError instanceof Error ? loadError.message : 'Could not load the board.');
                    setErrorStatus(loadError instanceof ApiError ? loadError.status : null);
                }
            } finally {
                if (current === request.current) {
                    setLoading(false);
                }
            }
        },
        [overlayPending],
    );

    useEffect(() => {
        if (url === null) {
            return;
        }

        const cached = boardCache.get(url);

        if (cached !== undefined) {
            setBoard(overlayPending(cached.data));
        }

        if (cached !== undefined && Date.now() - cached.at < CACHE_TTL) {
            setLoading(false);

            return;
        }

        void load(url, false);
    }, [url, load, overlayPending]);

    // Keep the cache in step with local moves and edits, without making a
    // stale entry look fresh.
    useEffect(() => {
        if (url !== null && board !== null) {
            boardCache.set(url, { at: boardCache.get(url)?.at ?? 0, data: board });
        }
    }, [url, board]);

    const updateItem = useCallback((itemId: string, changes: Partial<BoardItem>) => {
        setBoard((current) =>
            current === null
                ? current
                : { ...current, items: current.items.map((item) => (item.id === itemId ? { ...item, ...changes } : item)) },
        );
    }, []);

    const moveItem = useCallback(
        async (item: BoardItem, statusId: string | null) => {
            if (moveTemplate === null || item.statusId === statusId || pending.current.has(item.id)) {
                return;
            }

            const previous = item.statusId;
            pending.current.set(item.id, statusId);
            setPendingVersion((version) => version + 1);
            updateItem(item.id, { statusId });
            setNotice(null);

            try {
                await apiFetch(boardItemUrl(moveTemplate, item.id), {
                    method: 'PUT',
                    body: JSON.stringify({ status: statusId }),
                });
            } catch (moveError) {
                updateItem(item.id, { statusId: previous });
                setNotice(
                    `Couldn't move #${item.number}: ${moveError instanceof Error ? moveError.message : 'the request failed.'} It's back in its column.`,
                );
            } finally {
                pending.current.delete(item.id);
                setPendingVersion((version) => version + 1);
            }
        },
        [moveTemplate, updateItem],
    );

    return {
        board,
        loading,
        error,
        errorStatus,
        notice,
        reload: () => {
            if (url !== null) {
                void load(url, true);
            }
        },
        moveItem,
        isPending: (itemId: string) => pending.current.has(itemId),
        updateItem,
        dismissNotice: () => setNotice(null),
    };
}
