# ArtisanPack UI Plugin

Site-specific CMS plugin for [artisanpack-ui.dev](https://artisanpack-ui.dev),
running on JMWD Keystone + ArtisanPack UI CMS Framework.

This plugin exists as the home for site-specific extensions — admin surfaces,
custom field types, content-edit panels, and hook subscriptions — that don't
belong in a shared, reusable package.

## Structure

```
plugins/artisanpack-ui/
├── plugin.json                              # Plugin manifest
├── src/
│   └── ArtisanPackUIServiceProvider.php     # Base PluginServiceProvider
├── database/
│   └── migrations/                          # Site-specific migrations
└── resources/
    └── views/
        └── admin/
            └── index.blade.php              # /admin/artisanpack-ui landing view
```

## Activation

Activate via the admin plugin list, or flip the row in the `plugins` table:

```sql
UPDATE plugins SET is_active = 1 WHERE slug = 'artisanpack-ui';
```

Once active, the admin nav gets an "ArtisanPack UI" entry pointing at
`/admin/artisanpack-ui`.

## Further reading

- [Plugin Author Guide](../../vendor/artisanpack-ui/cms-framework/docs/plugin-authoring.md)
- [Hello World reference plugin](../../vendor/artisanpack-ui/cms-framework/examples/hello-world-plugin/)
