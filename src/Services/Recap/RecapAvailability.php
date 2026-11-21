<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Recap;

use ArtisanPackUI\Site\Models\IntegrationSettings;

/**
 * Whether the weekly recap can run: AI is set up, consented and the
 * feature is on ({@see RecapWriter::isAvailable()}), and the GitHub App the
 * releases are read through is configured.
 *
 * @since 1.6.0
 */
final class RecapAvailability
{
    public function __construct(
        private readonly RecapWriter $writer,
        private readonly IntegrationSettings $settings,
    ) {}

    public function isAvailable(): bool
    {
        return $this->settings->hasGitHubApp() && $this->writer->isAvailable();
    }
}
