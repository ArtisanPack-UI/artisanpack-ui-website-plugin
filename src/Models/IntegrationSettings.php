<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Models;

use ArtisanPackUI\Site\Casts\SafeEncrypted;
use Illuminate\Database\Eloquent\Model;

/**
 * The plugin's integration settings: the docs site API and the GitHub App
 * installed on the ArtisanPack-UI org.
 *
 * A single-row table. Read it through {@see self::current()}, which returns
 * an unsaved instance until the Settings page first saves.
 *
 * The docs API token and the GitHub App ID, private key and installation ID
 * are encrypted with the app key at rest through {@see SafeEncrypted}, which
 * reads a value the current key can't decrypt as unset. The token and private key are secrets and never leave the server:
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
 *
 * @since 1.0.0
 */
final class IntegrationSettings extends Model
{
    /**
     * The org the GitHub App is installed on, used until one is saved.
     */
    public const DEFAULT_GITHUB_ORGANIZATION = 'ArtisanPack-UI';

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
    ];

    /**
     * Never serialize the secrets, even if a caller dumps the model.
     *
     * @var list<string>
     */
    protected $hidden = ['docs_api_token', 'github_private_key'];

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
        ];
    }
}
