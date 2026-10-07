<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Docs;

use Illuminate\Support\Carbon;

/**
 * A package as the docs site's `PackageResource` describes it.
 *
 * The docs site has no Composer/npm name or GitHub repo field: the registry
 * name is derived from the slug ({@see self::registryName()}), and the repo
 * lives in the GitHub `docs_url` / `wiki_url` / `changelog_url` links.
 *
 * @since 0.2.0
 */
final class DocsPackage
{
    /**
     * @param  array{raw: string|null, set: string|null, name: string|null, svg: string|null}|null  $icon
     * @param  array<string, DocsImportStatus>  $imports  Keyed `docs` and `changelog`.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $version,
        public readonly ?string $registry,
        public readonly ?string $docsUrl,
        public readonly ?string $wikiUrl,
        public readonly ?string $changelogUrl,
        public readonly ?int $homepage,
        public readonly ?array $icon,
        public readonly array $imports,
        public readonly ?Carbon $updatedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $icon    = is_array($data['icon'] ?? null) ? $data['icon'] : null;
        $imports = is_array($data['imports'] ?? null) ? $data['imports'] : [];

        return new self(
            id: (int) ($data['id'] ?? 0),
            name: (string) ($data['name'] ?? ''),
            slug: (string) ($data['slug'] ?? ''),
            version: self::nullableString($data['version'] ?? null),
            registry: self::nullableString($data['package_registry'] ?? null),
            docsUrl: self::nullableString($data['docs_url'] ?? null),
            wikiUrl: self::nullableString($data['wiki_url'] ?? null),
            changelogUrl: self::nullableString($data['changelog_url'] ?? null),
            homepage: is_numeric($data['homepage'] ?? null) ? (int) $data['homepage'] : null,
            icon: null === $icon ? null : [
                'raw'  => self::nullableString($icon['raw'] ?? null),
                'set'  => self::nullableString($icon['set'] ?? null),
                'name' => self::nullableString($icon['name'] ?? null),
                'svg'  => self::nullableString($icon['svg'] ?? null),
            ],
            imports: [
                'docs'      => DocsImportStatus::fromArray(is_array($imports['docs'] ?? null) ? $imports['docs'] : []),
                'changelog' => DocsImportStatus::fromArray(is_array($imports['changelog'] ?? null) ? $imports['changelog'] : []),
            ],
            updatedAt: self::nullableDate($data['updated_at'] ?? null),
        );
    }

    /**
     * The Composer (`artisanpack-ui/{slug}`) or npm (`@artisanpack-ui/{slug}`)
     * name, matching the docs site's own `getRegistryPackageName()`.
     */
    public function registryName(): ?string
    {
        return match ($this->registry) {
            'packagist' => 'artisanpack-ui/' . $this->slug,
            'npm'       => '@artisanpack-ui/' . $this->slug,
            default     => null,
        };
    }

    /**
     * The `owner/name` GitHub repo, read from the first of the changelog,
     * docs and wiki links that points at one (the docs site requires them
     * to be GitHub URLs).
     */
    public function githubRepo(): ?string
    {
        foreach ([$this->changelogUrl, $this->docsUrl, $this->wikiUrl] as $url) {
            if (null !== $url && 1 === preg_match('#^https?://(?:www\.)?github\.com/([A-Za-z0-9-]+)/([A-Za-z0-9._-]+?)(?:\.git)?(?:[/?\#]|$)#i', $url, $matches)) {
                return $matches[1] . '/' . $matches[2];
            }
        }

        return null;
    }

    /**
     * The fields `PATCH /packages/{package}` requires. The docs site
     * validates a PATCH like a create, so an update must resend them all;
     * `icon` is written back as the raw string the docs site stores.
     *
     * The docs site only accepts GitHub links, but older packages still
     * hold links it no longer accepts (GitLab wikis). Those are left out
     * rather than resent, so the docs site keeps its stored value and the
     * PATCH isn't refused over a field it never meant to change.
     *
     * @return array<string, mixed>
     */
    public function toWritePayload(): array
    {
        $payload = [
            'name'             => $this->name,
            'slug'             => $this->slug,
            'homepage'         => $this->homepage,
            'docs_url'         => $this->docsUrl,
            'wiki_url'         => $this->wikiUrl,
            'changelog_url'    => $this->changelogUrl,
            'icon'             => $this->icon['raw'] ?? null,
            'version'          => $this->version,
            'package_registry' => $this->registry,
        ];

        foreach (['docs_url', 'wiki_url', 'changelog_url'] as $link) {
            if (null !== $payload[$link] && ! self::isGitHubUrl($payload[$link])) {
                unset($payload[$link]);
            }
        }

        return $payload;
    }

    private static function isGitHubUrl(string $url): bool
    {
        return 1 === preg_match('#^https?://(?:www\.)?github\.com/#i', $url);
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && '' !== $value ? $value : null;
    }

    public static function nullableDate(mixed $value): ?Carbon
    {
        return is_string($value) && '' !== $value ? Carbon::parse($value) : null;
    }
}
