<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Exceptions;

use RuntimeException;

/**
 * Thrown when an API client is used before its connection details are saved
 * on the plugin's Settings page. The message is safe to show an admin.
 *
 * @since 0.2.0
 */
final class IntegrationNotConfiguredException extends RuntimeException
{
    public static function docsSite(): self
    {
        return new self(__('The docs site isn\'t configured yet. Add its base URL and API token in ArtisanPack UI settings.'));
    }

    public static function gitHubApp(): self
    {
        return new self(__('The GitHub App isn\'t configured yet. Add its App ID, private key and installation ID in ArtisanPack UI settings.'));
    }

    public static function gitHubProject(): self
    {
        return new self(__('The org GitHub Project isn\'t configured yet. Add its project number in ArtisanPack UI settings.'));
    }
}
