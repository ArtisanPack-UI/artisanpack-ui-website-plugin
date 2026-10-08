# Changelog

All notable changes to this plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-10-08

The plugin becomes the admin hub for ArtisanPack UI packages: syncing with the
docs site, driving doc and changelog imports, package stats, and the org
GitHub Project as kanban boards.

### Added

- **Foundation.** A federated React admin bundle served from the plugin's
  own asset route, a Settings page for the docs site API and the GitHub App
  (with connection tests), typed clients for both, and the plugin's
  permissions.
- **Package data model and icons.** The `package` content type's custom
  fields, provisioned on boot; an icon picker field; and the `apui` icon set
  mirroring the docs site's custom package icons.
- **Sync with the docs site.** "Sync from docs" on the Packages list imports
  new docs packages as drafts and syncs versions (latest stable from
  Packagist, npm or GitHub, written to both sites) and icons. A daily job
  does the same, and Edit Package gains a sync status panel with "Sync now".
- **Docs tab.** Queue documentation and changelog imports on the docs site,
  and reorder documentation pages within their section.
- **Stats.** A daily stats snapshot, a Stats tab with live figures, trend
  charts and compatibility, and five dashboard stats widgets.
- **Kanban.** The org GitHub Project (v2) as a board on each package's Issues
  tab and on a global board page, with an issue modal for editing issues and
  commenting, and a dashboard board widget.
- The `artisanpack-ui.settings.manage` permission, which now gates the
  Settings page and its connection tests.
- A CI workflow running the tests, typecheck and build; releases run the
  tests before building the ZIP.
- `LICENSE` and this changelog.

### Changed

- Settings moved from `artisanpack-ui.sync` to the new
  `artisanpack-ui.settings.manage`. Updating seeds the new permission and
  grants it to admins only, so **non-admin roles that edited settings through
  `artisanpack-ui.sync` must be granted `artisanpack-ui.settings.manage`
  explicitly.**
- Changing the docs site URL's origin, or the GitHub App or installation ID,
  now requires re-entering the API token or private key.
- Requires PHP 8.3, cms-framework 2.12 and the `openssl` extension.
- Board reads are cached for 45 seconds and limited to 20 a minute per user;
  Refresh still reads the project fresh.

### Removed

- The unused `artisanpack-ui.api-tokens.manage` permission (the Command
  Center API is deferred past 1.0). Installs that already seeded it keep the
  row until the plugin is reinstalled; nothing references it any more, so it
  grants nothing.

### Fixed

- Every admin page returned 500 under a cached route table, because the
  plugin's route names weren't indexed.
- The issue modal's forms also submitted the Edit Package form.
- Plugin writes failed with 419 after logging in without a full page reload.
- Issue endpoints could reach any repo the GitHub App can see, and pull
  requests; they are now limited to issues on the configured project.
- The docs site URL check missed IPv6, carrier-grade NAT and unresolvable
  hosts, and didn't stop DNS rebinding; requests are now pinned to a vetted
  address.
- Issue markdown loaded remote images and opened links in the admin tab.
- The cached GitHub installation token was stored in plaintext, and saving
  settings didn't drop stale GitHub caches.
- Deleting the plugin left its stored credentials and icons behind.
- Overlapping sync or stats runs could create duplicate drafts or fail on
  unique keys.
- A changed `APP_KEY` made the Settings page and every endpoint fail.
- Board cards from repos outside the org opened the wrong issue.
- Issues with more than 100 comments hid the newest ones.
- Large boards and option lists were silently truncated.
- Field provisioning queried the database on every request.
- Accessibility, focus, polling and caching fixes across the admin bundle.

## [0.1.1] - 2026-09-12

### Fixed

- Block registration for the visual editor 1.7.x facade API.

### Added

- The `package` content type provisions itself on boot.

## [0.1.0] - 2026-09-07

### Added

- Initial release, extracted from the artisanpack-ui.dev site: the `package`
  content type, the terminal and copy-command blocks, and updates from
  GitHub Releases.

