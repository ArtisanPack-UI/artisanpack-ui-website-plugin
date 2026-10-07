<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Support;

/**
 * The custom fields the `package` content type carries for sync, stats and
 * the boards (roadmap 1.1).
 *
 * **Storage decision.** These are Keystone custom fields, not columns the
 * plugin migrates onto `packages` itself. On the dynamic content-type edit
 * screen a custom field already *is* a physical column on the type's table
 * (`CustomFieldManager::createField()` adds it), plus a `custom_fields` row.
 * The row is what makes Keystone's generic edit screen render an input for
 * the column, validate it, save it, and include it in the record it sends
 * the browser. Plugin-owned columns would get none of that: they would be
 * stripped from the edit payload and need a bespoke edit panel and save
 * endpoint. {@see PackageFieldProvisioner} creates the fields idempotently.
 *
 * `icon` is an {@see IconPickerField} holding its `{set, name}` iconRef as
 * JSON text. It is a `text` column rather than `json` so a hand-typed
 * invalid value is stored as-is instead of failing the save.
 *
 * @phpstan-type FieldDefinition array{key: string, name: string, type: string, column_type: string, description: string, order: int, options?: array<string, mixed>}
 *
 * @since 0.2.0
 */
final class PackageFields
{
    /** The content type slug the plugin's seeder registers. */
    public const CONTENT_TYPE = 'package';

    public const REGISTRY_PACKAGIST = 'packagist';

    public const REGISTRY_NPM = 'npm';

    /**
     * @return list<FieldDefinition>
     */
    public static function definitions(): array
    {
        return [
            [
                'key'         => 'docs_package_id',
                'name'        => 'Docs site package ID',
                'type'        => 'number',
                'column_type' => 'bigInteger',
                'description' => 'The package\'s id on the docs site. Set by sync.',
                'order'       => 10,
            ],
            [
                'key'         => 'registry',
                'name'        => 'Registry',
                'type'        => 'select',
                'column_type' => 'string',
                'description' => 'Where the package is published: packagist or npm.',
                'order'       => 20,
                'options'     => ['choices' => [
                    ['value' => self::REGISTRY_PACKAGIST, 'label' => 'Packagist'],
                    ['value' => self::REGISTRY_NPM, 'label' => 'npm'],
                ]],
            ],
            [
                'key'         => 'composer_name',
                'name'        => 'Composer name',
                'type'        => 'text',
                'column_type' => 'string',
                'description' => 'For Packagist packages, e.g. artisanpack-ui/accessibility.',
                'order'       => 30,
            ],
            [
                'key'         => 'npm_name',
                'name'        => 'npm name',
                'type'        => 'text',
                'column_type' => 'string',
                'description' => 'For npm packages, e.g. @artisanpack-ui/react.',
                'order'       => 40,
            ],
            [
                'key'         => 'github_repo',
                'name'        => 'GitHub repo',
                'type'        => 'text',
                'column_type' => 'string',
                'description' => 'owner/name, e.g. ArtisanPack-UI/accessibility. Maps the package to its issues on the org project.',
                'order'       => 50,
            ],
            [
                'key'         => 'version',
                'name'        => 'Version',
                'type'        => 'text',
                'column_type' => 'string',
                'description' => 'Latest stable release. Set by sync.',
                'order'       => 60,
            ],
            [
                'key'         => 'icon',
                'name'        => 'Icon',
                'type'        => IconPickerField::TYPE,
                'column_type' => 'text',
                'description' => 'iconRef JSON, e.g. {"set":"fas","name":"cube"}.',
                'order'       => 70,
            ],
            [
                'key'         => 'docs_url',
                'name'        => 'Docs URL',
                'type'        => 'url',
                'column_type' => 'string',
                'description' => 'The package\'s documentation on the docs site.',
                'order'       => 80,
            ],
            [
                'key'         => 'last_synced_at',
                'name'        => 'Last synced',
                'type'        => 'datetime',
                'column_type' => 'dateTime',
                'description' => 'When sync last updated this package. Set by sync.',
                'order'       => 90,
            ],
        ];
    }

    /**
     * Every field key, in display order.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_column(self::definitions(), 'key');
    }

    /**
     * The field holding a package's name on its registry (`composer_name`
     * or `npm_name`), or null for an unknown registry.
     */
    public static function registryNameColumn(?string $registry): ?string
    {
        return match ($registry) {
            self::REGISTRY_PACKAGIST => 'composer_name',
            self::REGISTRY_NPM       => 'npm_name',
            default                  => null,
        };
    }

    /**
     * The definitions not yet registered, given the keys that are.
     *
     * @param  list<string>  $registeredKeys
     *
     * @return list<FieldDefinition>
     */
    public static function missing(array $registeredKeys): array
    {
        return array_values(array_filter(
            self::definitions(),
            static fn (array $field): bool => ! in_array($field['key'], $registeredKeys, true),
        ));
    }

    /**
     * The definitions registered with a different type than they declare
     * now, keyed by field key, given each registered key's type. This is
     * how a field's type change (e.g. `icon` becoming an `icon_picker`)
     * reaches installs that registered it before.
     *
     * @param  array<string, string>  $registeredTypes  Field key → registered type.
     *
     * @return array<string, string> Field key → the type it should have.
     */
    public static function retyped(array $registeredTypes): array
    {
        $retyped = [];

        foreach (self::definitions() as $field) {
            $registered = $registeredTypes[$field['key']] ?? null;

            if (null !== $registered && $registered !== $field['type']) {
                $retyped[$field['key']] = $field['type'];
            }
        }

        return $retyped;
    }
}
