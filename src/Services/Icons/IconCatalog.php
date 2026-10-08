<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Icons;

use ArtisanPackUI\Site\Support\PackageIconSet;
use ArtisanPackUI\VisualEditor\Services\Icon\IconSvgResolver;

/**
 * What the icon picker field offers: every set the visual editor's
 * `IconSvgResolver` can serve and the icons in each, with search.
 *
 * The resolver's set paths are the bundled Font Awesome Free sets plus
 * everything registered through `ap.icons.registerIconSets`: the
 * artisanpack-ui/icons sets, admin-uploaded sets and {@see PackageIconSet}.
 * The visual editor's own picker catalog covers only Font Awesome and
 * uploaded sets, so it would miss the rest; reading the resolver instead
 * means anything picked here is guaranteed to render in the icon block.
 *
 * @phpstan-type IconEntry array{set: string, name: string, svg: string}
 * @phpstan-type SetEntry array{prefix: string, label: string, count: int}
 *
 * @since 1.0.0
 */
final class IconCatalog
{
    public const PER_PAGE = 60;

    /** Friendly names for the sets the site is known to carry. */
    private const LABELS = [
        'fas'                  => 'Font Awesome Solid',
        'far'                  => 'Font Awesome Regular',
        'fab'                  => 'Font Awesome Brands',
        PackageIconSet::PREFIX => 'ArtisanPack UI',
    ];

    /** @var array<string, list<string>>|null */
    private ?array $names = null;

    public function __construct(private readonly IconSvgResolver $resolver) {}

    /**
     * Every set, in prefix order, with how many icons it holds.
     *
     * @return list<SetEntry>
     */
    public function sets(): array
    {
        $sets = [];

        foreach ($this->names() as $prefix => $names) {
            $sets[] = ['prefix' => $prefix, 'label' => self::LABELS[$prefix] ?? $prefix, 'count' => count($names)];
        }

        return $sets;
    }

    /**
     * One page of icons whose name contains every word of `$query`,
     * optionally within one set, each with its SVG for the preview. An
     * exact name match ranks first and names starting with the query next,
     * so the icon being searched for is on the first page.
     *
     * @return array{total: int, icons: list<IconEntry>}
     */
    public function search(string $query = '', ?string $set = null, int $page = 1): array
    {
        $words   = array_filter(preg_split('/[\s_-]+/', strtolower(trim($query))) ?: []);
        $matches = [];

        foreach ($this->names() as $prefix => $names) {
            if (null !== $set && $set !== $prefix) {
                continue;
            }

            foreach ($names as $name) {
                if (self::matches($name, $words)) {
                    $matches[] = [$prefix, $name];
                }
            }
        }

        $needle = strtolower(trim($query));

        if ('' !== $needle) {
            usort($matches, static fn (array $a, array $b): int => self::rank($a[1], $needle) <=> self::rank($b[1], $needle));
        }

        $icons = [];

        foreach (array_slice($matches, (max(1, $page) - 1) * self::PER_PAGE, self::PER_PAGE) as [$prefix, $name]) {
            $icons[] = ['set' => $prefix, 'name' => $name, 'svg' => (string) $this->resolver->resolve($prefix, $name)];
        }

        return ['total' => count($matches), 'icons' => $icons];
    }

    /**
     * 0 for an exact match, 1 for a prefix match, 2 otherwise.
     */
    private static function rank(string $name, string $needle): int
    {
        $name = strtolower($name);

        return match (true) {
            $name === $needle               => 0,
            str_starts_with($name, $needle) => 1,
            default                         => 2,
        };
    }

    /**
     * @param  list<string>  $words
     */
    private static function matches(string $name, array $words): bool
    {
        foreach ($words as $word) {
            if (! str_contains(strtolower($name), $word)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Icon names per set prefix, read from each set's directory once.
     *
     * @return array<string, list<string>>
     */
    private function names(): array
    {
        if (null !== $this->names) {
            return $this->names;
        }

        $this->names = [];
        $paths       = $this->resolver->setPaths();
        ksort($paths);

        foreach ($paths as $prefix => $path) {
            $files = is_dir($path) ? (glob($path . DIRECTORY_SEPARATOR . '*.svg') ?: []) : [];
            $names = array_values(array_filter(
                array_map(static fn (string $file): string => basename($file, '.svg'), $files),
                PackageIconSet::isValidName(...),
            ));
            sort($names);

            $this->names[(string) $prefix] = $names;
        }

        return $this->names;
    }
}
