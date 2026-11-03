<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Social;

use ArtisanPackUI\Site\Exceptions\IntegrationNotConfiguredException;
use ArtisanPackUI\Site\Exceptions\SocialPostException;
use ArtisanPackUI\Site\Models\IntegrationSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Posts to Bluesky as the account saved on the Settings page.
 *
 * Signs in with the handle and an app password (never the account
 * password) through `com.atproto.server.createSession`, then writes an
 * `app.bsky.feed.post` record. Bluesky doesn't link URLs in a post's text by
 * itself, so the post carries a link facet over the release URL and an
 * external embed that renders it as a card. Hashtags likewise only become
 * tags through `#tag` facets.
 *
 * Each post's record key comes from the release
 * ({@see ReleaseAnnouncement::blueskyRecordKey()}), and the post is looked
 * up by that key before it is created. `createRecord` has no idempotency
 * key, so this is what stops a retry (after a timeout that hid a post that
 * did go through) from posting twice.
 *
 * Always talks to {@see self::SERVICE_URL}, so the app password can't be
 * sent anywhere an admin typed in.
 *
 * @since 1.4.0
 */
class BlueskyClient
{
    /**
     * The Bluesky PDS entryway every bsky.social account signs in through.
     */
    public const SERVICE_URL = 'https://bsky.social';

    private const NETWORK = 'Bluesky';

    public function __construct(private readonly IntegrationSettings $settings) {}

    /**
     * Sign in, proving the handle and app password. Returns the handle
     * Bluesky signed in as.
     */
    public function verify(): string
    {
        return $this->session()['handle'];
    }

    /**
     * Post the announcement. Returns the new post's `at://` URI.
     */
    public function post(ReleaseAnnouncement $announcement): string
    {
        $session = $this->session();
        $rkey    = $announcement->blueskyRecordKey();
        $posted  = $this->existingPost($session, $rkey);

        if (null !== $posted) {
            return $posted;
        }

        $text = $announcement->text();
        $summary = $announcement->summary();

        $record = [
            '$type'     => 'app.bsky.feed.post',
            'text'      => $text,
            'createdAt' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'langs'     => ['en'],
            'facets'    => [
                [
                    'index' => [
                        'byteStart' => strlen($text) - strlen($announcement->url),
                        'byteEnd'   => strlen($text),
                    ],
                    'features' => [[
                        '$type' => 'app.bsky.richtext.facet#link',
                        'uri'   => $announcement->url,
                    ]],
                ],
                ...self::tagFacets($announcement->hashtagOffsets($text)),
            ],
            'embed' => [
                '$type'    => 'app.bsky.embed.external',
                'external' => [
                    'uri'         => $announcement->url,
                    'title'       => $announcement->repository . ' ' . $announcement->tag,
                    'description' => '' === $summary ? __('Release notes on GitHub') : $summary,
                ],
            ],
        ];

        $response = $this->send($this->request()->withToken($session['accessJwt']), 'com.atproto.repo.createRecord', [
            'repo'       => $session['did'],
            'collection' => 'app.bsky.feed.post',
            'rkey'       => $rkey,
            'record'     => $record,
        ]);

        $uri = $response->json('uri');

        if (! is_string($uri) || '' === $uri) {
            throw SocialPostException::unexpectedResponse(self::NETWORK, $response->status());
        }

        return $uri;
    }

    /**
     * A `#tag` facet per hashtag.
     *
     * @param  array<string, array{byteStart: int, byteEnd: int}>  $offsets
     *
     * @return list<array<string, mixed>>
     */
    private static function tagFacets(array $offsets): array
    {
        $facets = [];

        foreach ($offsets as $tag => $index) {
            $facets[] = [
                'index'    => $index,
                'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => $tag]],
            ];
        }

        return $facets;
    }

    /**
     * The URI of the post already stored under the record key, or null.
     *
     * @param  array{accessJwt: string, did: string, handle: string}  $session
     */
    private function existingPost(array $session, string $rkey): ?string
    {
        try {
            $response = $this->request()->withToken($session['accessJwt'])->get('com.atproto.repo.getRecord', [
                'repo'       => $session['did'],
                'collection' => 'app.bsky.feed.post',
                'rkey'       => $rkey,
            ]);
        } catch (ConnectionException $exception) {
            throw SocialPostException::unreachable(self::NETWORK, $exception);
        }

        $uri = $response->json('uri');

        if ($response->successful() && is_string($uri) && '' !== $uri) {
            return $uri;
        }

        // A missing record is a 400 `RecordNotFound`; anything else is a
        // failure worth retrying rather than a reason to post again.
        if (400 === $response->status() && 'RecordNotFound' === $response->json('error')) {
            return null;
        }

        throw SocialPostException::fromResponse(self::NETWORK, $response, __('handle or app password'));
    }

    /**
     * @return array{accessJwt: string, did: string, handle: string}
     */
    private function session(): array
    {
        if (! $this->settings->hasBluesky()) {
            throw IntegrationNotConfiguredException::bluesky();
        }

        $response = $this->send($this->request(), 'com.atproto.server.createSession', [
            'identifier' => (string) $this->settings->blueskyHandle(),
            'password'   => (string) $this->settings->bluesky_app_password,
        ]);

        $session = [
            'accessJwt' => $response->json('accessJwt'),
            'did'       => $response->json('did'),
            'handle'    => $response->json('handle'),
        ];

        foreach ($session as $value) {
            if (! is_string($value) || '' === $value) {
                throw SocialPostException::unexpectedResponse(self::NETWORK, $response->status());
            }
        }

        /** @var array{accessJwt: string, did: string, handle: string} $session */
        return $session;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function send(PendingRequest $request, string $method, array $body): Response
    {
        try {
            $response = $request->post($method, $body);
        } catch (ConnectionException $exception) {
            throw SocialPostException::unreachable(self::NETWORK, $exception);
        }

        if ($response->failed()) {
            throw SocialPostException::fromResponse(self::NETWORK, $response, __('handle or app password'));
        }

        return $response;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(self::SERVICE_URL . '/xrpc')
            ->acceptJson()
            ->asJson()
            ->withoutRedirecting()
            ->connectTimeout(5)
            ->timeout(20);
    }
}
