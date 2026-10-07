/**
 * Boot module — federated remote `artisanpack-ui`, exposed as `./boot`.
 *
 * Keystone preloads this module (via the manifest's `bootModule` descriptor)
 * before the admin shell mounts its first page, so the `addFilter` side
 * effects here bind against the host's shared hooks registry in time to be
 * read.
 *
 * Contributes the Docs, Stats and Issues tabs to the `package` edit screen.
 * The server-side `ap.cmsFramework.admin.contentEdit.tabs` filter can't do it
 * today: the host's dynamic content-type edit controller never ships the
 * `contentEdit` Inertia prop, so `AdminEditSlot` seeds every slot with `[]`
 * there. The client-side `keystone.admin.panels.entries` filter runs on that
 * empty seed regardless, so the tabs are injected here instead, and
 * `keystone.admin.panels.resolved` hands back the component for each entry.
 * See the 0.1 spike notes in `plans/roadmap.md`.
 *
 * Passing component references in-process — rather than a
 * `{remote, entry, module}` descriptor — skips a second federation fetch and
 * needs no knowledge of the remote's entry URL: React is a shared federation
 * singleton, so the references render into the host's React runtime.
 */

import { addFilter } from '@artisanpack-ui/hooks-js';
import { lazy, Suspense, type ComponentType } from 'react';

import { NoAccess, useAbilities } from './components/ui';
import type { Abilities, PackageTabProps } from './lib/types';

/** The content type slug the plugin's seeder registers for packages. */
const PACKAGE_CONTENT_TYPE = 'package';

interface PackageTab {
    slug: string;
    title: string;
    component: string;
    order: number;
    /** The plugin ability the tab needs (see `Support/Permissions.php`). */
    ability: keyof Abilities;
    load: () => Promise<{ default: ComponentType<PackageTabProps> }>;
}

const PACKAGE_TABS: PackageTab[] = [
    {
        slug: 'artisanpack-ui.package-docs',
        title: 'Docs',
        component: 'artisanpack-ui.PackageDocsTab',
        order: 10,
        ability: 'sync',
        load: () => import('./tabs/PackageDocsTab'),
    },
    {
        slug: 'artisanpack-ui.package-stats',
        title: 'Stats',
        component: 'artisanpack-ui.PackageStatsTab',
        order: 20,
        ability: 'statsView',
        load: () => import('./tabs/PackageStatsTab'),
    },
    {
        slug: 'artisanpack-ui.package-issues',
        title: 'Issues',
        component: 'artisanpack-ui.PackageIssuesTab',
        order: 30,
        ability: 'issuesManage',
        load: () => import('./tabs/PackageIssuesTab'),
    },
];

/**
 * Wrap a lazily loaded tab in its own Suspense boundary, behind its
 * ability. The host mounts built-in-resolved panels without a boundary, so
 * a bare `lazy()` component would suspend up to the nearest boundary above
 * the whole edit screen.
 *
 * The ability check lives here, in the rendered tab, because the entries
 * filter below runs inside the host's render and can't read page props.
 * A user without the ability sees the tab with a notice instead of its
 * body, and never loads the body's chunk; the tab's endpoints enforce the
 * same permission server side.
 */
function gated(tab: PackageTab): ComponentType<PackageTabProps> {
    const Lazy = lazy(tab.load);

    return function GatedTab(props: PackageTabProps) {
        const can = useAbilities();

        if (!can[tab.ability]) {
            return <NoAccess title={tab.title} />;
        }

        return (
            <Suspense
                fallback={
                    <div className="animate-pulse rounded-lg border border-base-300/60 bg-base-200/40 px-4 py-6 text-center text-xs text-base-content/55">
                        Loading…
                    </div>
                }
            >
                <Lazy {...props} />
            </Suspense>
        );
    };
}

/** Component identifier → resolved component, built once at boot. */
const TAB_COMPONENTS = new Map<string, ComponentType<PackageTabProps>>(
    PACKAGE_TABS.map((tab) => [tab.component, gated(tab)]),
);

/**
 * Append the package tabs to the `tabs` slot on the `package` edit screen.
 * Entries another source already supplied (a future server-side
 * registration, a rerun of the filter) are not duplicated.
 */
addFilter('keystone.admin.panels.entries', (entries: unknown, context: unknown) => {
    const list = Array.isArray(entries) ? entries : [];
    const { slot, contentType } = (context ?? {}) as { slot?: string; contentType?: string };

    if (slot !== 'tabs' || contentType !== PACKAGE_CONTENT_TYPE) {
        return list;
    }

    const present = new Set(list.map((entry) => (entry as { slug?: unknown }).slug));
    const additions = PACKAGE_TABS.filter((tab) => !present.has(tab.slug)).map((tab) => ({
        slug: tab.slug,
        title: tab.title,
        component: tab.component,
        position: 'default',
        order: tab.order,
        props: {},
    }));

    return [...list, ...additions].sort(
        (a, b) => ((a as { order?: number }).order ?? 50) - ((b as { order?: number }).order ?? 50),
    );
});

/**
 * Return the tab component when the host resolves one of our identifiers,
 * otherwise pass the incoming value through so a host-owned component (or
 * another plugin's) keeps winning.
 */
addFilter('keystone.admin.panels.resolved', (component: unknown, entry: unknown) => {
    if (component) {
        return component;
    }

    const identifier = (entry as { component?: unknown } | null)?.component;

    return typeof identifier === 'string' ? (TAB_COMPONENTS.get(identifier) ?? component) : component;
});

export {};
