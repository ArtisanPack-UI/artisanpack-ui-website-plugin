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

/** The placeholders in the shared endpoint templates (see `ArtisanPackUIRoutes`). */
const PACKAGE_PLACEHOLDER = '__package__';
const ITEM_PLACEHOLDER = '__item__';
const REPO_PLACEHOLDER = '__repo__';
const ISSUE_PLACEHOLDER = '__issue__';

/**
 * Fill a per-package endpoint template with the record's id.
 */
export function packageUrl(template: string, packageId: number | string): string {
    return template.replace(PACKAGE_PLACEHOLDER, encodeURIComponent(String(packageId)));
}

/**
 * Fill the board move template with a card's project item id.
 */
export function boardItemUrl(template: string, itemId: string): string {
    return template.replace(ITEM_PLACEHOLDER, encodeURIComponent(itemId));
}

/**
 * Fill an issue endpoint template with an issue's repo and number. `repo`
 * is `owner/name`; the endpoints take the name, the owner being the org.
 */
export function issueUrl(template: string, repo: string, number?: number): string {
    const name = repo.includes('/') ? repo.slice(repo.indexOf('/') + 1) : repo;
    const url = template.replace(REPO_PLACEHOLDER, encodeURIComponent(name));

    return number === undefined ? url : url.replace(ISSUE_PLACEHOLDER, String(number));
}

/**
 * A timestamp in the admin's locale, or a dash when there isn't one.
 */
export function formatDateTime(iso: string | null): string {
    return iso === null ? '—' : new Date(iso).toLocaleString();
}
