/**
 * Boot module — federated remote `artisanpack-ui`, exposed as `./boot`.
 *
 * Keystone preloads this module (via the manifest's `bootModule` descriptor)
 * before the admin shell mounts its first page, so the `addFilter` side
 * effects here bind against the host's shared hooks registry in time to be
 * read.
 *
 * Contributes the Docs, Stats and Issues tabs, the sync status panel and
 * the icon picker to the `package` edit screen, the "Sync from docs"
 * button to the Packages list, and the dashboard widgets' bodies.
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

import { addFilter, doAction } from '@artisanpack-ui/hooks-js';
import { lazy, Suspense, type ComponentType, type ReactNode } from 'react';

import { SyncFromDocsButton } from './components/SyncFromDocsButton';
import { NoAccess, useAbilities } from './components/ui';
import { IconPickerField, type EditForm } from './fields/IconPickerField';
import { RegistryField } from './fields/RegistryField';
import type { Abilities, PackageTabProps, WidgetProps } from './lib/types';

/** The content type slug the plugin's seeder registers for packages. */
const PACKAGE_CONTENT_TYPE = 'package';

interface PackageTab {
    slug: string;
    title: string;
    component: string;
    order: number;
    /** The edit-screen slot the entry goes in. */
    slot: 'tabs' | 'sidebar-top';
    /** The plugin ability the tab needs (see `Support/Permissions.php`). */
    ability: keyof Abilities;
    load: () => Promise<{ default: ComponentType<PackageTabProps> }>;
}

const PACKAGE_TABS: PackageTab[] = [
    {
        slug: 'artisanpack-ui.package-sync',
        title: 'Docs site sync',
        component: 'artisanpack-ui.PackageSyncPanel',
        order: 10,
        slot: 'sidebar-top',
        ability: 'sync',
        load: () => import('./panels/PackageSyncPanel'),
    },
    {
        slug: 'artisanpack-ui.package-docs',
        title: 'Docs',
        component: 'artisanpack-ui.PackageDocsTab',
        order: 10,
        slot: 'tabs',
        ability: 'sync',
        load: () => import('./tabs/PackageDocsTab'),
    },
    {
        slug: 'artisanpack-ui.package-stats',
        title: 'Stats',
        component: 'artisanpack-ui.PackageStatsTab',
        order: 20,
        slot: 'tabs',
        ability: 'statsView',
        load: () => import('./tabs/PackageStatsTab'),
    },
    {
        slug: 'artisanpack-ui.package-issues',
        title: 'Issues',
        component: 'artisanpack-ui.PackageIssuesTab',
        order: 30,
        slot: 'tabs',
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
 * body (a sidebar panel is left out instead), and never loads the body's
 * chunk; the tab's endpoints enforce the same permission server side.
 */
function gated(tab: PackageTab): ComponentType<PackageTabProps> {
    const Lazy = lazy(tab.load);

    return function GatedTab(props: PackageTabProps) {
        const can = useAbilities();

        if (!can[tab.ability]) {
            return tab.slot === 'tabs' ? <NoAccess title={tab.title} /> : null;
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
 * Append the package tabs to the `tabs` slot, and the sync status panel to
 * the `sidebar-top` slot, on the `package` edit screen. Entries another
 * source already supplied (a future server-side registration, a rerun of
 * the filter) are not duplicated.
 */
addFilter('keystone.admin.panels.entries', (entries: unknown, context: unknown) => {
    const list = Array.isArray(entries) ? entries : [];
    const { slot, contentType } = (context ?? {}) as { slot?: string; contentType?: string };

    if (contentType !== PACKAGE_CONTENT_TYPE) {
        return list;
    }

    const present = new Set(list.map((entry) => (entry as { slug?: unknown }).slug));
    const additions = PACKAGE_TABS.filter((tab) => tab.slot === slot && !present.has(tab.slug)).map((tab) => ({
        slug: tab.slug,
        title: tab.title,
        component: tab.component,
        position: tab.slot === 'sidebar-top' ? 'top' : 'default',
        order: tab.order,
        props: {},
    }));

    if (additions.length === 0) {
        return list;
    }

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

/**
 * Mount the registry select and the icon picker on the `package` edit
 * screen. The host renders every custom field as a text input there and
 * ignores select choices and field-type editors, so they go in the
 * editor-sections slot, the one place that receives the edit form. Each
 * writes the same `values.*` entry its text input shows. See
 * `fields/RegistryField.tsx` and `fields/IconPickerField.tsx`.
 */
addFilter('keystone.admin.dynamicContent.editorSections', (node: unknown, context: unknown) => {
    const { form, contentType } = (context ?? {}) as { form?: EditForm; contentType?: string | { slug?: string } };
    const slug = typeof contentType === 'string' ? contentType : contentType?.slug;

    if (slug !== PACKAGE_CONTENT_TYPE || !form) {
        return node;
    }

    return (
        <>
            {node as ReactNode}
            <RegistryField form={form} />
            <IconPickerField form={form} />
        </>
    );
});

/**
 * Add "Sync from docs" to the admin top bar. The button renders only on
 * the Packages list, for users with the sync permission.
 */
addFilter('keystone.admin.topbar.right', (node: unknown) => (
    <>
        {node as ReactNode}
        <SyncFromDocsButton />
    </>
));

/**
 * The dashboard stats and board widgets' bodies (roadmap 4.3 and 5.5),
 * keyed by each PHP widget's `extendedInfo()['component']` (see
 * `src/Widgets`). Registered
 * through the host's `registerFederated` action rather than its widget
 * registry module, which isn't a shared federation singleton: the action
 * writes into the registry the dashboard grid actually reads. The host
 * wraps each in an error boundary and a Suspense boundary, so the lazy
 * bodies load on first render. Capability gating is server side: the
 * dashboard only hydrates widgets the user's permissions allow.
 */
const DASHBOARD_WIDGETS: Record<string, () => Promise<{ default: ComponentType<WidgetProps<never>> }>> = {
    ArtisanPackUIDownloadsKpiWidget: () => import('./widgets/DownloadsKpiWidget'),
    ArtisanPackUIDownloadsTrendWidget: () => import('./widgets/DownloadsTrendWidget'),
    ArtisanPackUIGitHubOverviewWidget: () => import('./widgets/GitHubOverviewWidget'),
    ArtisanPackUITopPackagesWidget: () => import('./widgets/TopPackagesWidget'),
    ArtisanPackUIReleaseFeedWidget: () => import('./widgets/ReleaseFeedWidget'),
    ArtisanPackUIBoardWidget: () => import('./widgets/BoardWidget'),
};

for (const [key, load] of Object.entries(DASHBOARD_WIDGETS)) {
    doAction('keystone.admin.dashboard.widget.registerFederated', key, lazy(load));
}

export {};
