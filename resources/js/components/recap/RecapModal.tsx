/**
 * The weekly recap modal, opened from "Create New Recap Post" on Blog Posts.
 *
 *   1. **Start**: the publish date (today if it's Friday, otherwise next
 *      Friday) and notes. A recap already in progress is offered to resume
 *      or start over first.
 *   2. **Working**: the server gathers the releases, merged pull requests
 *      and the notes' links, then the AI writes the outline. The modal polls
 *      the session until the step is done.
 *   3. **Outline**: the outline, what it was built from, and the
 *      conversation; a message asks the AI to revise it, and "Create Post"
 *      writes the draft.
 *   4. **Created**: the editor for the new draft opens.
 *
 * The session lives on the server, so closing the modal (Escape, Close, the
 * backdrop) keeps it; "Start over" discards it. A failed step shows its
 * error with a Retry.
 *
 * The outline arrives rendered server side with raw HTML escaped, which is
 * what makes inserting it as HTML safe. Portalled to `<body>`, like the
 * issue modal, so it can't nest inside a host form.
 */

import { router } from '@inertiajs/react';
import { useCallback, useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { createPortal } from 'react-dom';

import { apiFetch } from '../../lib/http';
import { MARKDOWN_CSS } from '../../lib/markdown';
import type { RecapEndpoints, RecapSession } from '../../lib/types';

/** How often a running step is polled, in milliseconds. */
const POLL_INTERVAL = 2000;

/** The longest wait between polls while polling keeps failing. */
const MAX_POLL_INTERVAL = 30000;

const INPUT_CLASS =
    'w-full rounded-md border border-base-300/60 bg-base-100 px-3 text-sm text-base-content outline-none focus:border-primary';

/** Today if it's a Friday, otherwise the next Friday, as `YYYY-MM-DD`. */
function nextFriday(today = new Date()): string {
    const date = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    date.setDate(date.getDate() + ((5 - date.getDay() + 7) % 7));

    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

/** What the server is doing, for the progress line. */
function workingLabel(session: RecapSession): string {
    switch (session.status) {
        case 'gathering':
            return 'Gathering the releases, merged pull requests and your links…';
        case 'outlining':
            return session.outline === null ? 'Writing the outline…' : 'Revising the outline…';
        case 'drafting':
            return 'Writing the post…';
        default:
            return '';
    }
}

export default function RecapModal({ endpoints, onClose }: { endpoints: RecapEndpoints; onClose: () => void }) {
    const dialog = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const dateId = useId();
    const notesId = useId();
    const messageId = useId();

    const [loading, setLoading] = useState(true);
    const [lookupFailed, setLookupFailed] = useState(false);
    const [lookupAttempt, setLookupAttempt] = useState(0);
    const [pollFailures, setPollFailures] = useState(0);
    const [resumable, setResumable] = useState<RecapSession | null>(null);
    const [session, setSession] = useState<RecapSession | null>(null);
    const [publishDate, setPublishDate] = useState(nextFriday);
    const [notes, setNotes] = useState('');
    const [message, setMessage] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

    useEffect(() => {
        // Guarded: StrictMode runs effects twice, and some browsers throw on
        // showModal() for a dialog that's already open.
        if (dialog.current && !dialog.current.open) {
            dialog.current.showModal();
        }
    }, []);

    useEffect(() => {
        let active = true;

        setLoading(true);
        setLookupFailed(false);
        setError(null);

        apiFetch<{ session: RecapSession | null }>(endpoints.current)
            .then((response) => {
                if (active) {
                    setResumable(response.session);
                }
            })
            .catch((loadError: unknown) => {
                // Not "no recap": offering a fresh start here would discard
                // the one in progress.
                if (active) {
                    setLookupFailed(true);
                    setError(loadError instanceof Error ? loadError.message : 'Could not check for a recap in progress.');
                }
            })
            .finally(() => {
                if (active) {
                    setLoading(false);
                }
            });

        return () => {
            active = false;
        };
    }, [endpoints.current, lookupAttempt]);

    // Poll while a step runs, backing off while requests fail.
    useEffect(() => {
        if (session === null || !session.busy) {
            return;
        }

        let active = true;
        const timer = window.setTimeout(() => {
            apiFetch<{ session: RecapSession }>(session.endpoints.show)
                .then((response) => {
                    if (active) {
                        if (pollFailures > 0) {
                            setPollFailures(0);
                            setError(null);
                        }
                        setSession(response.session);
                    }
                })
                .catch((pollError: unknown) => {
                    if (active) {
                        setError(pollError instanceof Error ? pollError.message : 'Lost track of the recap. Trying again…');
                        setPollFailures((failures) => failures + 1);
                    }
                });
        }, Math.min(POLL_INTERVAL * 2 ** pollFailures, MAX_POLL_INTERVAL));

        return () => {
            active = false;
            window.clearTimeout(timer);
        };
    }, [session, pollFailures]);

    // Open the editor once the post exists.
    useEffect(() => {
        if (session?.status === 'created' && session.editUrl) {
            router.visit(session.editUrl);
        }
    }, [session]);

    const send = useCallback(async (url: string, init: RequestInit = { method: 'POST' }): Promise<boolean> => {
        setSubmitting(true);
        setError(null);
        setFieldErrors({});

        try {
            const response = await apiFetch<{ session: RecapSession }>(url, init);
            setPollFailures(0);
            setSession(response.session);

            return true;
        } catch (sendError) {
            setError(sendError instanceof Error ? sendError.message : 'The request failed.');
            if (sendError && typeof sendError === 'object' && 'errors' in sendError) {
                setFieldErrors((sendError as { errors: Record<string, string[]> }).errors);
            }

            return false;
        } finally {
            setSubmitting(false);
        }
    }, []);

    async function start(event: FormEvent) {
        event.preventDefault();
        event.stopPropagation();

        await send(endpoints.store, { method: 'POST', body: JSON.stringify({ publish_date: publishDate, notes }) });
    }

    async function revise(event: FormEvent) {
        event.preventDefault();
        event.stopPropagation();

        if (session === null || message.trim() === '') {
            return;
        }

        if (await send(session.endpoints.message, { method: 'POST', body: JSON.stringify({ message }) })) {
            setMessage('');
        }
    }

    async function startOver() {
        const current = session ?? resumable;

        if (current !== null && !window.confirm('Discard this recap and start over?')) {
            return;
        }

        if (current !== null) {
            try {
                await apiFetch(current.endpoints.destroy, { method: 'DELETE' });
            } catch (discardError) {
                setError(discardError instanceof Error ? discardError.message : 'Could not discard the recap.');

                return;
            }
        }

        setSession(null);
        setResumable(null);
        setMessage('');
        setError(null);
    }

    function resume() {
        setSession(resumable);
        setResumable(null);
    }

    const working = session !== null && session.busy;
    const canAct = session !== null && !session.busy && session.outline !== null && session.status !== 'created' && !submitting;

    return createPortal(
        <dialog ref={dialog} className="modal" aria-labelledby={titleId} onClose={onClose}>
            <style>{MARKDOWN_CSS}</style>
            <div className="modal-box" style={{ width: '100%', maxWidth: '56rem' }}>
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h2 id={titleId} className="text-lg font-semibold text-base-content">
                            {session?.title ?? 'Create New Recap Post'}
                        </h2>
                        <p className="mt-0.5 text-sm text-base-content/60">
                            {session === null
                                ? 'Draft the weekly "This Week in ArtisanPack UI" post from the releases since the last recap and your notes.'
                                : 'Your recap is saved: close this any time and pick it up again later.'}
                        </p>
                    </div>
                    <button type="button" className="btn btn-ghost btn-sm" onClick={() => dialog.current?.close()}>
                        Close
                    </button>
                </div>

                {error && (
                    <div role="alert" className="mt-4 rounded-md border border-error/40 px-3 py-2 text-sm text-error">
                        {error}
                    </div>
                )}

                {loading && <p className="mt-6 text-sm text-base-content/60">Checking for a recap in progress…</p>}

                {!loading && lookupFailed && (
                    <div className="mt-6">
                        <button type="button" className="btn btn-primary btn-sm" onClick={() => setLookupAttempt((attempt) => attempt + 1)}>
                            Try again
                        </button>
                    </div>
                )}

                {!loading && !lookupFailed && session === null && resumable !== null && (
                    <div className="mt-6 space-y-4">
                        <p className="text-sm text-base-content">
                            You have a recap in progress: <strong>{resumable.title}</strong>.
                        </p>
                        <div className="flex gap-2">
                            <button type="button" className="btn btn-primary btn-sm" onClick={resume}>
                                Resume
                            </button>
                            <button type="button" className="btn btn-ghost btn-sm" onClick={startOver}>
                                Start over
                            </button>
                        </div>
                    </div>
                )}

                {!loading && !lookupFailed && session === null && resumable === null && (
                    <form className="mt-6 space-y-4" onSubmit={start}>
                        <div className="space-y-1.5">
                            <label htmlFor={dateId} className="text-sm font-medium text-base-content">
                                Publish date
                            </label>
                            <input
                                id={dateId}
                                type="date"
                                required
                                className={`${INPUT_CLASS} h-9 max-w-xs`}
                                value={publishDate}
                                onChange={(event) => setPublishDate(event.target.value)}
                            />
                            {fieldErrors.publish_date ? (
                                <span className="block text-xs text-error">{fieldErrors.publish_date[0]}</span>
                            ) : (
                                <span className="block text-xs text-base-content/55">
                                    Sets the title. The recap covers everything since the last recap was published.
                                </span>
                            )}
                        </div>
                        <div className="space-y-1.5">
                            <label htmlFor={notesId} className="text-sm font-medium text-base-content">
                                Notes
                            </label>
                            <textarea
                                id={notesId}
                                rows={8}
                                className={`${INPUT_CLASS} py-2`}
                                value={notes}
                                placeholder={'What to include beyond the releases (links to PRs, issues or posts are read), what to emphasize, and how to organize it.\n\ne.g. Lead with the Forms file uploads. Mention the new docs search. Group the patch releases at the end.'}
                                onChange={(event) => setNotes(event.target.value)}
                            />
                            {fieldErrors.notes && <span className="block text-xs text-error">{fieldErrors.notes[0]}</span>}
                        </div>
                        <div className="flex justify-end">
                            <button type="submit" className="btn btn-primary btn-sm" disabled={submitting}>
                                {submitting ? 'Starting…' : 'Gather and outline'}
                            </button>
                        </div>
                    </form>
                )}

                {session !== null && (
                    <div className="mt-6 space-y-5">
                        <div role="status" aria-live="polite">
                            {working && (
                                <p className="flex items-center gap-2 text-sm text-base-content/70">
                                    <span className="loading loading-spinner loading-xs" aria-hidden="true" />
                                    {workingLabel(session)}
                                </p>
                            )}
                            {session.status === 'created' && (
                                <p className="text-sm text-success">The draft is ready. Opening the editor…</p>
                            )}
                        </div>

                        {session.status === 'failed' && (
                            <div className="flex flex-wrap items-center gap-3 rounded-md border border-error/40 px-3 py-2">
                                <p className="text-sm text-error">{session.error ?? 'The last step failed.'}</p>
                                <button type="button" className="btn btn-outline btn-sm" disabled={submitting} onClick={() => send(session.endpoints.retry)}>
                                    Retry
                                </button>
                            </div>
                        )}

                        {session.window && <RecapMaterial session={session} />}

                        {session.outlineHtml !== null && (
                            <section aria-label="Outline" className="rounded-md border border-base-300/60 p-4">
                                <div className="apui-markdown" dangerouslySetInnerHTML={{ __html: session.outlineHtml }} />
                            </section>
                        )}

                        {session.messages.length > 0 && (
                            <section aria-label="Conversation" className="space-y-2">
                                {session.messages.map((entry, index) => (
                                    <div
                                        key={index}
                                        className={`rounded-md px-3 py-2 text-sm ${
                                            entry.role === 'user' ? 'ml-8 bg-primary/10 text-base-content' : 'mr-8 bg-base-200 text-base-content'
                                        }`}
                                    >
                                        <span className="sr-only">{entry.role === 'user' ? 'You: ' : 'AI: '}</span>
                                        {entry.content}
                                    </div>
                                ))}
                            </section>
                        )}

                        {session.outline !== null && session.status !== 'created' && (
                            <form className="space-y-2" onSubmit={revise}>
                                <label htmlFor={messageId} className="text-sm font-medium text-base-content">
                                    Ask for changes
                                </label>
                                <textarea
                                    id={messageId}
                                    rows={3}
                                    className={`${INPUT_CLASS} py-2`}
                                    value={message}
                                    placeholder="e.g. Move Forms to the top and drop the CI section."
                                    disabled={!canAct}
                                    onChange={(event) => setMessage(event.target.value)}
                                />
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <button type="button" className="btn btn-ghost btn-sm" disabled={working || submitting} onClick={startOver}>
                                        Start over
                                    </button>
                                    <div className="flex gap-2">
                                        <button type="submit" className="btn btn-outline btn-sm" disabled={!canAct || message.trim() === ''}>
                                            Revise outline
                                        </button>
                                        <button type="button" className="btn btn-primary btn-sm" disabled={!canAct} onClick={() => send(session.endpoints.post)}>
                                            Create Post
                                        </button>
                                    </div>
                                </div>
                            </form>
                        )}

                        {session.outline === null && session.status === 'failed' && (
                            <button type="button" className="btn btn-ghost btn-sm" disabled={submitting} onClick={startOver}>
                                Start over
                            </button>
                        )}
                    </div>
                )}
            </div>
            <form method="dialog" className="modal-backdrop" onSubmit={(event) => event.stopPropagation()}>
                <button type="submit">Close</button>
            </form>
        </dialog>,
        document.body,
    );
}

/**
 * What the recap is built from: the window, the releases, how many merged
 * pull requests and links were read, and anything that couldn't be.
 */
function RecapMaterial({ session }: { session: RecapSession }) {
    if (session.window === null) {
        return null;
    }

    return (
        <details className="rounded-md border border-base-300/60 px-4 py-3 text-sm">
            <summary className="cursor-pointer text-base-content">
                {session.releases.length} release{session.releases.length === 1 ? '' : 's'}, {session.pullRequestCount} merged pull request
                {session.pullRequestCount === 1 ? '' : 's'} and {session.sources.length} link{session.sources.length === 1 ? '' : 's'}, since{' '}
                {formatDate(session.window.start)}
                {session.warnings.length > 0 && <span className="text-warning"> · {session.warnings.length} couldn't be read</span>}
            </summary>
            <div className="mt-3 space-y-3 text-base-content/80">
                <p>
                    {session.window.previousRecap
                        ? `Since "${session.window.previousRecap.title}" went out.`
                        : 'No earlier recap was found, so this covers the seven days before the publish date.'}
                </p>
                {session.releases.length > 0 ? (
                    <ul className="list-disc space-y-0.5 pl-5">
                        {session.releases.map((release) => (
                            <li key={`${release.package}-${release.tag}`}>
                                <a href={release.url} target="_blank" rel="noreferrer" className="underline">
                                    {release.package} {release.tag}
                                </a>{' '}
                                <span className="text-base-content/55">{formatDate(release.publishedAt)}</span>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p>No stable releases in this window.</p>
                )}
                {session.sources.length > 0 && (
                    <ul className="list-disc space-y-0.5 pl-5">
                        {session.sources.map((source) => (
                            <li key={source.url}>{source.title || source.url}</li>
                        ))}
                    </ul>
                )}
                {session.warnings.length > 0 && (
                    <ul className="list-disc space-y-0.5 pl-5 text-warning">
                        {session.warnings.map((warning) => (
                            <li key={warning}>{warning}</li>
                        ))}
                    </ul>
                )}
            </div>
        </details>
    );
}
