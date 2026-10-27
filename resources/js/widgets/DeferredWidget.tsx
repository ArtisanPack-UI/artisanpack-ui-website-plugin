/**
 * Loads a dashboard widget's payload after the dashboard has rendered.
 *
 * The host builds every widget's payload while it renders the dashboard,
 * so a dashboard full of the plugin's widgets could run past the server's
 * timeout. Each PHP widget's `getData()` now returns only `{ deferred }`,
 * the URL of its payload (see `src/Services/Dashboard/DashboardWidgetData.php`).
 * {@link deferredWidget} wraps a widget body to show a skeleton in its
 * place, fetch that URL, and render the body with the payload.
 *
 * A payload can answer `{ pending: true }` while the server is still
 * reading what it needs in the background (the board widget, before its
 * first read of the project finishes). The skeleton then stays up and the
 * payload is fetched again every {@link PENDING_RETRY_MS}.
 */

import { lazy, Suspense, useCallback, useEffect, useState, type ComponentType } from 'react';

import { apiFetch } from '../lib/http';
import type { WidgetProps } from '../lib/types';

/** The rough shape a widget's skeleton takes while it loads. */
export type SkeletonShape = 'kpi' | 'chart' | 'list';

type WidgetState =
    | { status: 'loading' }
    | { status: 'loaded'; data: unknown }
    | { status: 'failed'; message: string };

const BAR_CLASS = 'rounded bg-base-300/60';

/** How long to wait before fetching a pending payload again. */
const PENDING_RETRY_MS = 5_000;

/**
 * How many times a pending payload is fetched again before giving up:
 * for as long as the server lets a background read run
 * (`BoardSummary::REFRESH_SECONDS`, two minutes).
 */
const PENDING_RETRIES = 24;

function WidgetSkeleton({ shape }: { shape: SkeletonShape }) {
    return (
        <div className="animate-pulse space-y-2" role="status">
            <span className="sr-only">Loading…</span>
            <div className={`h-3 w-1/3 ${BAR_CLASS}`} />
            {shape === 'kpi' && <div className={`h-8 w-1/2 ${BAR_CLASS}`} />}
            {shape === 'chart' && <div className={`h-32 w-full ${BAR_CLASS}`} />}
            {shape === 'list' && (
                <>
                    <div className={`h-4 w-full ${BAR_CLASS}`} />
                    <div className={`h-4 w-3/4 ${BAR_CLASS}`} />
                    <div className={`h-4 w-2/3 ${BAR_CLASS}`} />
                </>
            )}
        </div>
    );
}

/**
 * The payload URL a deferred widget's `data` carries, or null when `data`
 * is the payload itself.
 */
function deferredUrl(data: unknown): string | null {
    if (typeof data !== 'object' || data === null) {
        return null;
    }

    const url = (data as { deferred?: unknown }).deferred;

    return typeof url === 'string' ? url : null;
}

function isPending(data: unknown): boolean {
    return typeof data === 'object' && data !== null && (data as { pending?: unknown }).pending === true;
}

/**
 * The payload at `url`, refetched when the URL changes: changing a
 * widget's settings re-renders the dashboard with a new one.
 */
function useWidgetPayload(url: string | null): { state: WidgetState; reload: () => void } {
    const [state, setState] = useState<WidgetState>({ status: 'loading' });
    const [attempt, setAttempt] = useState(0);

    useEffect(() => {
        if (url === null) {
            return;
        }

        let cancelled = false;
        let retry: ReturnType<typeof setTimeout> | undefined;
        setState({ status: 'loading' });

        const load = (retriesLeft: number) => {
            apiFetch<unknown>(url)
                .then((data) => {
                    if (cancelled) {
                        return;
                    }

                    if (!isPending(data)) {
                        setState({ status: 'loaded', data });
                    } else if (retriesLeft > 0) {
                        retry = setTimeout(() => load(retriesLeft - 1), PENDING_RETRY_MS);
                    } else {
                        setState({ status: 'failed', message: 'This widget is still loading. Try again in a moment.' });
                    }
                })
                .catch((error: unknown) => {
                    if (!cancelled) {
                        setState({
                            status: 'failed',
                            message: error instanceof Error ? error.message : 'This widget couldn’t be loaded.',
                        });
                    }
                });
        };

        load(PENDING_RETRIES);

        return () => {
            cancelled = true;
            clearTimeout(retry);
        };
    }, [url, attempt]);

    const reload = useCallback(() => setAttempt((count) => count + 1), []);

    return { state, reload };
}

/**
 * Wrap a lazily loaded widget body so it fetches its own payload. The
 * body's chunk downloads while the payload is fetched, not after.
 */
export function deferredWidget(
    load: () => Promise<{ default: ComponentType<WidgetProps<never>> }>,
    shape: SkeletonShape,
): ComponentType<WidgetProps<unknown>> {
    // Each body narrows `data` to its own payload, which only the server knows.
    const Body = lazy(load) as unknown as ComponentType<WidgetProps<unknown>>;

    return function DeferredWidget(props: WidgetProps<unknown>) {
        const url = deferredUrl(props.data);
        const { state, reload } = useWidgetPayload(url);

        useEffect(() => {
            // A failed download is reported by `lazy()` when the body renders.
            load().catch(() => undefined);
        }, []);

        const skeleton = <WidgetSkeleton shape={shape} />;

        // A payload served inline, as before deferral, renders as it is.
        if (url === null) {
            return (
                <Suspense fallback={skeleton}>
                    <Body {...props} />
                </Suspense>
            );
        }

        if (state.status === 'loading') {
            return skeleton;
        }

        if (state.status === 'failed') {
            return (
                <div className="space-y-2">
                    <p className="text-sm text-base-content/60">{state.message}</p>
                    <button type="button" className="btn btn-sm" onClick={reload}>
                        Try again
                    </button>
                </div>
            );
        }

        return (
            <Suspense fallback={skeleton}>
                <Body {...props} data={state.data} />
            </Suspense>
        );
    };
}
