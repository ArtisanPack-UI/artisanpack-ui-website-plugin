/**
 * The issue modal (roadmap 5.3), opened from a board card: the issue's
 * rendered body and comment thread, and editing its title, body, labels,
 * milestone and assignees, closing or reopening it, and commenting.
 *
 * The markdown arrives rendered server side with raw HTML escaped and
 * unsafe links dropped (`Services/GitHub/GitHubIssues.php`), which is what
 * makes inserting it as HTML safe.
 *
 * Writes go through the GitHub App, so GitHub shows them as the App's bot
 * rather than the admin (roadmap open question 3); the modal says so.
 *
 * The dialog is portalled to `<body>`: on Edit Package the Issues tab sits
 * inside the host's `<form id="dynamic-content-form">`, and nested forms are
 * invalid DOM. React still bubbles synthetic events through a portal to its
 * React parents, so every form here also stops `submit` propagating, or the
 * host's submit handler would save the package.
 */

import { useCallback, useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { createPortal } from 'react-dom';

import { apiFetch, formatDateTime, issueUrl } from '../../lib/http';
import type { BoardEndpoints, IssueComment, IssueDetail, IssueOptions } from '../../lib/types';
import { LabelChip } from './labels';

/**
 * Minimal typography for the rendered markdown. The host's CSS resets
 * lists and headings, and the federated bundle ships no stylesheet.
 */
const MARKDOWN_CSS = `
.apui-markdown { overflow-wrap: anywhere; font-size: 0.875rem; line-height: 1.5; }
.apui-markdown > * + * { margin-top: 0.75em; }
.apui-markdown h1, .apui-markdown h2, .apui-markdown h3 { font-weight: 600; }
.apui-markdown h1 { font-size: 1.25em; } .apui-markdown h2 { font-size: 1.125em; }
.apui-markdown ul { list-style: disc; padding-left: 1.5em; }
.apui-markdown ol { list-style: decimal; padding-left: 1.5em; }
.apui-markdown a { color: var(--color-primary); text-decoration: underline; }
.apui-markdown code { font-family: ui-monospace, monospace; font-size: 0.85em; background: var(--color-base-200); padding: 0.1em 0.3em; border-radius: 0.25rem; }
.apui-markdown pre { background: var(--color-base-200); padding: 0.75em; border-radius: 0.375rem; overflow-x: auto; }
.apui-markdown pre code { background: none; padding: 0; }
.apui-markdown blockquote { border-left: 3px solid var(--color-base-300); padding-left: 0.75em; opacity: 0.8; }
.apui-markdown img { max-width: 100%; }
.apui-markdown table { border-collapse: collapse; } .apui-markdown th, .apui-markdown td { border: 1px solid var(--color-base-300); padding: 0.25em 0.5em; }
`;

interface Draft {
    title: string;
    body: string;
    labels: string[];
    milestone: number | null;
    assignees: string[];
}

function draftFrom(issue: IssueDetail): Draft {
    return {
        title: issue.title,
        body: issue.body,
        labels: issue.labels.map((label) => label.name),
        milestone: issue.milestone?.number ?? null,
        assignees: issue.assignees.map((user) => user.login),
    };
}

function sameSet(a: string[], b: string[]): boolean {
    return a.length === b.length && a.every((value) => b.includes(value));
}

export function IssueModal({
    endpoints,
    repo,
    number,
    onClose,
    onUpdated,
}: {
    endpoints: BoardEndpoints;
    /** `owner/name`. */
    repo: string;
    number: number;
    onClose: () => void;
    /** Called with the issue after every successful edit. */
    onUpdated: (issue: IssueDetail) => void;
}) {
    const dialog = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const [issue, setIssue] = useState<IssueDetail | null>(null);
    const [comments, setComments] = useState<IssueComment[]>([]);
    const [commentsTotal, setCommentsTotal] = useState(0);
    const [error, setError] = useState<string | null>(null);
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState<Draft | null>(null);
    const [options, setOptions] = useState<IssueOptions | null>(null);
    const [saving, setSaving] = useState(false);
    const [comment, setComment] = useState('');
    const [status, setStatus] = useState<string | null>(null);

    const issueEndpoint = issueUrl(endpoints.issue, repo, number);

    useEffect(() => {
        // Guarded: StrictMode runs effects twice, and some browsers throw on
        // showModal() for a dialog that's already open.
        if (dialog.current && !dialog.current.open) {
            dialog.current.showModal();
        }
    }, []);

    useEffect(() => {
        let active = true;

        apiFetch<IssueDetail>(issueEndpoint)
            .then((response) => {
                if (active) {
                    setIssue(response);
                    setComments(response.comments ?? []);
                    setCommentsTotal(response.commentsTotal ?? response.comments?.length ?? 0);
                }
            })
            .catch((loadError: unknown) => {
                if (active) {
                    setError(loadError instanceof Error ? loadError.message : 'Could not load the issue.');
                }
            });

        return () => {
            active = false;
        };
    }, [issueEndpoint]);

    const applyIssue = useCallback(
        (updated: IssueDetail) => {
            setIssue(updated);
            onUpdated(updated);
        },
        [onUpdated],
    );

    async function startEditing() {
        if (issue === null) {
            return;
        }

        setDraft(draftFrom(issue));
        setEditing(true);
        setError(null);

        if (options === null) {
            try {
                setOptions(await apiFetch<IssueOptions>(issueUrl(endpoints.options, repo)));
            } catch (loadError) {
                setError(loadError instanceof Error ? loadError.message : 'Could not load the labels, milestones and assignees.');
            }
        }
    }

    async function patch(changes: Record<string, unknown>, done: string) {
        setSaving(true);
        setError(null);

        try {
            const updated = await apiFetch<IssueDetail>(issueEndpoint, { method: 'PATCH', body: JSON.stringify(changes) });
            applyIssue(updated);
            setStatus(done);

            return true;
        } catch (saveError) {
            setError(saveError instanceof Error ? saveError.message : 'Could not save the issue.');

            return false;
        } finally {
            setSaving(false);
        }
    }

    async function save(event: FormEvent) {
        event.preventDefault();
        event.stopPropagation();

        if (issue === null || draft === null) {
            return;
        }

        const original = draftFrom(issue);
        const changes: Record<string, unknown> = {};

        if (draft.title.trim() !== original.title) {
            changes.title = draft.title.trim();
        }
        if (draft.body !== original.body) {
            changes.body = draft.body;
        }
        if (!sameSet(draft.labels, original.labels)) {
            changes.labels = draft.labels;
        }
        if (draft.milestone !== original.milestone) {
            changes.milestone = draft.milestone;
        }
        if (!sameSet(draft.assignees, original.assignees)) {
            changes.assignees = draft.assignees;
        }

        if (Object.keys(changes).length === 0) {
            setEditing(false);

            return;
        }

        if (await patch(changes, 'Issue saved.')) {
            setEditing(false);
        }
    }

    async function addComment(event: FormEvent) {
        event.preventDefault();
        event.stopPropagation();

        if (comment.trim() === '') {
            return;
        }

        setSaving(true);
        setError(null);

        try {
            const created = await apiFetch<IssueComment>(issueUrl(endpoints.comments, repo, number), {
                method: 'POST',
                body: JSON.stringify({ body: comment }),
            });
            setComments((current) => [...current, created]);
            setCommentsTotal((current) => current + 1);
            setComment('');
            setStatus('Comment added.');
        } catch (saveError) {
            setError(saveError instanceof Error ? saveError.message : 'Could not add the comment.');
        } finally {
            setSaving(false);
        }
    }

    function toggle(list: string[], value: string): string[] {
        return list.includes(value) ? list.filter((entry) => entry !== value) : [...list, value];
    }

    return createPortal(
        <dialog ref={dialog} className="modal" aria-labelledby={titleId} onClose={onClose}>
            <style>{MARKDOWN_CSS}</style>
            <div className="modal-box" style={{ width: '100%', maxWidth: '48rem' }}>
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <p className="text-xs text-base-content/55">
                            {repo}#{number}
                            {issue !== null && (
                                <span className={`badge badge-sm ml-2 ${issue.state === 'OPEN' ? 'badge-success' : 'badge-ghost'}`}>
                                    {issue.state === 'OPEN' ? 'Open' : 'Closed'}
                                </span>
                            )}
                        </p>
                        <h2 id={titleId} className="mt-1 text-lg font-semibold text-base-content">
                            {issue?.title ?? 'Loading issue…'}
                        </h2>
                    </div>
                    <button type="button" className="btn btn-ghost btn-sm" onClick={() => dialog.current?.close()}>
                        Close
                    </button>
                </div>

                <p className="mt-2 text-xs text-base-content/55">
                    Edits and comments are made by the ArtisanPack UI GitHub App, so GitHub shows them as the App's bot, not
                    your GitHub account.
                </p>

                {error !== null && (
                    <p role="alert" className="mt-3 rounded-md border border-error/40 px-3 py-2 text-sm text-error">
                        {error}
                    </p>
                )}
                <p aria-live="polite" className="sr-only">
                    {status ?? ''}
                </p>

                {issue === null ? (
                    error === null && <p className="mt-4 animate-pulse text-sm text-base-content/55">Loading…</p>
                ) : editing && draft !== null ? (
                    <form className="mt-4 space-y-4" onSubmit={save}>
                        <label className="block">
                            <span className="text-sm font-medium text-base-content">Title</span>
                            <input
                                className="input mt-1 w-full"
                                value={draft.title}
                                required
                                maxLength={256}
                                onChange={(event) => setDraft({ ...draft, title: event.target.value })}
                            />
                        </label>
                        <label className="block">
                            <span className="text-sm font-medium text-base-content">Body (markdown)</span>
                            <textarea
                                className="textarea mt-1 w-full font-mono text-sm"
                                rows={10}
                                value={draft.body}
                                onChange={(event) => setDraft({ ...draft, body: event.target.value })}
                            />
                        </label>

                        {options === null ? (
                            error === null && <p className="animate-pulse text-sm text-base-content/55">Loading options…</p>
                        ) : (
                            <div className="grid gap-4 md:grid-cols-3">
                                <fieldset>
                                    <legend className="text-sm font-medium text-base-content">Labels</legend>
                                    <div className="mt-1 space-y-1 overflow-y-auto" style={{ maxHeight: '12rem' }}>
                                        {options.labels.map((label) => (
                                            <label key={label.name} className="flex items-center gap-2 text-sm">
                                                <input
                                                    type="checkbox"
                                                    className="checkbox checkbox-sm"
                                                    checked={draft.labels.includes(label.name)}
                                                    onChange={() => setDraft({ ...draft, labels: toggle(draft.labels, label.name) })}
                                                />
                                                <LabelChip label={label} />
                                            </label>
                                        ))}
                                        {options.labels.length === 0 && <p className="text-xs text-base-content/55">None in this repo.</p>}
                                    </div>
                                </fieldset>
                                <label className="block">
                                    <span className="text-sm font-medium text-base-content">Milestone</span>
                                    <select
                                        className="select mt-1 w-full"
                                        value={draft.milestone ?? ''}
                                        onChange={(event) =>
                                            setDraft({ ...draft, milestone: event.target.value === '' ? null : Number(event.target.value) })
                                        }
                                    >
                                        <option value="">No milestone</option>
                                        {issue.milestone !== null &&
                                            !options.milestones.some((milestone) => milestone.number === issue.milestone?.number) && (
                                                <option value={issue.milestone.number}>{issue.milestone.title}</option>
                                            )}
                                        {options.milestones.map((milestone) => (
                                            <option key={milestone.number} value={milestone.number}>
                                                {milestone.title}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <fieldset>
                                    <legend className="text-sm font-medium text-base-content">Assignees</legend>
                                    <div className="mt-1 space-y-1 overflow-y-auto" style={{ maxHeight: '12rem' }}>
                                        {options.assignees.map((user) => (
                                            <label key={user.login} className="flex items-center gap-2 text-sm">
                                                <input
                                                    type="checkbox"
                                                    className="checkbox checkbox-sm"
                                                    checked={draft.assignees.includes(user.login)}
                                                    disabled={!draft.assignees.includes(user.login) && draft.assignees.length >= 10}
                                                    onChange={() => setDraft({ ...draft, assignees: toggle(draft.assignees, user.login) })}
                                                />
                                                {user.login}
                                            </label>
                                        ))}
                                    </div>
                                </fieldset>
                            </div>
                        )}

                        <div className="flex justify-end gap-2">
                            <button type="button" className="btn btn-ghost btn-sm" disabled={saving} onClick={() => setEditing(false)}>
                                Cancel
                            </button>
                            <button type="submit" className="btn btn-primary btn-sm" disabled={saving || draft.title.trim() === ''}>
                                {saving ? 'Saving…' : 'Save'}
                            </button>
                        </div>
                    </form>
                ) : (
                    <div className="mt-4 space-y-4">
                        <div className="flex flex-wrap items-center gap-2">
                            {issue.milestone !== null && <span className="badge badge-ghost badge-sm">{issue.milestone.title}</span>}
                            {issue.labels.map((label) => (
                                <LabelChip key={label.name} label={label} />
                            ))}
                            {issue.assignees.length > 0 && (
                                <span className="text-xs text-base-content/60">
                                    Assigned to {issue.assignees.map((user) => user.login).join(', ')}
                                </span>
                            )}
                        </div>

                        {issue.bodyHtml === '' ? (
                            <p className="text-sm italic text-base-content/55">No description.</p>
                        ) : (
                            <div className="apui-markdown text-base-content" dangerouslySetInnerHTML={{ __html: issue.bodyHtml }} />
                        )}

                        <div className="flex flex-wrap justify-between gap-2">
                            <a href={issue.url} target="_blank" rel="noreferrer" className="btn btn-ghost btn-sm">
                                Open on GitHub
                            </a>
                            <span className="flex gap-2">
                                <button
                                    type="button"
                                    className="btn btn-sm"
                                    disabled={saving}
                                    onClick={() =>
                                        issue.state === 'OPEN'
                                            ? patch({ state: 'closed', state_reason: 'completed' }, 'Issue closed.')
                                            : patch({ state: 'open', state_reason: 'reopened' }, 'Issue reopened.')
                                    }
                                >
                                    {issue.state === 'OPEN' ? 'Close issue' : 'Reopen issue'}
                                </button>
                                <button type="button" className="btn btn-primary btn-sm" disabled={saving} onClick={startEditing}>
                                    Edit
                                </button>
                            </span>
                        </div>
                    </div>
                )}

                {issue !== null && (
                    <section aria-label="Comments" className="mt-6 border-t border-base-300/60 pt-4">
                        <h3 className="text-sm font-semibold text-base-content">Comments ({Math.max(commentsTotal, comments.length)})</h3>
                        {commentsTotal > comments.length && (
                            <p className="mt-1 text-xs text-base-content/60">
                                Showing the latest {comments.length} of {commentsTotal} comments.{' '}
                                <a href={issue.url} target="_blank" rel="noopener noreferrer" className="link">
                                    Open on GitHub
                                </a>{' '}
                                to read the rest.
                            </p>
                        )}
                        <ol className="mt-3 space-y-3">
                            {comments.map((entry) => (
                                <li key={entry.id} className="rounded-md border border-base-300/60 p-3">
                                    <p className="text-xs text-base-content/60">
                                        <strong className="text-base-content">{entry.author?.login ?? 'ghost'}</strong> ·{' '}
                                        {formatDateTime(entry.createdAt)}
                                    </p>
                                    <div
                                        className="apui-markdown mt-2 text-base-content"
                                        dangerouslySetInnerHTML={{ __html: entry.bodyHtml }}
                                    />
                                </li>
                            ))}
                        </ol>
                        <form className="mt-3 space-y-2" onSubmit={addComment}>
                            <label className="block">
                                <span className="text-sm font-medium text-base-content">Add a comment</span>
                                <textarea
                                    className="textarea mt-1 w-full text-sm"
                                    rows={4}
                                    value={comment}
                                    onChange={(event) => setComment(event.target.value)}
                                />
                            </label>
                            <div className="flex justify-end">
                                <button type="submit" className="btn btn-primary btn-sm" disabled={saving || comment.trim() === ''}>
                                    Comment
                                </button>
                            </div>
                        </form>
                    </section>
                )}
            </div>
            <form method="dialog" className="modal-backdrop" onSubmit={(event) => event.stopPropagation()}>
                <button type="submit">Close</button>
            </form>
        </dialog>,
        document.body,
    );
}
