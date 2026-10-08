<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Support;

/**
 * The `icon_picker` custom-field type (roadmap 1.2). It stores an
 * {@see IconRef} as JSON text: the same `{set, name}` the visual editor's
 * `artisanpack/icon` block takes as `iconRef`, so a block can bind
 * `iconRef` to the field through the `custom_field` binding source.
 *
 * The provider registers it with `apRegisterFieldType()`. Keystone's
 * dynamic content-type edit screen doesn't dispatch on field type yet
 * (every custom field is a text input there; see the 0.1 spike notes in
 * `plans/roadmap.md`), so the picker UI is mounted by the plugin's boot
 * module next to that input and writes the same form value.
 *
 * @since 1.0.0
 */
final class IconPickerField
{
    public const TYPE = 'icon_picker';

    /** The editor the boot module mounts for this type. */
    public const EDITOR_COMPONENT = 'artisanpack-ui.IconPickerField';

    /**
     * The definition handed to `apRegisterFieldType()`.
     *
     * @return array{label: string, column_type: string, validation_rules: list<string>, editor_component: string}
     */
    public static function definition(): array
    {
        return [
            'label'            => __('Icon picker'),
            'column_type'      => 'text',
            'validation_rules' => ['nullable', 'json'],
            'editor_component' => self::EDITOR_COMPONENT,
        ];
    }
}
