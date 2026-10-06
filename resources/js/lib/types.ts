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
 * The props every plugin admin page receives.
 */
export interface PluginPageProps {
    nav: Nav;
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
