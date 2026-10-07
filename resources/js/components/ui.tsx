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

import type { Abilities, Nav } from '../lib/types';

export const CARD_CLASS =
    'rounded-[var(--radius-box)] border border-base-300/60 bg-base-100 p-6 shadow-[0_1px_2px_0_rgba(15,23,42,0.04)]';

const NAV_ITEMS: { key: keyof Nav; label: string; ability?: keyof Abilities }[] = [
    { key: 'board', label: 'Packages board' },
    { key: 'settings', label: 'Settings', ability: 'sync' },
];

const NO_ABILITIES: Abilities = { sync: false, issuesManage: false, statsView: false, apiTokensManage: false };

/**
 * The current user's plugin abilities from the `artisanpackUi.can` prop the
 * plugin shares with every admin page. Falls back to "nothing allowed" if
 * the prop is missing, so a gated surface fails closed.
 */
export function useAbilities(): Abilities {
    const { props } = usePage<{ artisanpackUi?: { can?: Abilities } }>();

    return props.artisanpackUi?.can ?? NO_ABILITIES;
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
 * Empty state for a surface whose real content lands in a later roadmap
 * phase. Keeps every expose mountable today so the federation wiring can be
 * verified end to end.
 */
export function ComingSoon({ title, roadmapItem, children }: { title: string; roadmapItem: string; children?: ReactNode }) {
    return (
        <section className={CARD_CLASS}>
            <h2 className="text-base font-semibold text-base-content">{title}</h2>
            <p className="mt-2 text-sm text-base-content/60">
                Planned for roadmap {roadmapItem}.
            </p>
            {children}
        </section>
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
