/**
 * "Create New Recap Post" on the Blog Posts screen, beside "New post",
 * through Keystone's `keystone.admin.posts.index.headerActions` filter (see
 * `boot.tsx`). On a Keystone without that filter the button simply never
 * appears.
 *
 * Shown only to admins with the recap permission, and only while the recap
 * can run (AI ready and consented, the feature on, the GitHub App set up),
 * from the `artisanpackUi.endpoints.recaps` prop. The modal is its own
 * chunk, loaded on first click.
 */

import { lazy, Suspense, useState } from 'react';

import { useSharedEndpoints } from '../ui';

const RecapModal = lazy(() => import('./RecapModal'));

export function RecapButton() {
    const recaps = useSharedEndpoints()?.recaps ?? null;
    const [open, setOpen] = useState(false);

    if (recaps === null || !recaps.available) {
        return null;
    }

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="inline-flex items-center gap-1.5 rounded-lg border border-base-300 bg-base-100 px-3 py-2 text-xs font-semibold text-base-content shadow-sm hover:bg-base-200 focus-visible:ring-2 focus-visible:ring-primary/60 focus-visible:ring-offset-2 focus-visible:outline-none"
            >
                <svg aria-hidden="true" viewBox="0 0 24 24" className="h-3.5 w-3.5" fill="none" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 3l1.9 4.6L18.5 9.5l-4.6 1.9L12 16l-1.9-4.6L5.5 9.5l4.6-1.9L12 3zM19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9L19 15z" />
                </svg>
                Create New Recap Post
            </button>
            {open && (
                <Suspense fallback={null}>
                    <RecapModal endpoints={recaps} onClose={() => setOpen(false)} />
                </Suspense>
            )}
        </>
    );
}
