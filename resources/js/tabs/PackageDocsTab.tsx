/**
 * Edit Package → Docs tab — federated remote `artisanpack-ui`, exposed as
 * `./package-docs`, mounted on the `package` edit screen by `../boot.tsx`.
 *
 * Manages the package's docs on the docs site (roadmap 3.1–3.2):
 *
 *   - "Import documentation" / "Import changelog" queue the docs site's
 *     imports. Imports run on the docs site's queue, so the tab polls the
 *     status until neither is queued, then shows done or failed with the
 *     last-imported time. An import this tab queued stays "Queued" until the
 *     docs site reports a newer import time or a failure, so a status read
 *     that lags the trigger can't flip it back and forth.
 *   - The documentation tree reorders siblings within their parent, by
 *     drag and drop or the move buttons. Each move is shown at once and
 *     saved in the background; a failed save puts the old order back.
 *     Parents can't change here: the docs site takes them from the repo's
 *     folder layout. The move buttons stay focusable while a save runs or
 *     at the end of a list (`aria-disabled`), and focus follows the moved
 *     page, so a keyboard user can press "Move down" repeatedly.
 *
 * The tab sits inside the edit screen's `<form>`, so every button is
 * `type="button"`.
 */

import { useCallback, useEffect, useRef, useState, type DragEvent } from 'react';

import { CARD_CLASS, useSharedEndpoints } from '../components/ui';
import { ApiError, apiFetch, formatDateTime, packageUrl } from '../lib/http';
import type { DocNode, DocsImport, DocsStatusResponse, PackageEndpoints, PackageTabProps } from '../lib/types';

/** How often the import status is polled while an import is queued. */
const POLL_INTERVAL_MS = 3000;

/** When polling gives up; the import may still finish later. */
const POLL_TIMEOUT_MS = 5 * 60 * 1000;

type ImportKind = 'docs' | 'changelog';

/** An import this tab queued and is waiting on: the state it replaces. */
interface AwaitedImport {
    importedAt: string | null;
    wasFailed: boolean;
    sawQueued: boolean;
}

/**
 * Whether the docs site has reported the outcome of an import queued after
 * `awaited` was captured: a newer import time, or a failure that isn't the
 * one from before.
 */
function isSettled(state: DocsImport, awaited: AwaitedImport): boolean {
    if (state.status === 'failed') {
        return !awaited.wasFailed || awaited.sawQueued;
    }

    return (
        state.status !== 'queued' &&
        state.importedAt !== null &&
        (awaited.importedAt === null || Date.parse(state.importedAt) > Date.parse(awaited.importedAt))
    );
}

const IMPORTS: { kind: ImportKind; label: string; button: string; endpoint: keyof PackageEndpoints }[] = [
    { kind: 'docs', label: 'Documentation', button: 'Import documentation', endpoint: 'importDocs' },
    { kind: 'changelog', label: 'Changelog', button: 'Import changelog', endpoint: 'importChangelog' },
];

export default function PackageDocsTab({ context }: PackageTabProps) {
    const endpoints = useSharedEndpoints();
    const packageId = context.record.id as number | undefined;

    if (!endpoints || packageId === undefined) {
        return null;
    }

    return (
        <div className="space-y-4">
            <ImportsCard endpoints={endpoints.package} packageId={packageId} />
            <ReorderCard endpoints={endpoints.package} packageId={packageId} />
        </div>
    );
}

function ImportsCard({ endpoints, packageId }: { endpoints: PackageEndpoints; packageId: number }) {
    const [status, setStatus] = useState<DocsStatusResponse | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [triggering, setTriggering] = useState<ImportKind | null>(null);
    const [pollTimedOut, setPollTimedOut] = useState(false);
    const [awaiting, setAwaiting] = useState<Partial<Record<ImportKind, AwaitedImport>>>({});
    const inFlight = useRef(false);

    const loadStatus = useCallback(async () => {
        // One read at a time, so a slow response can't land after a newer one.
        if (inFlight.current) {
            return;
        }

        inFlight.current = true;

        try {
            setStatus(await apiFetch<DocsStatusResponse>(packageUrl(endpoints.docsStatus, packageId)));
            setError(null);
        } catch (loadError) {
            setError(loadError instanceof Error ? loadError.message : 'Could not load the import status.');
        } finally {
            inFlight.current = false;
        }
    }, [endpoints.docsStatus, packageId]);

    useEffect(() => {
        void loadStatus();
    }, [loadStatus]);

    // Stop waiting on each import the docs site has reported an outcome for.
    useEffect(() => {
        const imports = status?.imports;

        if (!imports) {
            return;
        }

        setAwaiting((current) => {
            const next: Partial<Record<ImportKind, AwaitedImport>> = {};
            let changed = false;

            for (const kind of Object.keys(current) as ImportKind[]) {
                const awaited = current[kind]!;

                if (isSettled(imports[kind], awaited)) {
                    changed = true;
                } else {
                    next[kind] = imports[kind].status === 'queued' && !awaited.sawQueued ? { ...awaited, sawQueued: true } : awaited;
                    changed ||= next[kind] !== awaited;
                }
            }

            return changed ? next : current;
        });
    }, [status]);

    /** What each row shows: an import this tab is waiting on reads "Queued". */
    const imports = status?.imports
        ? {
              docs: awaiting.docs ? { ...status.imports.docs, status: 'queued' as const, error: null } : status.imports.docs,
              changelog: awaiting.changelog
                  ? { ...status.imports.changelog, status: 'queued' as const, error: null }
                  : status.imports.changelog,
          }
        : null;

    const queued = imports?.docs.status === 'queued' || imports?.changelog.status === 'queued';

    useEffect(() => {
        if (!queued) {
            return;
        }

        setPollTimedOut(false);
        const startedAt = Date.now();
        const timer = window.setInterval(() => {
            if (Date.now() - startedAt > POLL_TIMEOUT_MS) {
                window.clearInterval(timer);
                setPollTimedOut(true);

                return;
            }

            void loadStatus();
        }, POLL_INTERVAL_MS);

        return () => window.clearInterval(timer);
    }, [queued, loadStatus]);

    async function trigger(kind: ImportKind, url: string) {
        setTriggering(kind);
        setNotice(null);
        setError(null);

        try {
            const response = await apiFetch<{ message: string; queued: boolean }>(url, { method: 'POST' });
            const before = status?.imports?.[kind];

            setNotice(response.message);

            if (response.queued) {
                setAwaiting((current) => ({
                    ...current,
                    [kind]: {
                        importedAt: before?.importedAt ?? null,
                        wasFailed: before?.status === 'failed',
                        sawQueued: false,
                    },
                }));
            }
        } catch (triggerError) {
            setError(triggerError instanceof Error ? triggerError.message : 'The import could not be queued.');
        } finally {
            setTriggering(null);
        }
    }

    return (
        <section className={CARD_CLASS} aria-labelledby="artisanpack-ui-imports-heading">
            <h2 id="artisanpack-ui-imports-heading" className="text-base font-semibold text-base-content">
                Imports
            </h2>
            <p className="mt-1 text-sm text-base-content/60">
                Pull this package's documentation and changelog into the docs site from its GitHub repo.
            </p>

            {status !== null && !status.linked && (
                <p className="mt-4 text-sm text-base-content/70">
                    This package isn't linked to the docs site yet. Use "Sync now" to link it.
                </p>
            )}

            {imports !== null && (
                <ul className="mt-4 divide-y divide-base-300/60">
                    {IMPORTS.map((item) => (
                        <ImportRow
                            key={item.kind}
                            label={item.label}
                            button={item.button}
                            state={imports[item.kind]}
                            busy={triggering !== null}
                            onImport={() => trigger(item.kind, packageUrl(endpoints[item.endpoint], packageId))}
                        />
                    ))}
                </ul>
            )}

            <div aria-live="polite" className="mt-3 space-y-1 text-sm">
                {notice !== null && <p className="text-base-content/70">{notice}</p>}
                {queued && !pollTimedOut && <p className="text-base-content/60">Checking the docs site for the result…</p>}
                {queued && pollTimedOut && (
                    <p className="text-base-content/60">
                        Still queued on the docs site. Reopen this tab later to see the result.
                    </p>
                )}
                {error !== null && <p className="text-error">{error}</p>}
            </div>
        </section>
    );
}

const STATUS_BADGES: Record<NonNullable<DocsImport['status']>, { label: string; className: string }> = {
    queued: { label: 'Queued', className: 'badge badge-warning badge-sm' },
    succeeded: { label: 'Done', className: 'badge badge-success badge-sm' },
    failed: { label: 'Failed', className: 'badge badge-error badge-sm' },
};

function ImportRow({
    label,
    button,
    state,
    busy,
    onImport,
}: {
    label: string;
    button: string;
    state: DocsImport;
    busy: boolean;
    onImport: () => void;
}) {
    const badge = state.status === null ? null : STATUS_BADGES[state.status];

    return (
        <li className="flex flex-wrap items-start justify-between gap-3 py-3">
            <div className="min-w-0 space-y-1">
                <div className="flex items-center gap-2">
                    <span className="text-sm font-medium text-base-content">{label}</span>
                    {badge ? (
                        <span className={badge.className}>{badge.label}</span>
                    ) : (
                        <span className="text-xs text-base-content/55">Never imported</span>
                    )}
                </div>
                <p className="text-xs text-base-content/60">Last imported: {formatDateTime(state.importedAt)}</p>
                {state.status === 'failed' && state.error && <p className="text-xs text-error">{state.error}</p>}
            </div>
            <button
                type="button"
                className="btn btn-outline btn-sm"
                disabled={busy || state.status === 'queued'}
                onClick={onImport}
            >
                {button}
            </button>
        </li>
    );
}

/**
 * The tree with `parent`'s children put in `ids` order.
 */
function reorderTree(nodes: DocNode[], parent: number, ids: number[]): DocNode[] {
    const ordered = (list: DocNode[]) => ids.map((id) => list.find((node) => node.id === id)).filter((node): node is DocNode => node !== undefined);

    if (parent === 0) {
        return ordered(nodes);
    }

    return nodes.map((node) =>
        node.id === parent
            ? { ...node, children: ordered(node.children) }
            : { ...node, children: reorderTree(node.children, parent, ids) },
    );
}

/**
 * The ids in `ids` with `moved` placed at `index`.
 */
function moveTo(ids: number[], moved: number, index: number): number[] {
    const without = ids.filter((id) => id !== moved);
    without.splice(Math.max(0, Math.min(index, without.length)), 0, moved);

    return without;
}

type MoveDirection = 'up' | 'down';

function ReorderCard({ endpoints, packageId }: { endpoints: PackageEndpoints; packageId: number }) {
    const [tree, setTree] = useState<DocNode[] | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);
    const treeRef = useRef<DocNode[] | null>(null);
    const listRef = useRef<HTMLDivElement>(null);
    const refocus = useRef<{ id: number; direction: MoveDirection } | null>(null);
    treeRef.current = tree;

    // After a move re-renders the list, put focus back on the moved page's
    // button, or its other button if that end of the list was reached.
    useEffect(() => {
        const target = refocus.current;

        if (target === null || listRef.current === null) {
            return;
        }

        refocus.current = null;

        const button = (direction: MoveDirection) =>
            listRef.current?.querySelector<HTMLButtonElement>(`button[data-doc-id="${target.id}"][data-dir="${direction}"]`) ?? null;
        const same = button(target.direction);
        const other = button(target.direction === 'up' ? 'down' : 'up');

        (same?.dataset.atEnd === 'true' && other !== null ? other : same)?.focus();
    }, [tree]);

    const loadTree = useCallback(async () => {
        try {
            const response = await apiFetch<{ tree: DocNode[] }>(packageUrl(endpoints.docsTree, packageId));
            setTree(response.tree);
            setLoadError(null);
        } catch (error) {
            setLoadError(error instanceof Error ? error.message : 'Could not load the documentation.');
        }
    }, [endpoints.docsTree, packageId]);

    useEffect(() => {
        void loadTree();
    }, [loadTree]);

    async function save(parent: number, ids: number[], focus?: { id: number; direction: MoveDirection }) {
        const previous = treeRef.current;

        if (previous === null) {
            return;
        }

        refocus.current = focus ?? null;

        setTree(reorderTree(previous, parent, ids));
        setSaving(true);
        setMessage(null);

        try {
            const response = await apiFetch<{ message: string }>(packageUrl(endpoints.reorderDocs, packageId), {
                method: 'POST',
                body: JSON.stringify({ parent, ids }),
            });
            setMessage({ ok: true, text: response.message });
        } catch (error) {
            setTree(previous);
            setMessage({ ok: false, text: error instanceof Error ? error.message : 'The new order could not be saved.' });

            if (error instanceof ApiError && error.status === 409) {
                void loadTree();
            }
        } finally {
            setSaving(false);
        }
    }

    return (
        <section className={CARD_CLASS} aria-labelledby="artisanpack-ui-doc-order-heading">
            <h2 id="artisanpack-ui-doc-order-heading" className="text-base font-semibold text-base-content">
                Documentation order
            </h2>
            <p className="mt-1 text-sm text-base-content/60">
                Drag pages, or use the arrows, to reorder them within their section. Sections come from the repo's
                folder layout and can't be changed here.
            </p>

            {loadError !== null && (
                <div className="mt-4 flex flex-wrap items-center justify-between gap-2">
                    <p role="alert" className="text-sm text-error">
                        {loadError}
                    </p>
                    <button type="button" className="btn btn-sm" onClick={() => void loadTree()}>
                        Try again
                    </button>
                </div>
            )}
            {tree === null && loadError === null && <p className="mt-4 text-sm text-base-content/55">Loading…</p>}
            {tree !== null && tree.length === 0 && (
                <p className="mt-4 text-sm text-base-content/60">No documentation has been imported yet.</p>
            )}
            {tree !== null && tree.length > 0 && (
                <div className="mt-4" ref={listRef}>
                    <SiblingList nodes={tree} parent={0} disabled={saving} onReorder={save} />
                </div>
            )}

            <div aria-live="polite" className="mt-3 text-sm">
                {saving && <p className="text-base-content/60">Saving…</p>}
                {message !== null && <p className={message.ok ? 'text-success' : 'text-error'}>{message.text}</p>}
            </div>
        </section>
    );
}

function SiblingList({
    nodes,
    parent,
    disabled,
    onReorder,
}: {
    nodes: DocNode[];
    parent: number;
    disabled: boolean;
    onReorder: (parent: number, ids: number[], focus?: { id: number; direction: MoveDirection }) => void;
}) {
    const [dragged, setDragged] = useState<number | null>(null);
    const [over, setOver] = useState<number | null>(null);
    const ids = nodes.map((node) => node.id);

    function onDragStart(event: DragEvent<HTMLLIElement>, id: number) {
        event.stopPropagation();
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(id));
        setDragged(id);
    }

    function onDragOver(event: DragEvent<HTMLLIElement>, id: number) {
        // Only siblings accept the drop, so a page can't change parent.
        if (dragged === null) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        setOver(id);
    }

    function onDrop(event: DragEvent<HTMLLIElement>, id: number) {
        if (dragged === null) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const next = moveTo(ids, dragged, ids.indexOf(id));
        setDragged(null);
        setOver(null);

        if (next.join() !== ids.join()) {
            onReorder(parent, next);
        }
    }

    function move(id: number, direction: MoveDirection) {
        const index = ids.indexOf(id);
        const target = direction === 'up' ? index - 1 : index + 1;

        if (disabled || target < 0 || target >= ids.length) {
            return;
        }

        onReorder(parent, moveTo(ids, id, target), { id, direction });
    }

    return (
        <ol className={parent === 0 ? 'space-y-1' : 'mt-1 space-y-1 border-l border-base-300/60 pl-4'}>
            {nodes.map((node, index) => (
                <li
                    key={node.id}
                    draggable={!disabled}
                    onDragStart={(event) => onDragStart(event, node.id)}
                    onDragOver={(event) => onDragOver(event, node.id)}
                    onDragLeave={() => setOver((current) => (current === node.id ? null : current))}
                    onDrop={(event) => onDrop(event, node.id)}
                    onDragEnd={() => {
                        setDragged(null);
                        setOver(null);
                    }}
                    className={dragged === node.id ? 'opacity-50' : undefined}
                >
                    <div
                        className={`flex items-center gap-2 rounded-md border px-3 py-2 ${
                            over === node.id && dragged !== node.id ? 'border-primary' : 'border-base-300/60'
                        } ${disabled ? '' : 'cursor-grab'}`}
                    >
                        <span aria-hidden="true" className="select-none text-base-content/40">
                            ⠿
                        </span>
                        <span className="min-w-0 flex-1 truncate text-sm text-base-content">{node.title}</span>
                        <button
                            type="button"
                            className={`btn btn-ghost btn-xs ${disabled || index === 0 ? 'opacity-40' : ''}`}
                            aria-label={`Move ${node.title} up`}
                            aria-disabled={disabled || index === 0}
                            data-doc-id={node.id}
                            data-dir="up"
                            data-at-end={index === 0}
                            onClick={() => move(node.id, 'up')}
                        >
                            ↑
                        </button>
                        <button
                            type="button"
                            className={`btn btn-ghost btn-xs ${disabled || index === nodes.length - 1 ? 'opacity-40' : ''}`}
                            aria-label={`Move ${node.title} down`}
                            aria-disabled={disabled || index === nodes.length - 1}
                            data-doc-id={node.id}
                            data-dir="down"
                            data-at-end={index === nodes.length - 1}
                            onClick={() => move(node.id, 'down')}
                        >
                            ↓
                        </button>
                    </div>
                    {node.children.length > 0 && (
                        <SiblingList nodes={node.children} parent={node.id} disabled={disabled} onReorder={onReorder} />
                    )}
                </li>
            ))}
        </ol>
    );
}
