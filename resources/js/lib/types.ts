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
    settingsManage: boolean;
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
    package: PackageEndpoints;
    board: BoardEndpoints;
}

/**
 * The global board and the templates a card's move and issue modal fill
 * in; `boardUrl()` and `issueUrl()` in `./http` fill them.
 */
export interface BoardEndpoints {
    index: string;
    move: string;
    issue: string;
    comments: string;
    options: string;
}

/**
 * Per-package endpoint templates; `packageUrl()` in `./http` fills in the
 * record id.
 */
export interface PackageEndpoints {
    syncStatus: string;
    syncNow: string;
    docsStatus: string;
    docsTree: string;
    importDocs: string;
    importChangelog: string;
    reorderDocs: string;
    stats: string;
    board: string;
}

/**
 * How a package's last sync went (see `PackageSyncController::status()`).
 */
export interface PackageSyncStatus {
    lastSyncedAt: string | null;
    lastCheckedAt: string | null;
    lastError: string | null;
    linked: boolean;
}

/**
 * The docs site's last docs or changelog import for a package.
 */
export interface DocsImport {
    status: 'queued' | 'succeeded' | 'failed' | null;
    error: string | null;
    importedAt: string | null;
}

export interface DocsStatusResponse {
    linked: boolean;
    imports: { docs: DocsImport; changelog: DocsImport } | null;
}

/**
 * One page in a package's documentation tree on the docs site.
 */
export interface DocNode {
    id: number;
    title: string;
    slug: string;
    parent: number;
    menuOrder: number;
    children: DocNode[];
}

/**
 * One day's snapshot on the Stats tab's trend charts. Null means the
 * source couldn't be read that day.
 */
export interface StatPoint {
    date: string;
    downloadsDaily: number | null;
    downloadsMonthly: number | null;
    downloadsTotal: number | null;
    stars: number | null;
    forks: number | null;
    watchers: number | null;
    openIssues: number | null;
    openPullRequests: number | null;
    dependents: number | null;
}

export interface PackageStatsResponse {
    live: {
        downloads: { daily: number | null; monthly: number | null; total: number | null };
        stars: number | null;
        forks: number | null;
        watchers: number | null;
        openIssues: number | null;
        openPullRequests: number | null;
        latestRelease: { version: string | null; releasedAt: string | null };
    };
    compatibility: { registry: string; requires: Record<string, string>; dependents: number | null } | null;
    errors: string[];
    collectedAt: string;
    range: number;
    ranges: number[];
    history: StatPoint[];
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

export interface GitHubLabel {
    name: string;
    /** Hex without the `#`, as GitHub sends it. */
    color: string;
}

export interface GitHubMilestone {
    number: number;
    title: string;
}

export interface GitHubUser {
    login: string;
    avatarUrl: string | null;
}

/**
 * A Status column on the org project. The "No status" column's id is null.
 */
export interface BoardColumn {
    id: string | null;
    name: string;
    color: string | null;
}

/**
 * One issue on the org project (see `Services/Board/BoardItem.php`).
 */
export interface BoardItem {
    /** The project item's id, which a move targets. */
    id: string;
    statusId: string | null;
    /** `owner/name`. */
    repo: string;
    number: number;
    title: string;
    url: string;
    state: 'OPEN' | 'CLOSED';
    labels: GitHubLabel[];
    milestone: GitHubMilestone | null;
    assignees: GitHubUser[];
    createdAt: string | null;
    updatedAt: string | null;
    package: { id: number; title: string } | null;
}

export interface BoardResponse {
    project: { title: string; url: string; number: number };
    columns: BoardColumn[];
    items: BoardItem[];
    milestones: string[];
    labels: GitHubLabel[];
    packages: { id: number; title: string }[];
    /** The project has more items than one read pages through; `items` holds the first ones. */
    truncated: boolean;
}

export interface IssueComment {
    id: number;
    author: GitHubUser | null;
    /** CommonMark rendered server side with raw HTML escaped. */
    bodyHtml: string;
    createdAt: string | null;
    url: string;
}

/**
 * An issue as the issue modal shows it (see `Services/GitHub/GitHubIssues.php`).
 */
export interface IssueDetail {
    repo: string;
    number: number;
    title: string;
    body: string;
    bodyHtml: string;
    url: string;
    state: 'OPEN' | 'CLOSED';
    stateReason: string | null;
    labels: GitHubLabel[];
    milestone: GitHubMilestone | null;
    assignees: GitHubUser[];
    author: GitHubUser | null;
    createdAt: string | null;
    updatedAt: string | null;
    closedAt: string | null;
    /** The newest comments, oldest first; only when the issue is read, not after an edit. */
    comments?: IssueComment[];
    /** How many comments the issue has in all, which may be more than `comments` holds. */
    commentsTotal?: number;
}

export interface IssueOptions {
    labels: GitHubLabel[];
    milestones: GitHubMilestone[];
    assignees: GitHubUser[];
}

/**
 * What the host's dashboard grid hands a widget body.
 */
export interface WidgetProps<TData> {
    widget: { id: string; title: string; options: Record<string, unknown>; error?: boolean };
    data: TData;
    options: Record<string, unknown>;
}

export interface PackageRef {
    id: number;
    title: string;
}

export interface DownloadsKpiData {
    metric: 'total' | 'monthly' | 'daily';
    package: PackageRef | null;
    value: number | null;
    asOf: string | null;
}

export interface DownloadsTrendData {
    range: number;
    ranges: number[];
    selected: string;
    packages: PackageRef[];
    series: Record<string, { date: string; value: number | null }[]>;
}

export interface GitHubOverviewData {
    packages: (PackageRef & { stars: number | null; openIssues: number | null; openPullRequests: number | null })[];
    totals: { stars: number; openIssues: number; openPullRequests: number };
    asOf: string | null;
}

export interface TopPackagesData {
    rankBy: 'downloads' | 'growth';
    window: number;
    windows: number[];
    packages: (PackageRef & { downloads: number; growth: number | null })[];
}

export interface ReleaseFeedData {
    releases: (PackageRef & { version: string; releasedAt: string | null })[];
}

export interface BoardWidgetData {
    project: { title: string; url: string } | null;
    /** The Status columns in board order, "No status" (id null) first. */
    columns: (Pick<BoardColumn, 'id' | 'name'> & { count: number })[];
    total: number;
    /** The packages counted; empty counts every card. */
    packages: PackageRef[];
    /** The global board, filtered to the same packages. */
    boardUrl: string;
    /** Why the project couldn't be read, e.g. GitHub isn't set up. */
    error: string | null;
}
