# Feature: Packages taxonomy for blog posts

## Problem Statement

Blog posts about a package (release notes, updates, tutorials) can't be tied to
that package. A package page therefore can't show "updates for this package",
and readers have no way to browse every post about one package.

## Target User

- **The site author**, who tags a post with the packages it mentions while
  writing it on Edit Post.
- **Visitors** on a package page who want that package's latest news, and on a
  single post who want to jump to the packages it covers.

## User Stories

- As the author, I want a **Packages** picker on Edit Post listing every
  package, so I can tag a post with the packages it mentions.
- As the author, I want the package terms to be created and renamed for me when
  packages are added or synced, so I never manage them by hand.
- As the author, I want to drop a blog pattern into a package page and set its
  query to "Packages = <this package>", so the page shows that package's posts.
- As a visitor, I want a "Package updates" archive per package, so I can read
  every post about it.
- As a visitor, I want a single post to show the packages it covers, linking
  through, so I can get to those packages.

## Scope

### In scope (v1)

- **Keystone:** Blog posts can carry custom (dynamic) taxonomies, not only
  Categories and Tags: binding, the Edit Post sidebar picker, saving, query-loop
  filtering, a public term archive and Post Terms rendering.
- **cms-framework:** an extension point in `QueryRuntime`'s `taxQuery` handling
  so a host can filter on taxonomies other than `category` and `post_tag`.
- **visual-editor:** a taxonomy and term filter in the Query block's
  **Filters** panel that writes `taxQuery`.
- **Plugin:** provisions a `package` taxonomy bound to `post`, and keeps one
  term per Package record in sync.
- **Plugin:** the term archive for a package, and Post Terms on single posts
  (theme template edits, using the generic Keystone support above).

### Out of scope

- Pages carrying custom taxonomies. The Keystone change should not rule this
  out, but v1 is posts only.
- Bulk-assigning custom taxonomies from the Posts list. The existing bulk
  action stays categories/tags only.
- A query that infers "the current package" from context. The term is picked
  per package page by hand.
- Auto-tagging posts by scanning their content for package names.
- Backfilling existing posts. That's manual tagging after release.

## Behavior

### Happy path

1. The plugin's provisioner creates the `package` taxonomy (name "Packages",
   singular "Package", non-hierarchical, public), bound to `post`.
2. On provisioning, and whenever a Package record is created, renamed, synced
   from the docs site or deleted, the plugin creates, updates or removes the
   matching term, so there's one term per package.
3. On Edit Post the sidebar shows a **Packages** panel next to Categories and
   Tags. The author ticks Accessibility and Forms and saves, which writes two
   term assignments for the post.
4. On the Accessibility package page, the author inserts a blog pattern, opens
   the Query block's **Filters** panel, picks Taxonomy "Packages", Term
   "Accessibility", and saves. The page lists the newest posts tagged
   Accessibility.
5. The pattern's "All updates" link goes to the term archive (for example
   `/blog/package/accessibility`, with the route shape decided in the Keystone
   issue), which lists every post with that term.
6. A single post shows "Packages: Accessibility, Forms" through the Post Terms
   block's Packages variation, and each name links to its term archive.

### Edge cases

- **Package renamed:** the term's name and slug follow it. The plugin keeps the
  term id per package rather than matching on slug, so assignments survive. The
  old archive URL 404s (acceptable for v1, see Open Questions).
- **Package deleted:** the term is deleted and its assignments cascade
  (`keystone_dynamic_content_term_assignments.term_id` cascades on delete).
  Query blocks filtering on it show their empty state.
- **Draft package:** the term exists, so posts can be tagged ahead of launch,
  but the term archive and Post Terms only link to published packages' terms
  (decide in the plugin issue: hide or show unlinked).
- **Post deleted:** its assignments are removed, as the ContentModel delete path
  already does for dynamic records.
- **Slug collision:** the `package` taxonomy slug is taken by another type. The
  provisioner fails loudly, the same as `PackageContentTypeProvisioner` does for
  `package-category` and `package-tag`.
- **Query term deleted:** a saved `taxQuery` pointing at a missing term returns
  no posts rather than all posts.
- **Plugin deactivated:** the taxonomy and terms stay, and the posts stay
  tagged. On uninstall, `purgeOnDelete` removes the taxonomy, its terms and its
  assignments.

## Acceptance Criteria

- [ ] A taxonomy bound to `post` shows up as a sidebar panel on Edit Post, and
      its assignments save, reload and survive autosave and revisions.
- [ ] A Query block with `taxQuery: {taxonomy: 'package', terms: [id], operator: 'IN'}`
      on `postType: post` returns only posts carrying that term, both in the
      editor preview and on the rendered page.
- [ ] The Query block's Filters panel lets an author pick a taxonomy available
      to the selected post type, plus one or more of its terms.
- [ ] Category/tag `taxQuery` behavior is unchanged.
- [ ] Each published post term has a public archive that uses the theme's
      template hierarchy and falls back to the blog archive template.
- [ ] Post Terms offers a Packages variation and renders the post's package
      terms linked to their archives.
- [ ] The plugin creates one `package` term per Package record, and keeps it
      updated on rename, sync and delete.
- [ ] Re-running provisioning is idempotent and doesn't duplicate terms.
- [ ] Plugin uninstall removes the taxonomy, its terms and their assignments.

## Implementation Notes

The work spans four repos. The order is cms-framework, then visual-editor and
Keystone, then the plugin.

### cms-framework (GitHub `ArtisanPack-UI/cms-framework`)

- `src/Modules/Blog/Services/QueryRuntime.php`: `applyTaxQuery()` maps only
  `category`/`post_category` and `post_tag`/`tag`, and returns early for
  anything else (line ~416). Add a filter such as
  `ap.cmsFramework.query.taxQuery` that receives `(Builder $query, string $taxonomy, array $terms, string $postType)`
  for unmapped taxonomies, so Keystone can apply its own term tables. The
  default stays a no-op.

### visual-editor (GitHub `ArtisanPack-UI/visual-editor`)

- `resources/js/visual-editor/blocks/query/edit.tsx`: the Filters panel only
  has Search today, on `main` and `release/1.x`. Add Taxonomy and Terms
  controls that write `query.taxQuery` (`block.json` already declares
  `taxQuery: null`), source taxonomies from `editor/taxonomy-registry` for the
  selected post type, and load terms through the existing terms endpoint.
- `editor/use-query-preview.ts` already forwards `taxQuery`. Check the preview
  resolves custom taxonomies once the host handles them.
- `blocks/post-terms/variations.ts` already builds one variation per registered
  taxonomy (#771). Check that a host-registered `package` taxonomy gets a
  variation and renders for posts.

### Keystone (GitLab `jmwd-keystone-cms`)

- Storage already exists: `keystone_dynamic_content_terms` plus
  `keystone_dynamic_content_term_assignments` (`content_type_slug`,
  `record_id`, `term_id`). Posts reuse it with `content_type_slug = 'post'`, so
  no new tables are needed.
- Binding: allow `TaxonomyManager::createTaxonomy(['content_type_slug' => 'post', …])`
  and show `post` as a target in the Content Model taxonomy admin
  (`TaxonomyController`/`TaxonomyRequest`).
- Edit Post: render each taxonomy bound to `post` as a sidebar term panel. The
  dynamic edit screen's term panel and inline term creation
  (`TaxonomyController` inline endpoint) are the model. Persist on save in
  `Modules/Blog` `PostController` (or through
  `ap.cmsFramework.admin.contentEdit.saveData`).
- Query: register the cms-framework `taxQuery` filter to `whereExists` against
  the assignments table for `post`. `DynamicTaxonomyFilter` already does this
  for dynamic types.
- Terms for the editor: extend the `ap.visualEditor.terms` and
  `.terms.allUrl` filters (`ContentModelServiceProvider`) and the taxonomy
  registry payload to cover taxonomies bound to `post`.
- Public archive: a term archive route under the blog base, beside the
  existing category and tag archives (`BlogController::renderTermArchive()`),
  with a `taxonomy-{slug}` → `archive-post` → `index` template hierarchy and
  the same `keystone.public.blog.term.*` filters.
- Post delete: remove that post's assignments.

### Plugin (this repo)

- `src/Support/PackageContentType.php`: add the `package` taxonomy definition
  (`content_type_slug: post`) next to the package-bound ones. That changes
  `provisionedMarkerKey()`, so existing installs provision again.
- `src/Support/PackageContentTypeProvisioner.php`: provision taxonomies per
  target type (today everything is bound to `package`, and collisions are
  checked against `package`).
- New `src/Support/PackageTermSync.php`: upserts one term per Package record.
  It records the term id in a new nullable `package_term_id` column on
  `packages` (or a small plugin table), so renames update in place. Call it
  from the provisioner, from `PackageSyncRunner` after an import, and from the
  Package model's `saved` and `deleted` events.
- `src/Support/PluginBootstrapper.php` `purgeOnDelete`: drop the taxonomy and
  its terms.
- `plugin.json` `requires`: bump to the Keystone and cms-framework versions
  that ship the upstream support.
- Tests: `tests/Unit/PackageContentTypeTest.php` (definition and collisions),
  and a new `tests/Feature/PackageTermSyncTest.php` (create, rename, delete,
  idempotent re-run).
- Theme (`themes/artisanpack-ui`, content only): a `taxonomy-package.html`
  archive template, if the blog archive doesn't fit as is, and Post Terms with
  the Packages variation in `single-post.html` and `single-post-longform.html`.
  The package-page section uses an existing blog pattern with its query
  filtered by hand, so no new pattern is needed.

### Database changes

- Keystone: none. It reuses the dynamic term tables.
- Plugin: a nullable `package_term_id` on `packages`, added through the field
  provisioner or a plugin migration.

### Dependencies

- Keystone release with posts taxonomy support, which needs cms-framework and
  visual-editor releases that include the changes above.

## Open Questions

- Archive route shape: `/blog/{taxonomy}/{term}` or `/{taxonomy}/{term}`? The
  Keystone issue should decide it, keeping in mind that category and tag
  archives already use their own bases.
- Should a renamed package redirect its old term-archive URL? (Not in v1
  unless the redirect manager makes it trivial.)
- Should terms for draft packages be hidden from Post Terms and their archive
  until the package is published?
