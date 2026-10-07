/**
 * Shapes shared between the plugin's PHP page props and its React bundle.
 */

/**
 * Absolute URLs for the plugin's admin pages, built server-side from named
 * routes so the bundle never hard-codes a path.
 */
export interface Nav {
    board: string;
    settings: string;
}

/**
 * What the current user may do, from the plugin's permissions. Every page
 * receives it as `can`, and every admin page (including the host's package
 * edit screen) as the shared `artisanpackUi.can` prop.
 */
export interface Abilities {
    sync: boolean;
    issuesManage: boolean;
    statsView: boolean;
    apiTokensManage: boolean;
}

/**
 * The props every plugin admin page receives.
 */
export interface PluginPageProps {
    nav: Nav;
    can: Abilities;
}

/**
 * The saved integration settings as the Settings page sees them. Secrets
 * are write-only: only whether each one is stored is sent.
 */
export interface IntegrationSettings {
    docsBaseUrl: string | null;
    hasDocsApiToken: boolean;
    githubAppId: string | null;
    githubInstallationId: string | null;
    hasGitHubPrivateKey: boolean;
    githubOrganization: string;
    githubProjectNumber: number | null;
}

/**
 * The result of a "Test connection" check.
 */
export interface ConnectionCheck {
    ok: boolean;
    message: string;
    details: string[];
}

export interface SettingsPageProps extends PluginPageProps {
    settings: IntegrationSettings;
    endpoints: {
        update: string;
        testDocs: string;
        testGitHub: string;
    };
}

/**
 * What the host's `AdminEditSlot` hands every content-edit tab body: the
 * content type slug and the record being edited.
 */
export interface PanelContext {
    contentType: string;
    record: Record<string, unknown>;
}

/**
 * Props an Edit Package tab body receives from the host.
 */
export interface PackageTabProps {
    context: PanelContext;
}

/**
 * Plugin endpoints shared with every admin page as `artisanpackUi.endpoints`,
 * for the surfaces the boot module mounts inside host pages.
 */
export interface SharedEndpoints {
    icons: string;
    syncImport: string;
    syncVersions: string;
    syncIcons: string;
}

/**
 * The `artisanpackUi` prop the plugin shares with every admin page.
 */
export interface SharedPluginProps {
    can: Abilities;
    endpoints: SharedEndpoints;
}

/**
 * An icon reference: the `{set, name}` the visual editor's
 * `artisanpack/icon` block takes as `iconRef`.
 */
export interface IconRef {
    set: string;
    name: string;
}

/**
 * One icon from the plugin's icon catalog, with its SVG for the preview.
 */
export interface CatalogIcon extends IconRef {
    svg: string;
}

export interface CatalogSet {
    prefix: string;
    label: string;
    count: number;
}

export interface IconCatalogResponse {
    sets: CatalogSet[];
    perPage: number;
    total: number;
    icons: CatalogIcon[];
}

/**
 * What one sync step did (see `Services/Sync/SyncReport.php`).
 */
export interface SyncReport {
    created: number;
    updated: number;
    skipped: number;
    failed: number;
    docsUpdated: number;
    /** The cursor for a batched step's next batch, or null when done. */
    next: number | null;
    messages: string[];
}
