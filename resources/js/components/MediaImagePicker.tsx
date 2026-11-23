/**
 * A small media library image picker for the Settings page's recap
 * featured image. The host's own media modal lives in its bundle and isn't
 * shared with plugins, so this lists images through the plugin's
 * `settings/recap-images` endpoint (see `Support/MediaImages.php`) instead:
 * the newest images, narrowed by a title search.
 *
 * @since 1.6.0
 */

import { useEffect, useId, useState } from 'react';

import { apiFetch } from '../lib/http';
import type { MediaImage } from '../lib/types';

export function MediaImagePicker({
    endpoint,
    value,
    error,
    onChange,
}: {
    endpoint: string;
    value: MediaImage | null;
    error?: string[];
    onChange: (image: MediaImage | null) => void;
}) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [images, setImages] = useState<MediaImage[] | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const searchId = useId();

    useEffect(() => {
        if (!open) {
            return;
        }

        let active = true;
        // Debounced, so typing a search sends one request rather than one
        // per keystroke.
        const timer = window.setTimeout(() => {
            const url = new URL(endpoint, window.location.origin);
            if (search.trim() !== '') {
                url.searchParams.set('search', search.trim());
            }

            apiFetch<{ images: MediaImage[] }>(url.toString())
                .then((response) => {
                    if (active) {
                        setImages(response.images);
                        setLoadError(null);
                    }
                })
                .catch((caught: unknown) => {
                    if (active) {
                        setLoadError(caught instanceof Error ? caught.message : 'The images could not be loaded.');
                    }
                });
        }, 250);

        return () => {
            active = false;
            window.clearTimeout(timer);
        };
    }, [endpoint, open, search]);

    return (
        <div className="space-y-2">
            <span className="text-sm font-medium text-base-content">Featured image</span>
            <div className="flex flex-wrap items-center gap-3">
                {value ? (
                    <div className="flex items-center gap-3">
                        {value.url ? (
                            <img src={value.url} alt="" className="h-16 w-28 rounded-md border border-base-300/60 object-cover" />
                        ) : (
                            <div className="grid h-16 w-28 place-items-center rounded-md border border-base-300/60 text-xs text-base-content/55">
                                No preview
                            </div>
                        )}
                        <span className="text-sm text-base-content">{value.title}</span>
                    </div>
                ) : (
                    <span className="text-sm text-base-content/60">No image chosen. Recap posts are created without one.</span>
                )}
                <button type="button" className="btn btn-outline btn-sm" aria-expanded={open} onClick={() => setOpen(!open)}>
                    {open ? 'Close' : value ? 'Change image' : 'Choose image'}
                </button>
                {value && (
                    <button type="button" className="btn btn-ghost btn-sm" onClick={() => onChange(null)}>
                        Remove
                    </button>
                )}
            </div>
            {error && <span className="block text-xs text-error">{error[0]}</span>}

            {open && (
                <div className="space-y-3 rounded-md border border-base-300/60 p-3">
                    <label htmlFor={searchId} className="sr-only">
                        Search images
                    </label>
                    <input
                        id={searchId}
                        type="search"
                        placeholder="Search images by title"
                        className="h-9 w-full rounded-md border border-base-300/60 bg-base-100 px-3 text-sm text-base-content outline-none focus:border-primary"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                    />
                    {loadError && <p className="text-sm text-error">{loadError}</p>}
                    {images === null && !loadError && <p className="text-sm text-base-content/60">Loading images…</p>}
                    {images !== null && images.length === 0 && (
                        <p className="text-sm text-base-content/60">No images match. Upload one in the media library first.</p>
                    )}
                    {images !== null && images.length > 0 && (
                        <ul className="grid grid-cols-2 gap-2 sm:grid-cols-4 md:grid-cols-6">
                            {images.map((image) => (
                                <li key={image.id}>
                                    <button
                                        type="button"
                                        aria-pressed={value?.id === image.id}
                                        onClick={() => {
                                            onChange(image);
                                            setOpen(false);
                                        }}
                                        className={`block w-full overflow-hidden rounded-md border text-left focus-visible:ring-2 focus-visible:ring-primary/60 focus-visible:outline-none ${
                                            value?.id === image.id ? 'border-primary' : 'border-base-300/60 hover:border-primary'
                                        }`}
                                    >
                                        {image.url ? (
                                            <img src={image.url} alt="" className="h-20 w-full object-cover" />
                                        ) : (
                                            <div className="grid h-20 place-items-center text-xs text-base-content/55">No preview</div>
                                        )}
                                        <span className="block truncate px-2 py-1 text-xs text-base-content">{image.title}</span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
}
