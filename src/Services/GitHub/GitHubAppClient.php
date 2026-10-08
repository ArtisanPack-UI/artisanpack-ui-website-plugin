<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\GitHub;

use ArtisanPackUI\Site\Exceptions\GitHubException;
use ArtisanPackUI\Site\Exceptions\GitHubRateLimitException;
use ArtisanPackUI\Site\Exceptions\IntegrationNotConfiguredException;
use ArtisanPackUI\Site\Models\IntegrationSettings;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Talks to GitHub as the GitHub App installed on the ArtisanPack-UI org.
 *
 * Auth is the standard two-step App flow: an RS256 JWT signed with the App's
 * private key authenticates as the App itself (only {@see self::app()} and
 * the token exchange use it), and is exchanged for an installation access
 * token that every {@see self::rest()} and {@see self::graphql()} call sends.
 * The installation token is cached, encrypted with the app key, until a
 * minute before GitHub says it expires, so a page that makes several calls
 * exchanges once and the cache store never holds the org-wide token in
 * plaintext.
 *
 * Rate limits:
 *   - Every response's `X-RateLimit-Remaining` is exposed on the
 *     {@see GitHubResponse} and through {@see self::rateLimitRemaining()}.
 *   - A spent primary quota or a secondary (abuse) limit throws
 *     {@see GitHubRateLimitException} carrying the wait in seconds. The
 *     client does not sleep and retry: it runs inside admin requests, so
 *     the caller decides whether to report the wait or release a job.
 *
 * A 401 on a cached installation token (revoked, or the App reinstalled)
 * drops the token and retries once with a fresh one.
 *
 * @since 1.0.0
 */
class GitHubAppClient
{
    public const API_URL = 'https://api.github.com';

    public const API_VERSION = '2022-11-28';

    /**
     * Seconds before GitHub's stated expiry that a cached token is dropped,
     * so a token is never sent in the moment it lapses.
     */
    private const TOKEN_EXPIRY_MARGIN = 60;

    /**
     * Wait used for a secondary rate limit that sends no `Retry-After`,
     * per GitHub's guidance to wait at least a minute.
     */
    private const DEFAULT_SECONDARY_WAIT = 60;

    private ?int $rateLimitRemaining = null;

    public function __construct(
        private readonly IntegrationSettings $settings,
        private readonly Cache $cache,
    ) {}

    /**
     * `X-RateLimit-Remaining` from the most recent response, or null before
     * the first call.
     */
    public function rateLimitRemaining(): ?int
    {
        return $this->rateLimitRemaining;
    }

    /**
     * The authenticated App (`GET /app`), fetched with the App JWT. Used by
     * the Settings page's connection check to prove the App ID and private
     * key belong together.
     *
     * @return array<string, mixed>
     */
    public function app(): array
    {
        $response = $this->send(fn (): PendingRequest => $this->request($this->appJwt()), 'GET', '/app');

        return is_array($response->data) ? $response->data : [];
    }

    /**
     * Call the REST API with the installation token.
     *
     * @param  string                $path     Path below the API root, e.g. `/repos/{owner}/{repo}/issues`.
     * @param  array<string, mixed>  $payload  Query string for GET, JSON body otherwise.
     */
    public function rest(string $method, string $path, array $payload = []): GitHubResponse
    {
        return $this->sendAsInstallation(strtoupper($method), $path, $payload);
    }

    /**
     * Run a GraphQL query or mutation with the installation token and return
     * its `data`. A response carrying `errors` throws, because a partial
     * result would be mistaken for a complete one.
     *
     * @param  array<string, mixed>  $variables
     */
    public function graphql(string $query, array $variables = []): GitHubResponse
    {
        $response = $this->sendAsInstallation('POST', '/graphql', [
            'query'     => $query,
            'variables' => (object) $variables,
        ]);

        $body = is_array($response->data) ? $response->data : [];

        if (! empty($body['errors']) && is_array($body['errors'])) {
            throw $this->graphqlException($body['errors'], $response->status, $response->rateLimitRemaining, $response->rateLimitReset);
        }

        return new GitHubResponse(
            $response->status,
            $body['data'] ?? null,
            $response->rateLimitRemaining,
            $response->rateLimitReset,
        );
    }

    /**
     * An installation access token, from the cache when one is still valid.
     */
    public function installationToken(): string
    {
        $cached = $this->cachedInstallationToken();

        if (null !== $cached) {
            return $cached;
        }

        $response = $this->send(
            fn (): PendingRequest => $this->request($this->appJwt()),
            'POST',
            '/app/installations/' . rawurlencode($this->installationId()) . '/access_tokens',
        );

        $token = is_array($response->data) ? (string) ($response->data['token'] ?? '') : '';

        if ('' === $token) {
            throw new GitHubException(__('GitHub did not return an installation token.'), $response->status);
        }

        $expiresAt = is_array($response->data) && isset($response->data['expires_at'])
            ? Carbon::parse((string) $response->data['expires_at'])
            : Carbon::now()->addHour();

        $ttl = (int) Carbon::now()->diffInSeconds($expiresAt, false) - self::TOKEN_EXPIRY_MARGIN;

        if ($ttl > 0) {
            $this->cache->put($this->tokenCacheKey(), Crypt::encryptString($token), $ttl);
        }

        return $token;
    }

    /**
     * The cached installation token, decrypted, or null. A value that no
     * longer decrypts (the app key changed) is dropped and treated as a
     * miss.
     *
     * @since 1.0.0
     */
    private function cachedInstallationToken(): ?string
    {
        $cached = $this->cache->get($this->tokenCacheKey());

        if (! is_string($cached) || '' === $cached) {
            return null;
        }

        try {
            $token = Crypt::decryptString($cached);
        } catch (DecryptException) {
            $this->forgetInstallationToken();

            return null;
        }

        return '' === $token ? null : $token;
    }

    /**
     * Drop the cached installation token, e.g. after the App's credentials
     * change.
     */
    public function forgetInstallationToken(): void
    {
        $this->cache->forget($this->tokenCacheKey());
    }

    /**
     * A short-lived RS256 JWT authenticating as the App. `iat` is backdated
     * a minute to absorb clock drift, and `exp` stays under GitHub's ten
     * minute cap.
     */
    public function appJwt(): string
    {
        $this->assertConfigured();

        $privateKey = openssl_pkey_get_private((string) $this->settings->github_private_key);

        if (false === $privateKey) {
            throw new GitHubException(__('The GitHub App private key is not a valid PEM private key.'));
        }

        $now      = Carbon::now()->getTimestamp();
        $segments = [
            self::base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            self::base64Url((string) json_encode([
                'iat' => $now - 60,
                'exp' => $now + 540,
                'iss' => trim((string) $this->settings->github_app_id),
            ])),
        ];

        $signature = '';

        if (! openssl_sign(implode('.', $segments), $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new GitHubException(__('The GitHub App JWT could not be signed with the private key.'));
        }

        $segments[] = self::base64Url($signature);

        return implode('.', $segments);
    }

    /**
     * Send with the installation token, retrying once with a fresh token
     * when GitHub rejects a cached one. A freshly minted token that is
     * rejected is a real credential problem, so it isn't retried.
     *
     * @param  array<string, mixed>  $payload
     */
    private function sendAsInstallation(string $method, string $path, array $payload): GitHubResponse
    {
        $tokenWasCached = $this->cache->has($this->tokenCacheKey());

        try {
            return $this->send(fn (): PendingRequest => $this->request($this->installationToken()), $method, $path, $payload);
        } catch (GitHubException $exception) {
            if (401 !== $exception->status || ! $tokenWasCached) {
                throw $exception;
            }

            $this->forgetInstallationToken();

            return $this->send(fn (): PendingRequest => $this->request($this->installationToken()), $method, $path, $payload);
        }
    }

    /**
     * @param  callable(): PendingRequest  $request
     * @param  array<string, mixed>        $payload
     */
    private function send(callable $request, string $method, string $path, array $payload = []): GitHubResponse
    {
        try {
            $response = $request()->send($method, $path, 'GET' === $method
                ? ['query' => $payload]
                : ['json' => $payload]);
        } catch (ConnectionException $exception) {
            throw GitHubException::unreachable($exception);
        }

        $remaining = self::intHeader($response, 'X-RateLimit-Remaining');
        $reset     = self::intHeader($response, 'X-RateLimit-Reset');

        if (null !== $remaining) {
            $this->rateLimitRemaining = $remaining;
        }

        if ($response->failed()) {
            throw $this->rateLimitException($response, $remaining, $reset) ?? GitHubException::fromResponse($response);
        }

        return new GitHubResponse($response->status(), $response->json(), $remaining, $reset);
    }

    /**
     * A rate-limit exception for a 403/429 that is one, or null.
     */
    private function rateLimitException(Response $response, ?int $remaining, ?int $reset): ?GitHubRateLimitException
    {
        $status = $response->status();

        if (403 !== $status && 429 !== $status) {
            return null;
        }

        $retryAfter = self::intHeader($response, 'Retry-After');
        $message    = strtolower((string) $response->json('message', ''));

        if (null !== $retryAfter || str_contains($message, 'secondary rate limit')) {
            return GitHubRateLimitException::secondary($status, $retryAfter ?? self::DEFAULT_SECONDARY_WAIT);
        }

        if (0 === $remaining) {
            $wait = null === $reset ? self::DEFAULT_SECONDARY_WAIT : max(1, $reset - Carbon::now()->getTimestamp());

            return GitHubRateLimitException::primary($status, $wait);
        }

        return null;
    }

    /**
     * A `RATE_LIMITED` error with the hourly quota spent is the primary
     * limit, which lasts until `X-RateLimit-Reset`; any other is treated as
     * a secondary limit.
     *
     * @param  array<int, mixed>  $errors
     */
    private function graphqlException(array $errors, int $status, ?int $remaining, ?int $reset): GitHubException
    {
        $first = is_array($errors[0] ?? null) ? $errors[0] : [];

        if ('RATE_LIMITED' === ($first['type'] ?? null)) {
            if (0 === $remaining && null !== $reset) {
                return GitHubRateLimitException::primary($status, max(1, $reset - Carbon::now()->getTimestamp()));
            }

            return GitHubRateLimitException::secondary($status, self::DEFAULT_SECONDARY_WAIT);
        }

        $messages = array_filter(array_map(
            static fn (mixed $error): string => is_array($error) ? (string) ($error['message'] ?? '') : '',
            $errors,
        ));

        return new GitHubException(
            __('GitHub GraphQL error: :message', ['message' => implode('; ', $messages) ?: __('unknown error')]),
            $status,
        );
    }

    private function request(string $bearer): PendingRequest
    {
        return Http::baseUrl(self::API_URL)
            ->withToken($bearer)
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => self::API_VERSION])
            ->connectTimeout(5)
            ->timeout(20);
    }

    private function installationId(): string
    {
        $this->assertConfigured();

        return trim((string) $this->settings->github_installation_id);
    }

    private function assertConfigured(): void
    {
        if (! $this->settings->hasGitHubApp()) {
            throw IntegrationNotConfiguredException::gitHubApp();
        }
    }

    /**
     * Keyed on the App and installation, so changing either in Settings
     * never reuses a token minted for the old pair.
     */
    private function tokenCacheKey(): string
    {
        return 'artisanpack-ui:github:installation-token:'
            . sha1(trim((string) $this->settings->github_app_id) . '|' . trim((string) $this->settings->github_installation_id));
    }

    private static function intHeader(Response $response, string $name): ?int
    {
        $value = $response->header($name);

        return is_numeric($value) ? (int) $value : null;
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
