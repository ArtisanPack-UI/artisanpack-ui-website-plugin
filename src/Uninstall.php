<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site;

use ArtisanPackUI\Site\Support\AdminPages;
use ArtisanPackUI\Site\Support\PluginBootstrapper;

/**
 * The plugin's uninstall entry point, declared as `uninstall` in
 * `plugin.json`. Keystone runs it whenever the plugin is deleted, active or
 * not, before it rolls back the plugin's migrations. The
 * `ap.cmsFramework.plugin.deleting` listener only fires when the plugin
 * booted, so a plugin deactivated before deletion would otherwise leave its
 * credentials and the blog post `package` taxonomy behind.
 *
 * An inactive plugin's provider never registered its container bindings,
 * so they're registered first: the GitHub client needs the saved settings
 * to find its cached token. The purge is idempotent, so it doesn't matter
 * that an active plugin's `deleting` listener has already run it.
 *
 * @since 1.5.0
 */
final class Uninstall
{
    public function __invoke(mixed $plugin = null): void
    {
        PluginBootstrapper::register(app());
        PluginBootstrapper::purgeOnDelete(AdminPages::SLUG);
    }
}
