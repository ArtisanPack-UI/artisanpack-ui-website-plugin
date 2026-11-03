<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Social;

use ArtisanPackUI\Site\Jobs\PostReleaseAnnouncement;
use ArtisanPackUI\Site\Models\IntegrationSettings;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;

/**
 * Decides whether a `release` webhook delivery is announced, and queues a
 * {@see PostReleaseAnnouncement} per configured network when it is.
 *
 * Keyed on the `released` action, which GitHub sends when a release is
 * published and when a prerelease is promoted to a release (`published`
 * also fires for prereleases, and not for a promotion).
 *
 * Only a newly published, final release of a public repository in the
 * configured org is announced: drafts, prereleases, private repositories,
 * other orgs, releases published more than {@see self::MAX_AGE_HOURS}
 * hours ago (a replayed old delivery) and the excluded repositories
 * ({@see IntegrationSettings::excludedRepositories()}) are skipped. Every
 * outcome carries a reason, which the webhook returns so GitHub's delivery
 * log shows why a release was or wasn't posted.
 *
 * @phpstan-type Outcome array{announced: bool, reason: string, networks: list<string>}
 *
 * @since 1.4.0
 */
final class ReleaseAnnouncer
{
    /**
     * Hours after publishing that a release is still announced. GitHub
     * delivers within seconds; this leaves room for a manual redelivery.
     */
    public const MAX_AGE_HOURS = 24;

    public function __construct(private readonly IntegrationSettings $settings) {}

    /**
     * @param  array<string, mixed>  $payload  The `release` webhook payload.
     *
     * @return Outcome
     */
    public function handle(array $payload): array
    {
        $release    = is_array($payload['release'] ?? null) ? $payload['release'] : [];
        $repository = is_array($payload['repository'] ?? null) ? $payload['repository'] : [];
        $owner      = is_array($repository['owner'] ?? null) ? (string) ($repository['owner']['login'] ?? '') : '';
        $name       = strtolower((string) ($repository['name'] ?? ''));

        $skip = match (true) {
            'released' !== ($payload['action'] ?? null)                      => 'not-released',
            true === ($release['draft'] ?? false)                            => 'draft',
            true === ($release['prerelease'] ?? false)                       => 'prerelease',
            false !== ($repository['private'] ?? true)                       => 'private-repository',
            0 !== strcasecmp($owner, $this->settings->githubOrganization())  => 'other-organization',
            in_array($name, $this->settings->excludedRepositories(), true)   => 'excluded-repository',
            self::isStale($release['published_at'] ?? null)                  => 'stale-release',
            default                                                          => null,
        };

        if (null !== $skip) {
            return self::outcome(false, $skip);
        }

        $announcement = ReleaseAnnouncement::fromPayload($payload);

        if (null === $announcement) {
            return self::outcome(false, 'incomplete-payload');
        }

        $networks = $this->configuredNetworks();

        if ([] === $networks) {
            return self::outcome(false, 'no-networks');
        }

        foreach ($networks as $network) {
            PostReleaseAnnouncement::dispatch($network, $announcement);
        }

        return self::outcome(true, 'queued', array_map(static fn (SocialNetwork $network): string => $network->value, $networks));
    }

    /**
     * Whether the release was published too long ago, or says nothing
     * readable about when.
     */
    private static function isStale(mixed $publishedAt): bool
    {
        if (! is_string($publishedAt) || '' === $publishedAt) {
            return true;
        }

        try {
            return now()->subHours(self::MAX_AGE_HOURS)->greaterThan(Carbon::parse($publishedAt));
        } catch (InvalidFormatException) {
            return true;
        }
    }

    /**
     * @return list<SocialNetwork>
     */
    private function configuredNetworks(): array
    {
        return array_values(array_filter([
            $this->settings->hasBluesky() ? SocialNetwork::Bluesky : null,
            $this->settings->hasMastodon() ? SocialNetwork::Mastodon : null,
        ]));
    }

    /**
     * @param  list<string>  $networks
     *
     * @return Outcome
     */
    private static function outcome(bool $announced, string $reason, array $networks = []): array
    {
        return ['announced' => $announced, 'reason' => $reason, 'networks' => $networks];
    }
}
