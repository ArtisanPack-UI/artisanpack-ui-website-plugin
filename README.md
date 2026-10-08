# ArtisanPack UI Plugin

Site-specific CMS plugin for [artisanpack-ui.dev](https://artisanpack-ui.dev),
running on JMWD Keystone + ArtisanPack UI CMS Framework.

This plugin exists as the home for site-specific extensions — admin surfaces,
custom field types, content-edit panels, and hook subscriptions — that don't
belong in a shared, reusable package.

## Structure

```
plugins/artisanpack-ui/
├── plugin.json                              # Plugin manifest (incl. federated_module)
├── database/migrations/                     # Settings, sync state and stats snapshot tables
├── src/
│   ├── ArtisanPackUIServiceProvider.php     # Keystone wiring (nav, icons, blocks, widgets)
│   ├── Blocks/                              # Server-rendered visual editor blocks
│   ├── Casts/                               # SafeEncrypted (credentials at rest)
│   ├── Http/                                # JSON endpoints, routes and the asset route
│   ├── Jobs/                                # Daily package sync and stats snapshot
│   ├── Models/                              # Settings, package, sync state, snapshots
│   ├── Services/                            # Docs site, GitHub, boards, sync, stats
│   ├── Support/                             # Permissions, bootstrapper, field defs
│   └── Widgets/                             # Dashboard widgets
├── resources/
│   ├── js/                                  # Federated admin bundle (React)
│   │   ├── boot.tsx                         # Preloaded: Edit Package tabs
│   │   ├── components/board/                # Kanban board + issue modal
│   │   ├── pages/                           # plugins/artisanpack-ui/{page}
│   │   ├── tabs/                            # Edit Package tab bodies
│   │   └── widgets/                         # Dashboard widget bodies
│   ├── views/blocks/                        # Server-rendered block partials
│   └── icons/                               # `artisanpackui-*` icon set (sidebar logo)
├── tests/                                   # Pest suite under Testbench
├── bin/build-release-zip.sh                 # Release ZIP (injects dist/)
├── dist/assets/remoteEntry.js               # Build output (gitignored)
└── vite.config.js                           # Module Federation remote build
```

## Admin bundle

The admin UI is a Module Federation remote, like `keystone-sync`. Keystone
loads `remoteEntry.js` at runtime and shares its own React, Inertia and hooks
registry with it. Build it before activating the plugin:

```bash
npm install
npm run build      # or `npm run dev` to rebuild on change
```

`dist/` is gitignored. Build it locally for development; live sites get it
from the release ZIP (see **Releasing**).

## Tests

The PHP suite runs under Testbench, without a Keystone host, like the other
first-party plugins. It covers the settings, permissions, API clients, package sync
(including the daily run and "Sync now"), the Docs and Stats tab endpoints, the daily
stats snapshot and the dashboard widgets' data, the kanban boards and issue modal
endpoints (with GitHub faked), and the `apui` icon set and picker catalog (with the visual editor and icons
packages as dev dependencies); Keystone-only glue (nav, the sidebar icon,
blocks, field provisioning, dashboard widget registration) is checked in a running install.

```bash
composer install
composer test
```

## Scheduled jobs

The plugin schedules two queued jobs, so the host needs both the scheduler
(`php artisan schedule:run` every minute) and a queue worker:

- `artisanpack-ui:sync-packages` (03:00): imports new docs packages as drafts
  and syncs every version and icon, recording each package's outcome for the
  sync status panel on Edit Package.
- `artisanpack-ui:collect-package-stats` (04:00): writes today's row to
  `artisanpack_ui_package_stat_snapshots` for the Stats tab's trend charts,
  and drops the snapshots and sync state of packages that were deleted.

Both jobs are unique and never run at the same time as another copy of
themselves. A run may take up to 900 seconds, so **the queue connection's
`retry_after` must be greater than 900** (Laravel's default is 90); otherwise
a long run is handed to a second worker and marked failed.

## Releasing

Keystone's plugin updater installs the GitHub release's `.zip` asset, which is
the only place the built `dist/` ships.

1. Bump `version` in `plugin.json` and `package.json`, run
   `npm install --package-lock-only` so `package-lock.json` matches, add the
   release to `CHANGELOG.md`, and merge.
2. Push a matching tag: `git tag v1.0.0 && git push origin v1.0.0`.
3. `.github/workflows/release.yml` checks the tag against both versions, runs
   the test suite and `bin/build-release-zip.sh`, and attaches
   `artisanpack-ui-plugin-vX.Y.Z.zip` and its `.sha256` sidecar to the release.
   It uploads to an existing release for the tag, or creates a draft one to add
   notes to and publish.

Run `bin/build-release-zip.sh` locally to inspect the ZIP before tagging.

## Installing and activating

- **A site:** upload the release ZIP under **Plugins → Upload**, then activate
  it in the plugin list.
- **A development clone** in `plugins/artisanpack-ui`: build the bundle, then
  register it the way an upload would with `php artisan cms:plugins:sync`, and
  activate it in the plugin list.

Always activate through the admin (or `PluginManager`). Don't flip
`is_active` in the `plugins` table by hand: activation is what runs the
plugin's migrations and seeds its permissions, so a row switched on directly
leaves both missing.

Once active, the admin nav gets an "ArtisanPack UI" entry pointing at
`/admin/artisanpack-ui` (the packages board), with settings at
`/admin/artisanpack-ui/settings`. Package edit screens gain Docs, Stats and
Issues tabs, and a docs site sync panel in the sidebar.

The packages board and each package's Issues tab read the org GitHub Project
(v2) live, so they need the GitHub App and the project number saved in
Settings. The project needs a single-select **Status** field: its options are
the board's columns. Moves, edits and comments are made by the GitHub App, so
GitHub shows them as the App's bot rather than the admin who made them.

The admin dashboard's **Add widget** drawer lists six ArtisanPack UI widgets:
five stats widgets (downloads KPI, downloads trend, GitHub overview, top
packages, release feed) for users with `artisanpack-ui.stats.view`, which read
the daily stats snapshots and so show figures as of the last stats run, and the
kanban board widget (card counts per Status column) for users with
`artisanpack-ui.issues.manage`.

## Permissions

Activation seeds four permissions, all granted to admins:

| Permission | Grants |
|---|---|
| `artisanpack-ui.sync` | Sync from docs, Sync now, docs imports and doc reordering |
| `artisanpack-ui.issues.manage` | The kanban boards and editing issues |
| `artisanpack-ui.stats.view` | The Stats tab and the dashboard stats widgets |
| `artisanpack-ui.settings.manage` | The Settings page and its connection tests |

Holding any one of them shows the ArtisanPack UI nav entry.

## Updating

The updater replaces the plugin's files but doesn't clear Laravel's caches. On
a site that caches routes or config (the Keystone installer does), run
`php artisan optimize` after updating so the plugin's new routes and settings
take effect.

## Uninstalling

Deleting the plugin while it is active removes the stored integration
settings (the docs API token and GitHub App private key), the cached GitHub
installation token and the mirrored `apui` icons in
`storage/app/artisanpack-ui/icons`. Deactivate-then-delete skips this, because
an inactive plugin's code doesn't run; delete it while active to purge them.

The framework also rolls back the plugin's migrations, but
`migrate:rollback --path` only reverts the last migration batch, so the
plugin's tables may survive if other migrations ran after it was activated.

The `package` content type, its records and its custom fields deliberately
persist: they are site content, not plugin state.

## Known limitations

- Keystone's Edit Package screen still renders its own raw text inputs for the
  `icon` and `registry` custom fields next to the plugin's icon picker and
  registry editor.
- The Command Center API (scoped tokens and read endpoints) is deferred to a
  release after 1.0.

## Further reading

- [Hello World reference plugin](../../vendor/artisanpack-ui/cms-framework/examples/hello-world-plugin/)
