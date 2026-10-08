<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Support;

use ArtisanPackUI\Icons\Registries\IconSetRegistration;
use ArtisanPackUI\Site\Exceptions\InvalidIconException;
use ArtisanPackUI\VisualEditor\Services\Icon\SvgSanitizer;
use RuntimeException;

/**
 * The `apui` icon set: custom package icons that exist only on the docs
 * site (its `ap.*` set), mirrored here so the icon picker and the
 * `artisanpack/icon` block can resolve them (roadmap 1.3).
 *
 * The set is a directory of SVGs in plugin storage, registered through
 * `ap.icons.registerIconSets`, which is what the visual editor's
 * `IconSvgResolver` reads its set paths from. The icon sync (roadmap 2.3)
 * fills it through {@see self::write()}, the only way an SVG gets in: the
 * markup goes through the visual editor's `SvgSanitizer` first, only the
 * sanitized output is stored, and markup that doesn't survive
 * sanitization is rejected.
 *
 * Markup carrying a DOCTYPE or entity declaration is refused before it
 * reaches the sanitizer, as defense in depth: an icon never needs either,
 * and neither should ever reach an XML parser from a remote source.
 *
 * @since 1.0.0
 */
final class PackageIconSet
{
    /** The set prefix an iconRef names, e.g. `{"set":"apui","name":"puzzle"}`. */
    public const PREFIX = 'apui';

    /** The docs site's prefix for the same custom icons. */
    public const DOCS_SET = 'ap';

    /**
     * Icon names the resolver will look up: the same character class
     * `IconSvgResolver` and `IconBlock` accept, so a stored icon is always
     * one they can serve.
     */
    private const NAME_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,99}$/i';

    /** DOCTYPE and entity declarations, refused outright (see the class docblock). */
    private const DECLARATION_PATTERN = '/<!\s*(DOCTYPE|ENTITY)/i';

    public function __construct(
        private readonly SvgSanitizer $sanitizer,
        private readonly string $directory,
    ) {}

    /**
     * Where the set lives unless a test binds another directory.
     */
    public static function defaultDirectory(): string
    {
        return storage_path('app/artisanpack-ui/icons');
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Add the set to an `ap.icons.registerIconSets` registry, creating the
     * directory first because the registry refuses a missing one.
     */
    public function register(IconSetRegistration $registry): IconSetRegistration
    {
        $this->ensureDirectory();

        return $registry->addSet($this->directory, self::PREFIX);
    }

    /**
     * Whether `$name` is an icon name the set can hold.
     */
    public static function isValidName(string $name): bool
    {
        return ! str_contains($name, '..') && 1 === preg_match(self::NAME_PATTERN, $name);
    }

    /**
     * Sanitize `$svg` and store the result as `{name}.svg`, replacing any
     * icon of that name.
     *
     * @throws InvalidIconException When the name is invalid or nothing usable survives sanitization.
     */
    public function write(string $name, string $svg): void
    {
        if (! self::isValidName($name)) {
            throw new InvalidIconException(__('":name" isn\'t a valid icon name.', ['name' => $name]));
        }

        if (1 === preg_match(self::DECLARATION_PATTERN, $svg)) {
            throw new InvalidIconException(__('The SVG for the ":name" icon was rejected: it declares a DOCTYPE or entities.', ['name' => $name]));
        }

        $result = $this->sanitizer->sanitize($svg);

        if ($result->isEmpty()) {
            throw new InvalidIconException(__('The SVG for the ":name" icon was rejected: :reason.', [
                'name'   => $name,
                'reason' => $result->warnings[0] ?? __('it is empty'),
            ]));
        }

        $this->ensureDirectory();

        $path      = $this->directory . DIRECTORY_SEPARATOR . $name . '.svg';
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (false === file_put_contents($temporary, $result->sanitized, LOCK_EX) || ! rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException(sprintf('Could not write the "%s" icon to %s.', $name, $this->directory));
        }
    }

    private function ensureDirectory(): void
    {
        if (! is_dir($this->directory) && ! mkdir($this->directory, 0o755, true) && ! is_dir($this->directory)) {
            throw new RuntimeException(sprintf('Could not create the icon set directory %s.', $this->directory));
        }
    }
}
