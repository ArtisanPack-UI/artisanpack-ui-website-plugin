<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Models;

use ArtisanPackUI\Site\Casts\SafeEncrypted;
use Illuminate\Database\Eloquent\Model;

/**
 * The plugin's integration settings: the docs site API, the GitHub App
 * installed on the ArtisanPack-UI org, and the release announcements (the
 * GitHub webhook secret and the Bluesky and Mastodon accounts).
 *
 * A single-row table. Read it through {@see self::current()}, which returns
 * an unsaved instance until the Settings page first saves.
 *
 * The docs API token and the GitHub App ID, private key and installation ID
 * are encrypted with the app key at rest through {@see SafeEncrypted}, which
 * reads a value the current key can't decrypt as unset. So are the webhook
 * secret, the Bluesky app password and the Mastodon access token. The
 * secrets never leave the server:
 * {@see \ArtisanPackUI\Site\Http\Controllers\SettingsController} only tells
 * the browser whether one is stored.
 *
 * @property int|null    $id
 * @property string|null $docs_base_url
 * @property string|null $docs_api_token
 * @property string|null $github_app_id
 * @property string|null $github_private_key
 * @property string|null $github_installation_id
 * @property string|null $github_organization
 * @property int|null    $github_project_number
 * @property string|null $github_webhook_secret
 * @property string|null $bluesky_handle
 * @property string|null $bluesky_app_password
 * @property string|null $mastodon_instance_url
 * @property string|null $mastodon_access_token
 * @property string|null $announcement_excluded_repositories
 *
 * @since 1.0.0
 */
final class IntegrationSettings extends Model
{
    /**
     * The org the GitHub App is installed on, used until one is saved.
     */
    public const DEFAULT_GITHUB_ORGANIZATION = 'ArtisanPack-UI';

    /**
     * Repositories whose releases are never announced until the Settings
     * page saves its own list: the site's own theme and plugin.
     *
     * @var list<string>
     */
    public const DEFAULT_EXCLUDED_REPOSITORIES = [
        'artisanpack-ui-website-theme',
        'artisanpack-ui-website-plugin',
    ];

    protected $table = 'artisanpack_ui_settings';

    /** @var list<string> */
    protected $fillable = [
        'docs_base_url',
        'docs_api_token',
        'github_app_id',
        'github_private_key',
        'github_installation_id',
        'github_organization',
        'github_project_number',
        'github_webhook_secret',
        'bluesky_handle',
        'bluesky_app_password',
        'mastodon_instance_url',
        'mastodon_access_token',
        'announcement_excluded_repositories',
    ];

    /**
     * Never serialize the secrets, even if a caller dumps the model.
     *
     * @var list<string>
     */
    protected $hidden = [
        'docs_api_token',
        'github_private_key',
        'github_webhook_secret',
        'bluesky_app_password',
        'mastodon_access_token',
    ];

    /**
     * The saved settings row, or an unsaved one when nothing is saved yet.
     */
    public static function current(): self
    {
        return self::query()->oldest('id')->first() ?? new self;
    }

    /**
     * The docs site base URL without a trailing slash, or null when unset.
     */
    public function docsBaseUrl(): ?string
    {
        $url = rtrim(trim((string) $this->docs_base_url), '/');

        return '' === $url ? null : $url;
    }

    /**
     * The org the GitHub App is installed on.
     */
    public function githubOrganization(): string
    {
        $organization = trim((string) $this->github_organization);

        return '' === $organization ? self::DEFAULT_GITHUB_ORGANIZATION : $organization;
    }

    /**
     * Whether the docs site has both a base URL and an API token.
     */
    public function hasDocsSite(): bool
    {
        return null !== $this->docsBaseUrl() && filled($this->docs_api_token);
    }

    /**
     * Whether the GitHub App has an app ID, private key and installation ID.
     */
    public function hasGitHubApp(): bool
    {
        return filled($this->github_app_id)
            && filled($this->github_private_key)
            && filled($this->github_installation_id);
    }

    /**
     * Whether a GitHub webhook secret is saved. Without one the webhook
     * endpoint refuses every delivery.
     */
    public function hasWebhookSecret(): bool
    {
        return filled($this->github_webhook_secret);
    }

    /**
     * The Bluesky handle without a leading `@`, or null when unset.
     */
    public function blueskyHandle(): ?string
    {
        $handle = ltrim(trim((string) $this->bluesky_handle), '@');

        return '' === $handle ? null : $handle;
    }

    /**
     * Whether Bluesky has both a handle and an app password.
     */
    public function hasBluesky(): bool
    {
        return null !== $this->blueskyHandle() && filled($this->bluesky_app_password);
    }

    /**
     * The Mastodon instance URL without a trailing slash, or null when unset.
     */
    public function mastodonInstanceUrl(): ?string
    {
        $url = rtrim(trim((string) $this->mastodon_instance_url), '/');

        return '' === $url ? null : $url;
    }

    /**
     * Whether Mastodon has both an instance URL and an access token.
     */
    public function hasMastodon(): bool
    {
        return null !== $this->mastodonInstanceUrl() && filled($this->mastodon_access_token);
    }

    /**
     * The repositories whose releases are never announced, lowercased: the
     * saved list (one name per line, which may be empty), or
     * {@see self::DEFAULT_EXCLUDED_REPOSITORIES} until one is saved.
     *
     * @return list<string>
     */
    public function excludedRepositories(): array
    {
        if (null === $this->announcement_excluded_repositories) {
            return self::DEFAULT_EXCLUDED_REPOSITORIES;
        }

        return self::parseRepositoryList($this->announcement_excluded_repositories);
    }

    /**
     * Split a newline- or comma-separated list of repository names into
     * unique, lowercased, non-empty names.
     *
     * @return list<string>
     */
    public static function parseRepositoryList(string $list): array
    {
        $names = preg_split('/[\s,]+/', strtolower($list)) ?: [];

        return array_values(array_unique(array_filter($names, static fn (string $name): bool => '' !== $name)));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'docs_api_token'         => SafeEncrypted::class,
            'github_app_id'          => SafeEncrypted::class,
            'github_private_key'     => SafeEncrypted::class,
            'github_installation_id' => SafeEncrypted::class,
            'github_project_number'  => 'integer',
            'github_webhook_secret'  => SafeEncrypted::class,
            'bluesky_app_password'   => SafeEncrypted::class,
            'mastodon_access_token'  => SafeEncrypted::class,
        ];
    }
}
