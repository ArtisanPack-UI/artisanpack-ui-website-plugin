/**
 * Icon picker for the package `icon` field (an `icon_picker` custom field,
 * see `Support/IconPickerField.php`).
 *
 * Keystone's dynamic edit screen renders every custom field as a text
 * input, so the boot module mounts this next to it through the
 * `keystone.admin.dynamicContent.editorSections` filter, which hands over
 * the edit screen's Inertia form. Picking an icon writes the same
 * `values.icon` form value the text input shows, as `{set, name}` JSON, and
 * it saves with the rest of the record.
 *
 * The catalog comes from the plugin's icon endpoint, which lists every set
 * the visual editor's icon resolver serves, so anything picked here renders
 * in the `artisanpack/icon` block.
 */

import { useEffect, useMemo, useRef, useState } from 'react';

import { CARD_CLASS, SvgPreview, useAbilities, useSharedEndpoints } from '../components/ui';
import { apiFetch } from '../lib/http';
import type { CatalogIcon, CatalogSet, IconCatalogResponse, IconRef } from '../lib/types';

/** The package custom field this picker edits. */
export const ICON_FIELD_KEY = 'icon';

/**
 * The slice of the host's Inertia `useForm` object the picker needs.
 */
export interface EditForm {
    data: { values: Record<string, string> };
    setData: (key: 'values', value: Record<string, string>) => void;
}

const SEARCH_DELAY_MS = 250;

/**
 * The icon grid's columns, inline because the host only compiles the
 * Tailwind classes its own sources use, and an arbitrary `grid-cols-[…]`
 * class from this bundle wouldn't exist in its CSS.
 */
const ICON_GRID_STYLE = { gridTemplateColumns: 'repeat(auto-fill, minmax(5.5rem, 1fr))' } as const;

function parseIconRef(value: string | undefined): IconRef | null {
    if (!value) {
        return null;
    }

    try {
        const parsed = JSON.parse(value) as Partial<IconRef> | null;

        return parsed && typeof parsed.set === 'string' && typeof parsed.name === 'string'
            ? { set: parsed.set, name: parsed.name }
            : null;
    } catch {
        return null;
    }
}

function catalogUrl(base: string, query: string, set: string, page: number): string {
    const url = new URL(base, window.location.origin);

    if (query !== '') {
        url.searchParams.set('q', query);
    }
    if (set !== '') {
        url.searchParams.set('set', set);
    }
    url.searchParams.set('page', String(page));

    return url.toString();
}

export function IconPickerField({ form }: { form: EditForm }) {
    const can = useAbilities();
    const endpoints = useSharedEndpoints();
    const value = form.data.values[ICON_FIELD_KEY];
    const selected = useMemo(() => parseIconRef(value), [value]);
    const [selectedSvg, setSelectedSvg] = useState<string | null>(null);
    const [previewLoading, setPreviewLoading] = useState(false);
    const [open, setOpen] = useState(false);
    /** The icon whose preview `selectedSvg` holds, set once it has loaded, so picking one doesn't refetch it. */
    const previewFor = useRef<string | null>(null);

    const canUseCatalog = endpoints !== null && Object.values(can).some(Boolean);
    // A string, so the shared endpoints object being rebuilt on every
    // Inertia response doesn't refetch the preview.
    const iconsEndpoint = endpoints?.icons ?? null;
    const invalidValue = selected === null && (value ?? '').trim() !== '';

    // Look up the preview for the stored icon.
    useEffect(() => {
        const key = selected ? `${selected.set}:${selected.name}` : null;

        if (key !== null && key === previewFor.current) {
            setPreviewLoading(false);

            return;
        }

        setSelectedSvg(null);

        if (!selected || !canUseCatalog || iconsEndpoint === null) {
            previewFor.current = null;
            setPreviewLoading(false);

            return;
        }

        let cancelled = false;
        setPreviewLoading(true);

        apiFetch<IconCatalogResponse>(catalogUrl(iconsEndpoint, selected.name, selected.set, 1))
            .then((response) => {
                const match = response.icons.find((icon) => icon.name === selected.name && icon.set === selected.set);
                if (!cancelled) {
                    previewFor.current = key;
                    setSelectedSvg(match?.svg ?? null);
                }
            })
            .catch(() => undefined)
            .finally(() => {
                if (!cancelled) {
                    setPreviewLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [selected, canUseCatalog, iconsEndpoint]);

    if (!canUseCatalog || endpoints === null) {
        return null;
    }

    function choose(icon: CatalogIcon | null) {
        previewFor.current = icon ? `${icon.set}:${icon.name}` : null;
        form.setData('values', {
            ...form.data.values,
            [ICON_FIELD_KEY]: icon ? JSON.stringify({ set: icon.set, name: icon.name }) : '',
        });
        setSelectedSvg(icon?.svg ?? null);
        setPreviewLoading(false);
        setOpen(false);
    }

    return (
        <section className={CARD_CLASS}>
            <div className="flex flex-wrap items-center gap-4">
                <div className="grid h-14 w-14 place-items-center rounded-lg border border-base-300/60 bg-base-200/40 text-base-content">
                    {selectedSvg ? (
                        <SvgPreview svg={selectedSvg} className="h-8 w-8" />
                    ) : (
                        <span className="text-xs text-base-content/45">
                            {selected && previewLoading ? '…' : selected || invalidValue ? '?' : 'None'}
                        </span>
                    )}
                </div>
                <div className="min-w-0 flex-1">
                    <h2 className="text-base font-semibold text-base-content">Package icon</h2>
                    <p className="mt-0.5 text-sm text-base-content/60">
                        {selected ? (
                            <>
                                <code>{selected.set}</code> / <code>{selected.name}</code>
                                {!selectedSvg && !previewLoading && ' (not found in any registered icon set)'}
                            </>
                        ) : invalidValue ? (
                            <span className="text-error">Invalid icon value; pick an icon to replace it.</span>
                        ) : (
                            'No icon chosen. The single-package template shows it through the icon block.'
                        )}
                    </p>
                </div>
                <div className="flex gap-2">
                    <button type="button" className="btn btn-outline btn-sm" onClick={() => setOpen((current) => !current)} aria-expanded={open}>
                        {open ? 'Close' : selected ? 'Change icon' : 'Choose icon'}
                    </button>
                    {(selected || invalidValue) && (
                        <button type="button" className="btn btn-ghost btn-sm" onClick={() => choose(null)}>
                            Clear
                        </button>
                    )}
                </div>
            </div>

            {open && <IconBrowser endpoint={endpoints.icons} selected={selected} onChoose={choose} />}
        </section>
    );
}

function IconBrowser({
    endpoint,
    selected,
    onChoose,
}: {
    endpoint: string;
    selected: IconRef | null;
    onChoose: (icon: CatalogIcon) => void;
}) {
    const [query, setQuery] = useState('');
    const [debouncedQuery, setDebouncedQuery] = useState('');
    const [set, setSet] = useState(selected?.set ?? '');
    const [sets, setSets] = useState<CatalogSet[]>([]);
    const [icons, setIcons] = useState<CatalogIcon[]>([]);
    const [total, setTotal] = useState(0);
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const request = useRef(0);

    // A new search or set starts again from the first page, in the same
    // update so the old page is never fetched for the new search.
    useEffect(() => {
        const timer = window.setTimeout(() => {
            setDebouncedQuery(query.trim());
            setPage(1);
        }, SEARCH_DELAY_MS);

        return () => window.clearTimeout(timer);
    }, [query]);

    function chooseSet(prefix: string) {
        setSet(prefix);
        setPage(1);
    }

    useEffect(() => {
        const id = ++request.current;
        setLoading(true);
        setError(null);

        apiFetch<IconCatalogResponse>(catalogUrl(endpoint, debouncedQuery, set, page))
            .then((response) => {
                if (id !== request.current) {
                    return;
                }
                setSets(response.sets);
                setTotal(response.total);
                setIcons((current) => (page === 1 ? response.icons : [...current, ...response.icons]));
            })
            .catch((caught: unknown) => {
                if (id === request.current) {
                    setError(caught instanceof Error ? caught.message : 'The icons could not be loaded.');
                }
            })
            .finally(() => {
                if (id === request.current) {
                    setLoading(false);
                }
            });
    }, [endpoint, debouncedQuery, set, page]);

    return (
        <div className="mt-5 space-y-4 border-t border-base-300/60 pt-4">
            <input
                type="search"
                className="h-9 w-full rounded-md border border-base-300/60 bg-base-100 px-3 text-sm text-base-content outline-none focus:border-primary"
                placeholder="Search icons, e.g. puzzle"
                aria-label="Search icons"
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                // The picker sits inside the host's edit form, where Enter
                // would submit (and save) the whole record.
                onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                    }
                }}
            />

            <div className="flex flex-wrap gap-1.5" role="group" aria-label="Icon sets">
                {[{ prefix: '', label: 'All sets', count: 0 }, ...sets].map((option) => (
                    <button
                        key={option.prefix || 'all'}
                        type="button"
                        aria-pressed={set === option.prefix}
                        onClick={() => chooseSet(option.prefix)}
                        className={`rounded-full border px-3 py-1 text-xs ${
                            set === option.prefix
                                ? 'border-primary bg-primary/15 text-base-content'
                                : 'border-base-300/60 text-base-content/70 hover:text-base-content'
                        }`}
                    >
                        {option.label}
                        {option.prefix !== '' && <span className="ml-1 text-base-content/45">{option.count}</span>}
                    </button>
                ))}
            </div>

            {error && (
                <p role="alert" className="text-sm text-error">
                    {error}
                </p>
            )}

            {!error && !loading && icons.length === 0 && <p className="text-sm text-base-content/60">No icons match.</p>}

            <ul className="grid gap-2" style={ICON_GRID_STYLE}>
                {icons.map((icon) => {
                    const isSelected = selected?.set === icon.set && selected?.name === icon.name;

                    return (
                        <li key={`${icon.set}:${icon.name}`}>
                            <button
                                type="button"
                                onClick={() => onChoose(icon)}
                                aria-pressed={isSelected}
                                title={`${icon.set} / ${icon.name}`}
                                className={`flex w-full flex-col items-center gap-1.5 rounded-md border px-1 py-2 text-base-content ${
                                    isSelected ? 'border-primary bg-primary/15' : 'border-base-300/60 hover:border-primary'
                                }`}
                            >
                                <SvgPreview svg={icon.svg} />
                                <span className="w-full truncate text-center text-xs text-base-content/70">{icon.name}</span>
                            </button>
                        </li>
                    );
                })}
            </ul>

            <div className="flex items-center gap-3">
                {icons.length < total && (
                    <button type="button" className="btn btn-outline btn-sm" disabled={loading} onClick={() => setPage((current) => current + 1)}>
                        {loading ? 'Loading…' : 'Load more'}
                    </button>
                )}
                <span className="text-xs text-base-content/55" role="status">
                    {loading && icons.length === 0 ? 'Loading…' : `${icons.length} of ${total} icons`}
                </span>
            </div>
        </div>
    );
}
