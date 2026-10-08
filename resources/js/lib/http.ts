/**
 * JSON fetch helper for the plugin's admin endpoints, mirroring the other
 * Keystone plugins: same-origin cookies, the page's CSRF token, and a typed
 * error carrying Laravel's message and validation errors.
 */

export class ApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly errors: Record<string, string[]> = {},
    ) {
        super(message);
    }
}

function csrfToken(): string {
    if (typeof document === 'undefined') {
        return '';
    }

    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

export async function apiFetch<T>(url: string, init: RequestInit = {}): Promise<T> {
    const headers = new Headers(init.headers);
    headers.set('Accept', 'application/json');
    headers.set('X-Requested-With', 'XMLHttpRequest');

    if (init.body && !headers.has('Content-Type')) {
        headers.set('Content-Type', 'application/json');
    }

    const token = csrfToken();
    if (token) {
        headers.set('X-CSRF-TOKEN', token);
    }

    const response = await fetch(url, { credentials: 'same-origin', ...init, headers });
    const text = await response.text();

    let payload: unknown = null;
    if (text.length > 0) {
        try {
            payload = JSON.parse(text) as unknown;
        } catch {
            payload = null;
        }
    }

    if (!response.ok) {
        const body = (payload ?? {}) as { message?: unknown; errors?: unknown };
        const message =
            typeof body.message === 'string' && body.message !== ''
                ? body.message
                : `Request failed (HTTP ${response.status}).`;
        const errors =
            body.errors && typeof body.errors === 'object' ? (body.errors as Record<string, string[]>) : {};

        throw new ApiError(message, response.status, errors);
    }

    return payload as T;
}

/** The id placeholder in the shared per-package endpoint templates. */
const PACKAGE_PLACEHOLDER = '__package__';

/**
 * Fill a per-package endpoint template with the record's id.
 */
export function packageUrl(template: string, packageId: number | string): string {
    return template.replace(PACKAGE_PLACEHOLDER, encodeURIComponent(String(packageId)));
}

/**
 * A timestamp in the admin's locale, or a dash when there isn't one.
 */
export function formatDateTime(iso: string | null): string {
    return iso === null ? '—' : new Date(iso).toLocaleString();
}
