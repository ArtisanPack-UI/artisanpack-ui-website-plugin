# ArtisanPack UI Plugin — Roadmap

Plan for turning the `artisanpack-ui` Keystone plugin into the admin hub for
ArtisanPack UI packages on artisanpack-ui.dev: syncing with the docs site
(docs.artisanpackui.dev), driving doc/changelog imports, surfacing
Packagist/npm/GitHub stats, managing GitHub issues on a kanban board, and
feeding the JMWD Command Center.

Issues are split by repo:

- **[plugin]** → `ArtisanPack-UI/artisanpack-ui-website-plugin`
- **[docs]** → `ArtisanPack-UI/artisanpack-ui-docs`

## Goal

One place, the **Edit Package** screen, to see and act on everything about a
package: metadata synced from the docs site, its docs and changelog, its
stats, and its issues. Plus a global board for all packages and a read API for
the Command Center.

## Decisions

| Topic | Decision |
|---|---|
| Docs site integration | Use the docs site's existing Sanctum `/api/v1` API, and fill its gaps (see the docs-site work below). |
| Package source of truth | The docs site owns which packages exist. New packages import into this site as **drafts**. |
| Version | Taken from the latest stable Packagist/npm release (GitHub release as a fallback), then written to **both** sites. |
| Icon | Stored as an `iconRef` `{set, name}` in an icon-picker custom field. The `artisanpack/icon` block binds `iconRef` through the visual editor's `custom_field` binding source. Custom package icons are registered here as their own icon set. |
| Sync trigger | A manual "Sync" button (on the list and per package) plus a daily scheduled job. |
| GitHub auth | A GitHub App installed on the ArtisanPack-UI org. |
| GitHub data | Read and written live through the API with **no local mirror** of issues. Stats are the exception: they are snapshotted daily so trends can be charted. |
| GitHub Projects | One **org-wide** Project (v2), with the repo mapped to a package. |
| Board writes | Status (drag and drop), title/body, labels, milestone, assignees, comments, close/reopen. |
| Admin UI | A federated module with its own React bundle, like `keystone-sync`. |
| Command Center | A separate app that **pulls** from us using scoped bearer tokens. Board and stats endpoints proxy GitHub/Packagist live. |

## Non-goals (v1)

- Creating new GitHub issues from the board.
- Video and Resource content types for the Command Center. v1 is blog posts and packages only.
- A local mirror of issues or projects kept up to date by webhooks.
- Editing doc *content* or doc parent/child structure from this site. Only order is changed here, because the docs site derives hierarchy from the repo's folder layout.
- Versioned docs (the docs site keeps one set per package).

---

## Phase 0 — Foundation

### 0.1 [plugin] Federated module scaffold
- Add a Vite build that outputs `dist/assets/remoteEntry.js` and declare `federated_module` in `plugin.json`. Mirror `keystone-sync`.
- Replace the `admin/artisanpack-ui/Index` Inertia render. That page component doesn't exist today.
- Expose these entries: `./packages-board`, `./settings`, plus the package-edit tabs below.
- **Spike:** confirm a federated module can contribute (a) tabs or panels to the content edit screen through `ap.admin.contentEdit.panels`, and (b) the editor component for a custom field type, and (c) dashboard widget bodies through `keystone.admin.dashboard.widget.render`. Record the answer in this file before starting Phase 1.

#### 0.1 spike findings

Checked against the Keystone host as of October 2026. The package edit screen is the host's generic dynamic content-type screen (`admin/content-model/DynamicContentEdit`, rendered by `ContentTypeContentController::edit()`), not the Blog/Pages edit screens. That distinction drives (a) and (b).

| Question | Answer | How |
|---|---|---|
| (a) Content-edit tabs/panels | **Yes, client side only** | See below. |
| (b) Custom field type editor | **No, not on the package screen** | See below. |
| (c) Dashboard widget bodies | **Yes** | See below. |

**(a) Tabs and panels — yes, through the boot module.** The PHP filters (`ap.cmsFramework.admin.contentEdit.tabs` / `.panels`, which is what `ap.admin.contentEdit.panels` refers to) only reach the Blog and Pages edit screens. `ContentTypeContentController::edit()` never shares the `contentEdit` Inertia prop, so a server-side registration never reaches the package screen. Content Organizer hits the same gap. That screen still mounts `<AdminEditSlot slot="tabs">` (and the sidebar, before-editor and after-editor slots), and `AdminEditSlot` always runs the client-side `keystone.admin.panels.entries` filter, even over an empty seed. So the plugin's `./boot` module:

1. adds entries to the `tabs` slot when `contentType === 'package'` (`keystone.admin.panels.entries`), and
2. returns the React component for each entry's `component` identifier (`keystone.admin.panels.resolved`).

This ships in 0.1 as placeholder **Docs**, **Stats** and **Issues** tabs. The fallback (a plugin page per package) isn't needed. Two caveats:
- Capability gating has to happen client side, or inside the tab's own endpoints, because the host's server-side `capability` key never applies on this path. Phase 0.5 must account for this.
- If Keystone later wires `PanelSlotSupport::payload()` into `ContentTypeContentController::edit()`, a server-side registration with the same slugs is deduplicated by the boot filter.

**(b) Custom field editors — no on the package screen.** Blog and Pages render custom fields through `CustomFieldRenderer`, and plugins can supply an editor there through the `keystone.admin.customFields.registerType` filter. `DynamicContentEdit` ignores both: it renders **every** custom field as a plain `TextField`, and `fieldsPayload()` doesn't send `editor_component`. An `icon_picker` field type registered with `apRegisterFieldType()` would therefore show up as a raw JSON text input on Edit Package. Options for 1.2, in order of preference:
1. A Keystone change so `DynamicContentEdit` renders fields through `CustomFieldRenderer` and the payload carries `editor_component`. This is the right fix, and it also helps every other dynamic type.
2. Until then, ship the icon picker as an Edit Package sidebar panel (`sidebar-top` / `sidebar-bottom` through the same boot-module path as (a)) that reads and writes the `icon` value through a plugin endpoint, and hide the raw field.

Answer this before 1.1/1.2, together with Open question 1.

**(c) Dashboard widgets — yes.** There are two seams:
- `keystone.admin.dashboard.widget.registerFederated` (action) registers a component against the host's widget registry under the key the PHP widget's `extendedInfo()['component']` names. The boot module can pass the component reference directly. This is the seam 4.3 and 5.5 should use, and Content Organizer already uses it for its widgets.
- `keystone.admin.dashboard.widget.render` (filter) receives `(ReactNode, { widget, catalog })` and can wrap or replace any widget body. It suits decoration, but it isn't needed to register a widget.

The PHP side is unchanged: `AdminWidgetManager::register()` with a `KeystoneAdminWidgetInterface` class.

### 0.2 [plugin] Settings screen
- Docs site base URL and API token, stored encrypted.
- GitHub App ID, private key and installation ID, plus the org Project number.
- Status checks that run "Test connection" against the docs site and GitHub.

### 0.3 [plugin] GitHub App client
- Mint a JWT, exchange it for an installation token and cache the token until it expires.
- Thin REST and GraphQL wrappers that are aware of rate limits (they surface `X-RateLimit-Remaining`).

### 0.4 [plugin] Docs site API client
- Typed client for packages, documentation (index and reorder), changelogs and import triggers.
- Handles 202/queued responses and maps errors to admin-friendly messages.

### 0.5 [plugin] Capabilities
- Add `artisanpack-ui.sync`, `artisanpack-ui.issues.manage` and `artisanpack-ui.api-tokens.manage`.
- Gate every admin route and UI action on them.

---

## Phase 1 — Package data model and icons

### 1.1 [plugin] Package custom fields
- Fields: `docs_package_id`, `composer_name` / `npm_name`, `registry` (packagist|npm), `github_repo`, `version`, `icon` (iconRef), `docs_url`, `last_synced_at`.
- **Decide:** Keystone custom-field groups or plugin-owned columns added to `packages` through a migration (the plugin's seeder already owns the table). See Open questions.

### 1.2 [plugin] Icon picker custom field type
- Register it with `apRegisterFieldType('icon_picker', …)`. It stores `{set, name}` JSON.
- The picker lists every set registered through `ap.icons.registerIconSets`, so it shows the default artisanpack-ui/icons sets, Font Awesome and the custom set from 1.3.
- **Acceptance:** an `artisanpack/icon` block on the single-package template, with `iconRef` bound to `custom_field: icon`, renders the selected package's icon on the front end. Add a feature test.

### 1.3 [plugin] Custom package icon set
- Register an `apui` icon set through `ap.icons.registerIconSets`. The set points at plugin storage (for example `storage/app/artisanpack-ui/icons`).
- Its SVGs are populated by the icon sync (2.3) and sanitized with the visual editor's `SvgSanitizer`.

---

## Phase 2 — Sync with the docs site

### 2.1 [plugin] Import packages as drafts
- A "Sync from docs" button on the Packages list.
- It matches docs-site packages by `docs_package_id`, then creates a **draft** for each package that isn't here yet.
- It never overwrites title or body content on packages that already exist.

### 2.2 [plugin] Version sync
- Read the latest stable version from Packagist (`artisanpack-ui/{slug}`) or npm (`@artisanpack-ui/{slug}`), falling back to the latest GitHub release.
- Update the marketing package, then `PATCH` the docs site package when the docs site's version differs.

### 2.3 [plugin] Icon sync
- Pull each package's icon from the docs site API, which depends on [docs] D4.
- Map it to an `iconRef` and write any custom SVG into the `apui` set.

### 2.4 [plugin] Scheduled sync and status
- A daily queued job runs 2.1–2.3. Each package also gets a "Sync now" action.
- Show `last_synced_at` and the last error on the edit screen.

---

## Phase 3 — Edit Package: Docs tab

### 3.1 [plugin] Import docs and changelog buttons
- "Import documentation" and "Import changelog" call the docs site import endpoints, which depends on [docs] D2 and D3.
- Show queued, done and failed status, and `docs_imported_at`, which depends on [docs] D5.

### 3.2 [plugin] Reorder documentation
- A drag-and-drop tree that reads the docs index, reorders siblings within a parent and saves through `POST packages/{id}/documentation/reorder`.
- Parent changes are out of scope.

---

## Phase 4 — Stats

### 4.1 [plugin] Daily stats snapshots
- A `package_stat_snapshots` table holding: date, package, downloads (daily, monthly and total, from Packagist or npm), stars, forks, watchers, open issues, open PRs, dependents, and the latest release.
- Collected by a daily scheduled job.

### 4.2 [plugin] Stats tab on Edit Package
- Live KPI tiles and trend charts drawn from the snapshots.
- A compatibility panel showing the PHP/Laravel `require` constraints from Packagist and the dependents count.

### 4.3 [plugin] Dashboard stats widgets
- Register widgets on the Keystone admin dashboard with `AdminWidgetManager::register()`. Each widget implements `KeystoneAdminWidgetInterface`, following `Modules/SiteEditor/app/Widgets/*`.
- The widget bodies render from the federated module through the `keystone.admin.dashboard.widget.render` filter.
- Widgets:
  - **Downloads KPI tile.** Total, monthly or daily, for all packages or one package. Configured through the widget settings, following `KpiTileWidget`'s `metric` option.
  - **Downloads trend.** A chart over time from the snapshots, with a package selector.
  - **GitHub overview.** Stars, open issues and open PRs for each package.
  - **Top packages.** Ranked by downloads or growth over a chosen window.
  - **Release feed.** Latest version per package and when it was released.
- Every widget is gated on a stats capability.
- Depends on 4.1.

---

## Phase 5 — Kanban (GitHub org Project v2)

### 5.1 [plugin] Project reader
- GraphQL fetch of the org project: the Status field and its options, and each item's issue (repo, number, title, labels, milestone, assignees, state).
- Map the repo to a package through `github_repo`.
- Paginate the fetch. It runs live with no persistence.

### 5.2 [plugin] Package board (Edit Package: Issues tab)
- Columns are the project's Status options, and the board is scoped to the package's repo.
- Filters for milestone and label.
- Dragging a card calls `updateProjectV2ItemFieldValue`. The UI updates optimistically and rolls back if the call fails.

### 5.3 [plugin] Issue modal
- Renders the issue body as markdown.
- Edits title, body, labels, milestone and assignees, and can close or reopen the issue.
- Shows the comment thread and can add a comment.

### 5.4 [plugin] Global board page
- An admin page showing every package in the org project.
- **Sort and group by package:**
  - A "group by package" toggle shows one horizontal swimlane per package across the shared Status columns.
  - Without the toggle, cards inside each column can be sorted by package, milestone, last updated or created date.
- Filters for package (multi-select), milestone and label.
- Remember the grouping, sort and filters per user in `localStorage`.
- Dragging a card moves its Status. It can't change which package the card belongs to.
- Reuses the 5.2 and 5.3 components.

### 5.5 [plugin] Dashboard board widget
- A compact dashboard widget, like the 4.3 widgets: card counts per Status column, filtered to one or more packages.
- It links to the global board with those filters applied.
- Depends on 5.1.

---

## Phase 6 — Command Center API

### 6.1 [plugin] Scoped API tokens
- Issue and rotate tokens from plugin settings. Tokens are hashed at rest and shown once.
- Abilities: `posts:read`, `packages:read`, `stats:read`, `boards:read`. An ability middleware enforces them, and requests are rate limited.

### 6.2 [plugin] Read endpoints (`/api/artisanpack-ui/v1/...`)
- `posts`: published blog posts.
- `packages`: package metadata, version and icon.
- `stats`: per-package and aggregate figures, read live with snapshot history.
- `boards`: the org project, proxied live with the package, milestone and label filters.

### 6.3 [plugin] API reference doc
- Endpoint and auth reference for the Command Center team, plus a Bruno collection like the one the docs site has.

---

## Docs-site work ([docs] → `artisanpack-ui-docs`)

- **D1** Look up packages by slug, either a `?slug=` filter on the index or `getRouteKeyName`. Today packages can only be fetched by id.
- **D2** `POST /api/v1/packages/{package}/import-changelog` (202, queued). It doesn't exist yet.
- **D3** Add an `imports:trigger` token ability and enforce it on both import endpoints. `import-docs` currently accepts any token.
- **D4** Include icon data in the package API:
  - an `iconRef` `{set, name}`;
  - the sanitized SVG markup for icons that aren't in a shared set (`ap.*` custom icons);
  - consider moving the docs site's icon string format over to `iconRef` as well.
- **D5** Expose import status in the API: `docs_imported_at`, a changelog timestamp, and the last job result or error.
- **D6** Have the docs site also pull its version from Packagist/npm. Optional: the plugin already PATCHes it in 2.2.

## Dependencies

- 0.1–0.4 come before everything else.
- 1.2 depends on the 0.1 spike.
- 2.3 depends on D4.
- 3.1 depends on D2, D3 and D5.
- 2.1 benefits from D1 but doesn't require it, because it matches on `docs_package_id`.
- 4.3 depends on 4.1, and on the 0.1 spike confirming that federated widget rendering works.
- 5.2–5.5 depend on 5.1.
- 6.2 depends on 4.1 and 5.1.

## Open questions

1. **Custom field storage.** Keystone custom-field groups, or columns the plugin owns on `packages`? Answer this before 1.1.
2. ~~**Federated module reach.**~~ Answered by the 0.1 spike (see **0.1 spike findings**): tabs yes, through the boot module; custom-field editors no on the package screen, pending a Keystone change.
3. **GitHub App identity.** Comments and edits will appear as the App bot, not as the admin. Is that acceptable, or should comments carry an "on behalf of" prefix?
4. **Rate limits on live proxying.** Admin and Command Center traffic both hit GitHub live. Add a short cache (30–60s) on the Command Center `boards` and `stats` endpoints if limits become a problem.
5. **npm packages on the board.** Confirm that JS packages live in their own repos, or in a monorepo with a label convention, so the repo-to-package mapping holds.
