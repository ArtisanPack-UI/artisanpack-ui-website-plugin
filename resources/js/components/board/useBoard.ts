/**
 * Loads a board from the plugin's board endpoints and moves its cards,
 * shared by the Edit Package Issues tab and the global board page.
 *
 * A move is optimistic: the card changes column at once, and goes back
 * (with a message) if GitHub refuses the change.
 */

import { useCallback, useEffect, useRef, useState } from 'react';

import { apiFetch, boardItemUrl } from '../../lib/http';
import type { BoardItem, BoardResponse } from '../../lib/types';

export interface BoardState {
    board: BoardResponse | null;
    loading: boolean;
    /** Why the board couldn't be loaded. */
    error: string | null;
    /** Why the last move or edit was rolled back, for the live region. */
    notice: string | null;
    reload: () => void;
    moveItem: (item: BoardItem, statusId: string | null) => Promise<void>;
    /** Merge fields into one card, e.g. after the issue modal saves. */
    updateItem: (itemId: string, changes: Partial<BoardItem>) => void;
    dismissNotice: () => void;
}

export function useBoard(url: string | null, moveTemplate: string | null): BoardState {
    const [board, setBoard] = useState<BoardResponse | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const request = useRef(0);

    const load = useCallback(async (target: string) => {
        const current = ++request.current;
        setLoading(true);

        try {
            const response = await apiFetch<BoardResponse>(target);

            if (current === request.current) {
                setBoard(response);
                setError(null);
            }
        } catch (loadError) {
            if (current === request.current) {
                setError(loadError instanceof Error ? loadError.message : 'Could not load the board.');
            }
        } finally {
            if (current === request.current) {
                setLoading(false);
            }
        }
    }, []);

    useEffect(() => {
        if (url !== null) {
            void load(url);
        }
    }, [url, load]);

    const updateItem = useCallback((itemId: string, changes: Partial<BoardItem>) => {
        setBoard((current) =>
            current === null
                ? current
                : { ...current, items: current.items.map((item) => (item.id === itemId ? { ...item, ...changes } : item)) },
        );
    }, []);

    const moveItem = useCallback(
        async (item: BoardItem, statusId: string | null) => {
            if (moveTemplate === null || item.statusId === statusId) {
                return;
            }

            const previous = item.statusId;
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
            }
        },
        [moveTemplate, updateItem],
    );

    return {
        board,
        loading,
        error,
        notice,
        reload: () => {
            if (url !== null) {
                void load(url);
            }
        },
        moveItem,
        updateItem,
        dismissNotice: () => setNotice(null),
    };
}
