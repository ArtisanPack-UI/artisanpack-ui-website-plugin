<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Jobs;

use ArtisanPackUI\Site\Exceptions\IntegrationNotConfiguredException;
use ArtisanPackUI\Site\Exceptions\SocialPostException;
use ArtisanPackUI\Site\Services\Social\BlueskyClient;
use ArtisanPackUI\Site\Services\Social\MastodonClient;
use ArtisanPackUI\Site\Services\Social\ReleaseAnnouncement;
use ArtisanPackUI\Site\Services\Social\SocialNetwork;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts one release announcement to one network.
 *
 * Dispatched once per configured network by
 * {@see \ArtisanPackUI\Site\Services\Social\ReleaseAnnouncer}, so a network
 * that is down is retried on its own and never re-posts to the other.
 *
 * A release is announced at most once per network: a posted release is
 * remembered for {@see self::POSTED_TTL_DAYS} days, so a webhook
 * redelivered later (or the same release reaching the site through two
 * webhooks) is skipped, and two copies never run at once
 * ({@see self::middleware()}). Not `ShouldBeUnique`: its lock is taken
 * before the job is queued and stays held if queueing throws, which would
 * silently swallow the redelivery meant to recover. Rejected credentials
 * and refused posts fail at once rather than retrying.
 *
 * @since 1.4.0
 */
final class PostReleaseAnnouncement implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Days a posted release is remembered.
     */
    public const POSTED_TTL_DAYS = 30;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly SocialNetwork $network,
        public readonly ReleaseAnnouncement $announcement,
    ) {}

    /**
     * The cache key marking the release as posted to the network.
     */
    public static function postedKey(SocialNetwork $network, int $releaseId): string
    {
        return 'artisanpack-ui:release-announced:' . $network->value . ':' . $releaseId;
    }

    /**
     * The network and release this job posts, which no two running jobs
     * share.
     */
    public function uniqueId(): string
    {
        return $this->network->value . ':' . $this->announcement->releaseId;
    }

    /**
     * A copy that starts while another is posting the same release to the
     * same network is dropped: the other one either posts it or retries.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('artisanpack-ui:release-announcement:' . $this->uniqueId()))->dontRelease()->expireAfter(120)];
    }

    /**
     * Seconds to wait before each retry.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(BlueskyClient $bluesky, MastodonClient $mastodon): void
    {
        $key = self::postedKey($this->network, $this->announcement->releaseId);

        if (Cache::has($key)) {
            return;
        }

        try {
            $post = match ($this->network) {
                SocialNetwork::Bluesky  => $bluesky->post($this->announcement),
                SocialNetwork::Mastodon => $mastodon->post($this->announcement),
            };
        } catch (IntegrationNotConfiguredException $exception) {
            // The network was removed from Settings after the job queued.
            Log::info('ArtisanPack UI skipped a release announcement for an unconfigured network.', $this->context());

            return;
        } catch (SocialPostException $exception) {
            if ($exception->permanent) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        }

        Cache::put($key, true, now()->addDays(self::POSTED_TTL_DAYS));

        Log::info('ArtisanPack UI announced a release.', [...$this->context(), 'post' => $post]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('ArtisanPack UI could not announce a release.', [
            ...$this->context(),
            'exception' => $exception?->getMessage(),
        ]);
    }

    /**
     * @return array{network: string, repository: string, tag: string}
     */
    private function context(): array
    {
        return [
            'network'    => $this->network->label(),
            'repository' => $this->announcement->repository,
            'tag'        => $this->announcement->tag,
        ];
    }
}
