/**
 * Presentational pieces the plugin's admin pages and Edit Package tabs share.
 *
 * Styling uses the host admin's DaisyUI semantic tokens (`bg-base-100`,
 * `text-base-content`, …) so the pages track the admin's light / dark
 * theme. Those classes are compiled into the host's `app.css`; the federated
 * bundle carries no CSS of its own.
 */

import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

import type { Abilities, Nav, SharedEndpoints, SharedPluginProps } from '../lib/types';

export const CARD_CLASS =
    'rounded-[var(--radius-box)] border border-base-300/60 bg-base-100 p-6 shadow-[0_1px_2px_0_rgba(15,23,42,0.04)]';

const NAV_ITEMS: { key: keyof Nav; label: string; ability?: keyof Abilities }[] = [
    { key: 'board', label: 'Packages board' },
    { key: 'settings', label: 'Settings', ability: 'sync' },
];

const NO_ABILITIES: Abilities = { sync: false, issuesManage: false, statsView: false };

/**
 * The current user's plugin abilities from the `artisanpackUi.can` prop the
 * plugin shares with every admin page. Falls back to "nothing allowed" if
 * the prop is missing, so a gated surface fails closed.
 */
export function useAbilities(): Abilities {
    const { props } = usePage<{ artisanpackUi?: Partial<SharedPluginProps> }>();

    return props.artisanpackUi?.can ?? NO_ABILITIES;
}

/**
 * The plugin endpoints shared with every admin page, or null if the prop
 * is missing (the plugin's provider didn't boot), so callers render
 * nothing rather than call a guessed URL.
 */
export function useSharedEndpoints(): SharedEndpoints | null {
    const { props } = usePage<{ artisanpackUi?: Partial<SharedPluginProps> }>();

    return props.artisanpackUi?.endpoints ?? null;
}

/**
 * An SVG drawn in the current text colour. The markup is used as a CSS
 * mask image rather than inserted into the page, so nothing in it can
 * run, and the icon still follows the admin's light / dark theme.
 */
export function SvgPreview({ svg, className = 'h-6 w-6' }: { svg: string; className?: string }) {
    const image = `url("data:image/svg+xml;utf8,${encodeURIComponent(svg)}")`;

    return (
        <span
            aria-hidden="true"
            className={`inline-block shrink-0 bg-current ${className}`}
            style={{
                maskImage: image,
                WebkitMaskImage: image,
                maskRepeat: 'no-repeat',
                WebkitMaskRepeat: 'no-repeat',
                maskPosition: 'center',
                WebkitMaskPosition: 'center',
                maskSize: 'contain',
                WebkitMaskSize: 'contain',
            }}
        />
    );
}

/**
 * Page shell for the plugin's full admin pages: a heading, an optional
 * description, and the tab bar linking the plugin's pages together.
 */
export function PluginPage({
    nav,
    can,
    active,
    title,
    description,
    children,
}: {
    nav: Nav;
    can: Abilities;
    active: keyof Nav;
    title: string;
    description?: string;
    children: ReactNode;
}) {
    return (
        <div className="mx-auto max-w-7xl space-y-6 px-6 py-8">
            <header className="space-y-4">
                <div>
                    <h1 className="font-display text-2xl font-semibold text-base-content">{title}</h1>
                    {description && <p className="mt-2 text-sm text-base-content/60">{description}</p>}
                </div>
                <nav aria-label="ArtisanPack UI" className="flex flex-wrap gap-1 border-b border-base-300/60">
                    {NAV_ITEMS.filter((item) => !item.ability || can[item.ability]).map((item) => (
                        <Link
                            key={item.key}
                            href={nav[item.key]}
                            aria-current={active === item.key ? 'page' : undefined}
                            className={`-mb-px border-b-2 px-3 py-2 text-sm font-medium ${
                                active === item.key
                                    ? 'border-primary text-base-content'
                                    : 'border-transparent text-base-content/60 hover:text-base-content'
                            }`}
                        >
                            {item.label}
                        </Link>
                    ))}
                </nav>
            </header>
            {children}
        </div>
    );
}

/**
 * Shown in place of a surface the user's permissions don't cover. The
 * server enforces the same permission on the surface's endpoints; this only
 * keeps the UI from offering what would be refused.
 */
export function NoAccess({ title }: { title: string }) {
    return (
        <section className={CARD_CLASS}>
            <h2 className="text-base font-semibold text-base-content">{title}</h2>
            <p className="mt-2 text-sm text-base-content/60">
                You don't have permission to use this. Ask an administrator to grant it.
            </p>
        </section>
    );
}
