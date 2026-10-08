/**
 * Number and date formatting shared by the Stats tab, the dashboard
 * widgets and the boards, in the admin's locale.
 */

const NUMBER = new Intl.NumberFormat();

/**
 * A count, or a dash when there isn't one.
 */
export function formatNumber(value: number | null | undefined): string {
    return value === null || value === undefined ? '—' : NUMBER.format(value);
}

/**
 * A calendar date (`YYYY-MM-DD`) or ISO timestamp as a local date, or a
 * dash when there isn't one. A bare date is read as local midnight, so it
 * doesn't shift a day west of UTC.
 */
export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return new Date(/^\d{4}-\d{2}-\d{2}$/.test(value) ? `${value}T00:00:00` : value).toLocaleDateString();
}
