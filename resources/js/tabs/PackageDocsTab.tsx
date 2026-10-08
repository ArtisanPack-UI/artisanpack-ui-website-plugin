/**
 * Edit Package → Docs tab — federated remote `artisanpack-ui`, exposed as
 * `./package-docs`, mounted on the `package` edit screen by `../boot.tsx`.
 *
 * Manages the package's docs on the docs site (roadmap 3.1–3.2):
 *
 *   - "Import documentation" / "Import changelog" queue the docs site's
 *     imports. Imports run on the docs site's queue, so the tab polls the
 *     status until neither is queued, then shows done or failed with the
 *     last-imported time.
 *   - The documentation tree reorders siblings within their parent, by
 *     drag and drop or the move buttons. Each move is shown at once and
 *     saved in the background; a failed save puts the old order back.
 *     Parents can't change here: the docs site takes them from the repo's
 *     folder layout.
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

    const loadStatus = useCallback(async () => {
        try {
            setStatus(await apiFetch<DocsStatusResponse>(packageUrl(endpoints.docsStatus, packageId)));
            setError(null);
        } catch (loadError) {
            setError(loadError instanceof Error ? loadError.message : 'Could not load the import status.');
        }
    }, [endpoints.docsStatus, packageId]);

    useEffect(() => {
        void loadStatus();
    }, [loadStatus]);

    const queued =
        status?.imports?.docs.status === 'queued' || status?.imports?.changelog.status === 'queued';

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
            const response = await apiFetch<{ message: string }>(url, { method: 'POST' });

            setNotice(response.message);
            setStatus((current) =>
                current?.imports
                    ? { ...current, imports: { ...current.imports, [kind]: { ...current.imports[kind], status: 'queued', error: null } } }
                    : current,
            );
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

            {status?.imports && (
                <ul className="mt-4 divide-y divide-base-300/60">
                    {IMPORTS.map((item) => (
                        <ImportRow
                            key={item.kind}
                            label={item.label}
                            button={item.button}
                            state={status.imports![item.kind]}
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

function ReorderCard({ endpoints, packageId }: { endpoints: PackageEndpoints; packageId: number }) {
    const [tree, setTree] = useState<DocNode[] | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);
    const treeRef = useRef<DocNode[] | null>(null);
    treeRef.current = tree;

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

    async function save(parent: number, ids: number[]) {
        const previous = treeRef.current;

        if (previous === null) {
            return;
        }

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

            {loadError !== null && <p className="mt-4 text-sm text-error">{loadError}</p>}
            {tree === null && loadError === null && <p className="mt-4 text-sm text-base-content/55">Loading…</p>}
            {tree !== null && tree.length === 0 && (
                <p className="mt-4 text-sm text-base-content/60">No documentation has been imported yet.</p>
            )}
            {tree !== null && tree.length > 0 && (
                <div className="mt-4">
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
    onReorder: (parent: number, ids: number[]) => void;
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

    function move(id: number, offset: number) {
        onReorder(parent, moveTo(ids, id, ids.indexOf(id) + offset));
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
                            className="btn btn-ghost btn-xs"
                            aria-label={`Move ${node.title} up`}
                            disabled={disabled || index === 0}
                            onClick={() => move(node.id, -1)}
                        >
                            ↑
                        </button>
                        <button
                            type="button"
                            className="btn btn-ghost btn-xs"
                            aria-label={`Move ${node.title} down`}
                            disabled={disabled || index === nodes.length - 1}
                            onClick={() => move(node.id, 1)}
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
