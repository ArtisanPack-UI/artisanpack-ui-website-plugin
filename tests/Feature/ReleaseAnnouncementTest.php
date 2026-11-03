<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Jobs\PostReleaseAnnouncement;
use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Services\Social\BlueskyClient;
use ArtisanPackUI\Site\Services\Social\MastodonClient;
use ArtisanPackUI\Site\Services\Social\ReleaseAnnouncement;
use ArtisanPackUI\Site\Services\Social\SocialNetwork;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const WEBHOOK_SECRET = 'a-webhook-secret-of-some-length';

/**
 * Save working webhook, Bluesky and Mastodon settings.
 *
 * @param  array<string, mixed>  $overrides
 */
function configureAnnouncements(array $overrides = []): IntegrationSettings
{
    return IntegrationSettings::create([
        'github_organization'   => 'ArtisanPack-UI',
        'github_webhook_secret' => WEBHOOK_SECRET,
        'bluesky_handle'        => 'artisanpackui.bsky.social',
        'bluesky_app_password'  => 'abcd-efgh-ijkl-mnop',
        'mastodon_instance_url' => 'https://mastodon.example',
        'mastodon_access_token' => 'mastodon-token',
        ...$overrides,
    ]);
}

/**
 * A `release` webhook payload.
 *
 * @param  array<string, mixed>  $release
 * @param  array<string, mixed>  $repository
 *
 * @return array<string, mixed>
 */
function releasePayload(array $release = [], array $repository = [], string $action = 'released'): array
{
    return [
        'action'  => $action,
        'release' => [
            'id'         => 4242,
            'tag_name'   => 'v1.9.0',
            'name'       => 'v1.9.0',
            'html_url'   => 'https://github.com/ArtisanPack-UI/seo/releases/tag/v1.9.0',
            'draft'        => false,
            'prerelease'   => false,
            'published_at' => now()->subMinute()->toIso8601ZuluString(),
            ...$release,
        ],
        'repository' => [
            'name'      => 'seo',
            'full_name' => 'ArtisanPack-UI/seo',
            'private'   => false,
            'owner'   => ['login' => 'ArtisanPack-UI'],
            ...$repository,
        ],
    ];
}

/**
 * POST a signed delivery to the webhook.
 *
 * @param  array<string, mixed>  $payload
 */
function deliver(array $payload, string $event = 'release', ?string $secret = WEBHOOK_SECRET, ?string $delivery = null): Illuminate\Testing\TestResponse
{
    $body    = json_encode($payload, JSON_THROW_ON_ERROR);
    $headers = [
        'X-GitHub-Event'    => $event,
        'X-GitHub-Delivery' => $delivery ?? (string) Illuminate\Support\Str::uuid(),
        'Content-Type'      => 'application/json',
    ];

    if ('' === $delivery) {
        unset($headers['X-GitHub-Delivery']);
    }

    if (null !== $secret) {
        $headers['X-Hub-Signature-256'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    return test()->call('POST', '/plugins/artisanpack-ui/webhooks/github', [], [], [], test()->transformHeadersToServerVars($headers), $body);
}

function seoAnnouncement(string $title = 'v1.9.0'): ReleaseAnnouncement
{
    return new ReleaseAnnouncement(4242, 'seo', 'v1.9.0', $title, 'https://github.com/ArtisanPack-UI/seo/releases/tag/v1.9.0');
}

/**
 * Fake both networks answering successfully.
 */
function fakeNetworks(): void
{
    Http::fake([
        'bsky.social/xrpc/com.atproto.server.createSession' => Http::response([
            'accessJwt' => 'jwt',
            'did'       => 'did:plc:abcdefghijklmnopqrstuvwx',
            'handle'    => 'artisanpackui.bsky.social',
        ]),
        'bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response(['uri' => 'at://did:plc:abc/app.bsky.feed.post/1', 'cid' => 'cid']),
        'bsky.social/xrpc/com.atproto.repo.getRecord*'   => Http::response(['error' => 'RecordNotFound', 'message' => 'Could not locate record'], 400),
        'mastodon.example/api/v1/statuses'               => Http::response(['url' => 'https://mastodon.example/@artisanpackui/1']),
        'mastodon.example/api/v1/accounts/verify_credentials' => Http::response(['acct' => 'artisanpackui']),
    ]);
}

describe('webhook', function (): void {
    beforeEach(function (): void {
        Queue::fake();
    });

    it('queues one post per configured network for a published release', function (): void {
        configureAnnouncements();

        deliver(releasePayload())
            ->assertAccepted()
            ->assertJson(['announced' => true, 'reason' => 'queued', 'networks' => ['bluesky', 'mastodon']]);

        Queue::assertPushed(PostReleaseAnnouncement::class, 2);
        Queue::assertPushed(PostReleaseAnnouncement::class, fn (PostReleaseAnnouncement $job): bool => SocialNetwork::Bluesky === $job->network
            && 'seo' === $job->announcement->repository
            && 'v1.9.0' === $job->announcement->tag
            && 4242 === $job->announcement->releaseId);
    });

    it('only queues the networks that are configured', function (): void {
        configureAnnouncements(['mastodon_access_token' => null]);

        deliver(releasePayload())->assertAccepted()->assertJsonPath('networks', ['bluesky']);

        Queue::assertPushed(PostReleaseAnnouncement::class, 1);
    });

    it('skips releases that should not be announced', function (array $payload, string $reason): void {
        configureAnnouncements();

        deliver($payload)->assertAccepted()->assertJson(['announced' => false, 'reason' => $reason]);

        Queue::assertNothingPushed();
    })->with([
        'published'        => [fn () => releasePayload(action: 'published'), 'not-released'],
        'edited'           => [fn () => releasePayload(action: 'edited'), 'not-released'],
        'created'          => [fn () => releasePayload(action: 'created'), 'not-released'],
        'draft'            => [fn () => releasePayload(['draft' => true]), 'draft'],
        'prerelease'       => [fn () => releasePayload(['prerelease' => true]), 'prerelease'],
        'private repo'     => [fn () => releasePayload(repository: ['private' => true]), 'private-repository'],
        'no privacy flag'  => [fn () => array_replace_recursive(releasePayload(), ['repository' => ['private' => null]]), 'private-repository'],
        'other org'        => [fn () => releasePayload(repository: ['owner' => ['login' => 'someone-else']]), 'other-organization'],
        'website theme'    => [fn () => releasePayload(repository: ['name' => 'artisanpack-ui-website-theme']), 'excluded-repository'],
        'website plugin'   => [fn () => releasePayload(repository: ['name' => 'ArtisanPack-UI-Website-Plugin']), 'excluded-repository'],
        'non-GitHub link'  => [fn () => releasePayload(['html_url' => 'https://evil.example/x']), 'incomplete-payload'],
        'other repo link'  => [fn () => releasePayload(['html_url' => 'https://github.com/someone/else/releases/tag/v1.9.0']), 'incomplete-payload'],
        'missing tag'      => [fn () => releasePayload(['tag_name' => '']), 'incomplete-payload'],
        'non-version tag'  => [fn () => releasePayload(['tag_name' => 'see https://evil.example']), 'incomplete-payload'],
        'old release'      => [fn () => releasePayload(['published_at' => now()->subDays(2)->toIso8601ZuluString()]), 'stale-release'],
        'no publish date'  => [fn () => releasePayload(['published_at' => null]), 'stale-release'],
        'bad publish date' => [fn () => releasePayload(['published_at' => 'yesterday-ish']), 'stale-release'],
    ]);

    it('matches the org case-insensitively', function (): void {
        configureAnnouncements();

        deliver(releasePayload(repository: ['owner' => ['login' => 'artisanpack-ui']]))->assertJsonPath('announced', true);
    });

    it('uses the saved exclusion list instead of the defaults', function (): void {
        configureAnnouncements(['announcement_excluded_repositories' => "seo\nforms"]);

        deliver(releasePayload())->assertJsonPath('reason', 'excluded-repository');
        deliver(releasePayload(repository: ['name' => 'artisanpack-ui-website-theme']))->assertJsonPath('announced', true);
    });

    it('excludes nothing when the saved list is empty', function (): void {
        configureAnnouncements(['announcement_excluded_repositories' => '']);

        deliver(releasePayload(repository: ['name' => 'artisanpack-ui-website-plugin']))->assertJsonPath('announced', true);
    });

    it('reports when no network is configured', function (): void {
        configureAnnouncements(['bluesky_app_password' => null, 'mastodon_instance_url' => null]);

        deliver(releasePayload())->assertJson(['announced' => false, 'reason' => 'no-networks']);

        Queue::assertNothingPushed();
    });

    it('rejects a delivery with a bad or missing signature', function (?string $secret): void {
        configureAnnouncements();

        deliver(releasePayload(), secret: $secret)->assertUnauthorized();

        Queue::assertNothingPushed();
    })->with([
        'wrong secret' => 'not-the-secret',
        'unsigned'     => null,
    ]);

    it('rejects a body that was changed after signing', function (): void {
        configureAnnouncements();
        $signed = json_encode(releasePayload(), JSON_THROW_ON_ERROR);
        $body   = json_encode(releasePayload(repository: ['name' => 'forms']), JSON_THROW_ON_ERROR);

        $this->call('POST', '/plugins/artisanpack-ui/webhooks/github', [], [], [], $this->transformHeadersToServerVars([
            'X-GitHub-Event'      => 'release',
            'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $signed, WEBHOOK_SECRET),
        ]), $body)->assertUnauthorized();
    });

    it('answers 404 until a webhook secret is saved', function (): void {
        configureAnnouncements(['github_webhook_secret' => null]);

        deliver(releasePayload())->assertNotFound();

        Queue::assertNothingPushed();
    });

    it('answers a ping', function (): void {
        configureAnnouncements();

        deliver(['zen' => 'Keep it logically awesome.'], 'ping')->assertOk()->assertJson(['message' => 'pong']);
    });

    it('accepts and ignores other events', function (): void {
        configureAnnouncements();

        deliver(['action' => 'created'], 'discussion')->assertAccepted()->assertJsonPath('event', 'discussion');

        Queue::assertNothingPushed();
    });

    it('refuses a delivery without an id', function (): void {
        configureAnnouncements();

        deliver(releasePayload(), delivery: '')->assertBadRequest();

        Queue::assertNothingPushed();
    });

    it('lets a delivery be redelivered when queueing it failed', function (): void {
        configureAnnouncements();
        $dispatcher = app(Illuminate\Contracts\Bus\Dispatcher::class);
        $failing    = Mockery::mock(Illuminate\Contracts\Bus\Dispatcher::class);
        $failing->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));
        app()->instance(Illuminate\Contracts\Bus\Dispatcher::class, $failing);

        deliver(releasePayload(), delivery: 'delivery-2')->assertServerError();

        app()->instance(Illuminate\Contracts\Bus\Dispatcher::class, $dispatcher);
        deliver(releasePayload(), delivery: 'delivery-2')->assertAccepted()->assertJsonPath('announced', true);

        Queue::assertPushed(PostReleaseAnnouncement::class, 2);
    });

    it('queues every network before rethrowing a dispatch failure', function (): void {
        configureAnnouncements();
        $dispatcher = app(Illuminate\Contracts\Bus\Dispatcher::class);
        $failing    = Mockery::mock(Illuminate\Contracts\Bus\Dispatcher::class);
        $failing->shouldReceive('dispatch')->once()->ordered()->andThrow(new RuntimeException('bluesky job failed'));
        $failing->shouldReceive('dispatch')->once()->ordered()->with(Mockery::on(
            fn (PostReleaseAnnouncement $job): bool => SocialNetwork::Mastodon === $job->network,
        ));
        app()->instance(Illuminate\Contracts\Bus\Dispatcher::class, $failing);

        deliver(releasePayload())->assertServerError();

        app()->instance(Illuminate\Contracts\Bus\Dispatcher::class, $dispatcher);
    });

    it('announces a prerelease once it is promoted to a release', function (): void {
        configureAnnouncements();

        deliver(releasePayload(['prerelease' => true], action: 'published'))->assertJsonPath('announced', false);
        deliver(releasePayload(['tag_name' => 'v2.0.0-rc.1', 'html_url' => 'https://github.com/ArtisanPack-UI/seo/releases/tag/v2.0.0-rc.1']))
            ->assertJsonPath('announced', true);
    });

    it('handles a redelivered delivery once', function (): void {
        configureAnnouncements();

        deliver(releasePayload(), delivery: 'delivery-1')->assertAccepted();
        deliver(releasePayload(), delivery: 'delivery-1')->assertOk()->assertJson(['message' => 'Delivery already handled.']);

        Queue::assertPushed(PostReleaseAnnouncement::class, 2);
    });

    it('needs no session or CSRF token', function (): void {
        configureAnnouncements();

        deliver(releasePayload())->assertAccepted()->assertCookieMissing(config('session.cookie'));
    });
});

describe('announcement text', function (): void {
    it('announces the repository and tag, with the release URL last', function (): void {
        expect(seoAnnouncement()->text())->toBe("📦 seo v1.9.0 is out!\n\nhttps://github.com/ArtisanPack-UI/seo/releases/tag/v1.9.0");
    });

    it('adds the release title when it says more than the tag', function (): void {
        expect(seoAnnouncement('Public URL support')->text())
            ->toBe("📦 seo v1.9.0 is out!\nPublic URL support\n\nhttps://github.com/ArtisanPack-UI/seo/releases/tag/v1.9.0")
            ->and(seoAnnouncement('seo v1.9.0')->summary())->toBe('');
    });

    it('defuses mentions, hashtags and links in the release title', function (): void {
        $summary = seoAnnouncement('Fix for @victim@mastodon.social #php https://evil.example')->summary();

        expect($summary)->toBe("Fix for @\u{200B}victim@\u{200B}mastodon.social #\u{200B}php https:\u{200B}//evil.example")
            ->and(seoAnnouncement('Fix https://evil.example')->text())->toEndWith("\n\nhttps://github.com/ArtisanPack-UI/seo/releases/tag/v1.9.0");
    });

    it('leaves the title out of a post that would pass the Bluesky limit', function (): void {
        $title = 'A meaningful title ' . str_repeat('t', 60);
        $long  = new ReleaseAnnouncement(1, str_repeat('a', 90), 'v1.0.0', $title, 'https://github.com/ArtisanPack-UI/' . str_repeat('a', 90) . '/releases/tag/v1.0.0');

        expect(grapheme_strlen($long->text()))->toBeLessThanOrEqual(ReleaseAnnouncement::POST_LIMIT)
            ->and($long->text())->not->toContain('A meaningful title')
            ->and(seoAnnouncement('A meaningful title')->text())->toContain('A meaningful title');
    });

    it('shortens a very long repository name to keep the post within the limit', function (): void {
        $repository = str_repeat('r', 100);
        $tag        = 'v1.0.0-' . str_repeat('b', 40);
        $url        = 'https://github.com/ArtisanPack-UI/' . $repository . '/releases/tag/' . $tag;
        $text       = (new ReleaseAnnouncement(1, $repository, $tag, 'Title', $url))->text();

        expect(grapheme_strlen($text))->toBeLessThanOrEqual(ReleaseAnnouncement::POST_LIMIT)
            ->and($text)->toEndWith("\n\n" . $url)
            ->and($text)->toContain('…');
    });

    it('derives a stable, valid Bluesky record key from the release', function (): void {
        $announcement = new ReleaseAnnouncement(4242, 'seo', 'v1.9.0', '', 'https://github.com/ArtisanPack-UI/seo/releases/tag/v1.9.0', 1_793_000_000);
        $later        = new ReleaseAnnouncement(4243, 'seo', 'v1.9.1', '', 'https://github.com/ArtisanPack-UI/seo/releases/tag/v1.9.1', 1_793_000_100);

        expect($announcement->blueskyRecordKey())->toMatch('/^[234567abcdefghij][234567abcdefghijklmnopqrstuvwxyz]{12}$/')
            ->toBe($announcement->blueskyRecordKey())
            ->not->toBe($later->blueskyRecordKey())
            ->and(strcmp($announcement->blueskyRecordKey(), $later->blueskyRecordKey()))->toBeLessThan(0);
    });

    it('cuts a long release title short', function (): void {
        $summary = seoAnnouncement(str_repeat('é', 150))->summary();

        expect(mb_strlen($summary))->toBe(ReleaseAnnouncement::TITLE_LIMIT)
            ->and($summary)->toEndWith('…');
    });
});

describe('posting', function (): void {
    it('posts to Bluesky with a link facet over the URL and a link card', function (): void {
        configureAnnouncements();
        fakeNetworks();

        $uri = app(BlueskyClient::class)->post(seoAnnouncement('Public URL support'));

        expect($uri)->toBe('at://did:plc:abc/app.bsky.feed.post/1');

        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), 'createSession')
            && 'artisanpackui.bsky.social' === $request['identifier']
            && 'abcd-efgh-ijkl-mnop' === $request['password']);

        Http::assertSent(function (HttpRequest $request): bool {
            if (! str_ends_with($request->url(), 'createRecord')) {
                return false;
            }

            $record = $request['record'];
            $facet  = $record['facets'][0];
            $linked = substr($record['text'], $facet['index']['byteStart'], $facet['index']['byteEnd'] - $facet['index']['byteStart']);

            return $request->hasHeader('Authorization', 'Bearer jwt')
                && seoAnnouncement()->blueskyRecordKey() === $request['rkey']
                && 'did:plc:abcdefghijklmnopqrstuvwx' === $request['repo']
                && 'app.bsky.feed.post' === $request['collection']
                && 'https://github.com/ArtisanPack-UI/seo/releases/tag/v1.9.0' === $linked
                && $facet['features'][0]['uri'] === $linked
                && 'app.bsky.embed.external' === $record['embed']['$type']
                && 'seo v1.9.0' === $record['embed']['external']['title']
                && 'Public URL support' === $record['embed']['external']['description'];
        });
    });

    it('does not post to Bluesky again when an earlier attempt already did', function (): void {
        configureAnnouncements();
        Http::fake([
            'bsky.social/xrpc/com.atproto.server.createSession' => Http::response(['accessJwt' => 'jwt', 'did' => 'did:plc:abcdefghijklmnopqrstuvwx', 'handle' => 'artisanpackui.bsky.social']),
            'bsky.social/xrpc/com.atproto.repo.getRecord*'      => Http::response(['uri' => 'at://did:plc:abc/app.bsky.feed.post/earlier', 'value' => []]),
        ]);

        expect(app(BlueskyClient::class)->post(seoAnnouncement()))->toBe('at://did:plc:abc/app.bsky.feed.post/earlier');

        Http::assertNotSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), 'createRecord'));
    });

    it('retries rather than posts when Bluesky cannot say whether the post exists', function (): void {
        configureAnnouncements();
        Http::fake([
            'bsky.social/xrpc/com.atproto.server.createSession' => Http::response(['accessJwt' => 'jwt', 'did' => 'did:plc:abcdefghijklmnopqrstuvwx', 'handle' => 'artisanpackui.bsky.social']),
            'bsky.social/xrpc/com.atproto.repo.getRecord*'      => Http::response(['error' => 'InternalServerError'], 500),
        ]);

        expect(fn () => app()->call([new PostReleaseAnnouncement(SocialNetwork::Bluesky, seoAnnouncement()), 'handle']))
            ->toThrow(ArtisanPackUI\Site\Exceptions\SocialPostException::class, 'HTTP 500');

        Http::assertNotSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), 'createRecord'));
    });

    it('posts a public Mastodon status with an idempotency key', function (): void {
        configureAnnouncements();
        fakeNetworks();

        $url = app(MastodonClient::class)->post(seoAnnouncement());

        expect($url)->toBe('https://mastodon.example/@artisanpackui/1');

        Http::assertSent(fn (HttpRequest $request): bool => 'https://mastodon.example/api/v1/statuses' === $request->url()
            && $request->hasHeader('Authorization', 'Bearer mastodon-token')
            && $request->hasHeader('Idempotency-Key', 'artisanpack-ui-release-4242')
            && 'public' === $request['visibility']
            && seoAnnouncement()->text() === $request['status']);
    });

    it('retries when the Mastodon host does not resolve', function (): void {
        configureAnnouncements(['mastodon_instance_url' => 'https://nonexistent.example']);
        Http::fake();

        $job = (new PostReleaseAnnouncement(SocialNetwork::Mastodon, seoAnnouncement()))->withFakeQueueInteractions();

        expect(fn () => app()->call([$job, 'handle']))->toThrow(ArtisanPackUI\Site\Exceptions\SocialPostException::class, 'doesn\'t resolve');

        $job->assertNotFailed();
        Http::assertNothingSent();
    });

    it('refuses to send the Mastodon token to a private address', function (): void {
        configureAnnouncements(['mastodon_instance_url' => 'https://localhost']);
        Http::fake();

        expect(fn () => app(MastodonClient::class)->post(seoAnnouncement()))
            ->toThrow(ArtisanPackUI\Site\Exceptions\SocialPostException::class, 'private or reserved');

        Http::assertNothingSent();
    });

    it('posts through the job and remembers the release', function (): void {
        configureAnnouncements();
        fakeNetworks();

        $job = new PostReleaseAnnouncement(SocialNetwork::Mastodon, seoAnnouncement());
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        Http::assertSentCount(1);
        expect(Cache::has(PostReleaseAnnouncement::postedKey(SocialNetwork::Mastodon, 4242)))->toBeTrue()
            ->and(Cache::has(PostReleaseAnnouncement::postedKey(SocialNetwork::Bluesky, 4242)))->toBeFalse();
    });

    it('fails the job at once when the credentials are rejected', function (): void {
        configureAnnouncements();
        Http::fake(['bsky.social/*' => Http::response(['error' => 'AuthenticationRequired', 'message' => 'Invalid identifier or password'], 401)]);

        $job = (new PostReleaseAnnouncement(SocialNetwork::Bluesky, seoAnnouncement()))->withFakeQueueInteractions();

        app()->call([$job, 'handle']);

        $job->assertFailedWith(ArtisanPackUI\Site\Exceptions\SocialPostException::class);
        Http::assertSentCount(1);
        expect(Cache::has(PostReleaseAnnouncement::postedKey(SocialNetwork::Bluesky, 4242)))->toBeFalse();
    });

    it('waits for the rate limit to reset instead of failing', function (array $headers, int $delay): void {
        configureAnnouncements();
        Http::fake(['mastodon.example/*' => Http::response(['error' => 'Too many requests'], 429, $headers)]);

        $job = (new PostReleaseAnnouncement(SocialNetwork::Mastodon, seoAnnouncement()))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertReleased($delay);
        $job->assertNotFailed();
    })->with([
        'Retry-After'          => [['Retry-After' => '120'], 120],
        'Bluesky-style reset'  => [fn () => ['RateLimit-Reset' => (string) (time() + 600)], 600],
        'soon, waits a minute' => [['Retry-After' => '5'], 60],
        'far, waits an hour'   => [['Retry-After' => '86400'], 3600],
        'no reset given'       => [[], 300],
    ]);

    it('rethrows a temporary failure so the job retries', function (): void {
        configureAnnouncements();
        Http::fake(['mastodon.example/*' => Http::response(['error' => 'Service unavailable'], 503)]);

        $job = new PostReleaseAnnouncement(SocialNetwork::Mastodon, seoAnnouncement());

        expect(fn () => app()->call([$job, 'handle']))
            ->toThrow(ArtisanPackUI\Site\Exceptions\SocialPostException::class, 'HTTP 503');
    });

    it('skips a network removed from settings after the job queued', function (): void {
        configureAnnouncements(['bluesky_app_password' => null]);
        Http::fake();

        app()->call([new PostReleaseAnnouncement(SocialNetwork::Bluesky, seoAnnouncement()), 'handle']);

        Http::assertNothingSent();
    });

    it('never runs two copies of the same announcement at once', function (): void {
        $job = new PostReleaseAnnouncement(SocialNetwork::Bluesky, seoAnnouncement());

        expect($job->uniqueId())->toBe('bluesky:4242')
            ->and($job->middleware()[0])->toBeInstanceOf(Illuminate\Queue\Middleware\WithoutOverlapping::class)
            ->and($job->middleware()[0]->key)->toBe('artisanpack-ui:release-announcement:bluesky:4242');
    });
});
