<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Support;

/**
 * The `{set, name}` iconRef a package's `icon` field holds: the shape the
 * visual editor's `artisanpack/icon` block takes as its `iconRef`
 * attribute, so the field can be bound to the block as-is.
 *
 * @phpstan-type IconRefShape array{set: string, name: string}
 *
 * @since 0.3.0
 */
final class IconRef
{
    /** The set prefixes `IconBlock` and `IconSvgResolver` accept. */
    private const SET_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/i';

    /**
     * The iconRef in `$value` (an array, or its JSON as the edit screen
     * saves it), trimmed, or null when it isn't a usable one.
     *
     * @return IconRefShape|null
     */
    public static function normalize(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (! is_array($value) || ! is_string($value['set'] ?? null) || ! is_string($value['name'] ?? null)) {
            return null;
        }

        $set  = trim($value['set']);
        $name = trim($value['name']);

        if (1 !== preg_match(self::SET_PATTERN, $set) || ! PackageIconSet::isValidName($name)) {
            return null;
        }

        return ['set' => $set, 'name' => $name];
    }
}
