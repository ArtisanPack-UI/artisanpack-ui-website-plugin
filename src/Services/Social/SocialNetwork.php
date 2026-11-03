<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Social;

/**
 * A network release announcements are posted to.
 *
 * @since 1.4.0
 */
enum SocialNetwork: string
{
    case Bluesky = 'bluesky';

    case Mastodon = 'mastodon';

    public function label(): string
    {
        return match ($this) {
            self::Bluesky  => 'Bluesky',
            self::Mastodon => 'Mastodon',
        };
    }
}
