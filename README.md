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
├── src/
│   ├── ArtisanPackUIServiceProvider.php     # Base PluginServiceProvider
│   └── Http/Controllers/
│       └── PluginAssetController.php        # Serves dist/assets/ to the browser
├── resources/
│   ├── js/                                  # Federated admin bundle (React)
│   │   ├── boot.tsx                         # Preloaded: Edit Package tabs
│   │   ├── pages/                           # plugins/artisanpack-ui/{page}
│   │   └── tabs/                            # Edit Package tab bodies
│   ├── views/blocks/                        # Server-rendered block partials
│   └── icons/                               # `artisanpackui-*` icon set (sidebar logo)
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

## Releasing

Keystone's plugin updater installs the GitHub release's `.zip` asset, which is
the only place the built `dist/` ships.

1. Bump `version` in `plugin.json` (and `package.json`) and merge.
2. Push a matching tag: `git tag v0.2.0 && git push origin v0.2.0`.
3. `.github/workflows/release.yml` runs `bin/build-release-zip.sh` and attaches
   `artisanpack-ui-plugin-vX.Y.Z.zip` and its `.sha256` sidecar to the release.
   It uploads to an existing release for the tag, or creates a draft one to add
   notes to and publish.

Run `bin/build-release-zip.sh` locally to inspect the ZIP before tagging.

## Activation

Activate via the admin plugin list, or flip the row in the `plugins` table:

```sql
UPDATE plugins SET is_active = 1 WHERE slug = 'artisanpack-ui';
```

Once active, the admin nav gets an "ArtisanPack UI" entry pointing at
`/admin/artisanpack-ui` (the packages board), with settings at
`/admin/artisanpack-ui/settings`. Package edit screens gain Docs, Stats and
Issues tabs.

## Further reading

- [Plugin Author Guide](../../vendor/artisanpack-ui/cms-framework/docs/plugin-authoring.md)
- [Hello World reference plugin](../../vendor/artisanpack-ui/cms-framework/examples/hello-world-plugin/)
