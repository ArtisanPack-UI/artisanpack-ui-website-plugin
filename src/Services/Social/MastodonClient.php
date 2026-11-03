<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Social;

use ArtisanPackUI\Site\Exceptions\IntegrationNotConfiguredException;
use ArtisanPackUI\Site\Exceptions\SocialPostException;
use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Support\OutboundUrlPolicy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Posts to Mastodon as the account whose access token is saved on the
 * Settings page. The token needs the `write:statuses` scope, plus
 * `read:accounts` for the Settings page's connection check.
 *
 * The instance URL is typed in by an admin and the token is sent to it, so
 * every request re-checks it against {@see OutboundUrlPolicy}, pins the
 * connection to the vetted address and refuses redirects, as the docs site
 * client does.
 *
 * Each status carries an `Idempotency-Key` built from the release id, so a
 * retried post that did reach Mastodon isn't published twice.
 *
 * @since 1.4.0
 */
class MastodonClient
{
    private const NETWORK = 'Mastodon';

    public function __construct(private readonly IntegrationSettings $settings) {}

    /**
     * Read the token's account, proving the instance URL and token. Returns
     * the account's `acct` name.
     */
    public function verify(): string
    {
        $response = $this->send('GET', '/api/v1/accounts/verify_credentials', []);
        $acct     = $response->json('acct');

        if (! is_string($acct) || '' === $acct) {
            throw SocialPostException::unexpectedResponse(self::NETWORK, $response->status());
        }

        return $acct;
    }

    /**
     * Publish the announcement as a public status. Returns its URL.
     */
    public function post(ReleaseAnnouncement $announcement): string
    {
        $response = $this->send('POST', '/api/v1/statuses', [
            'status'     => $announcement->text(),
            'visibility' => 'public',
            'language'   => 'en',
        ], ['Idempotency-Key' => 'artisanpack-ui-release-' . $announcement->releaseId]);

        $url = $response->json('url') ?? $response->json('uri');

        if (! is_string($url) || '' === $url) {
            throw SocialPostException::unexpectedResponse(self::NETWORK, $response->status());
        }

        return $url;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function send(string $method, string $path, array $body, array $headers = []): Response
    {
        $request = $this->request()->withHeaders($headers);

        try {
            $response = 'GET' === $method ? $request->get($path) : $request->post($path, $body);
        } catch (ConnectionException $exception) {
            throw SocialPostException::unreachable(self::NETWORK, $exception);
        }

        if ($response->failed()) {
            throw SocialPostException::fromResponse(self::NETWORK, $response, __('access token'));
        }

        return $response;
    }

    private function request(): PendingRequest
    {
        if (! $this->settings->hasMastodon()) {
            throw IntegrationNotConfiguredException::mastodon();
        }

        $baseUrl = (string) $this->settings->mastodonInstanceUrl();

        ['problem' => $problem, 'ips' => $ips] = OutboundUrlPolicy::resolve($baseUrl, app()->isLocal());

        if (null !== $problem) {
            // Not permanent: "doesn't resolve" can be a passing DNS failure,
            // and a URL that stays refused just uses up the retries.
            throw new SocialPostException(__('The Mastodon instance URL can\'t be called: :problem', ['problem' => $problem]));
        }

        $request = Http::baseUrl($baseUrl)
            ->withToken((string) $this->settings->mastodon_access_token)
            ->acceptJson()
            ->asJson()
            ->withoutRedirecting()
            ->connectTimeout(5)
            ->timeout(20);

        $pin = OutboundUrlPolicy::curlResolvePin($baseUrl, $ips);

        return null === $pin ? $request : $request->withOptions(['curl' => [CURLOPT_RESOLVE => [$pin]]]);
    }
}
