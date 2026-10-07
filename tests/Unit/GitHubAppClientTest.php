<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Exceptions\GitHubException;
use ArtisanPackUI\Site\Exceptions\GitHubRateLimitException;
use ArtisanPackUI\Site\Exceptions\IntegrationNotConfiguredException;
use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Services\GitHub\GitHubAppClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function gitHubClient(array $overrides = []): GitHubAppClient
{
    $settings = new IntegrationSettings([
        'github_app_id'          => '123456',
        'github_installation_id' => '987654',
        'github_private_key'     => testPrivateKey(),
        ...$overrides,
    ]);

    return new GitHubAppClient($settings, Cache::store());
}

function fakeTokenExchange(): array
{
    return [
        'api.github.com/app/installations/987654/access_tokens' => Http::response([
            'token'      => 'ghs_installation',
            'expires_at' => now()->addHour()->toIso8601String(),
        ], 201),
    ];
}

it('mints an RS256 JWT the App private key verifies', function (): void {
    [$header, $payload, $signature] = explode('.', gitHubClient()->appJwt());

    $decode = fn (string $part): string => base64_decode(strtr($part, '-_', '+/'));
    $public = openssl_pkey_get_details(openssl_pkey_get_private(testPrivateKey()))['key'];

    expect(json_decode($decode($header), true))->toBe(['alg' => 'RS256', 'typ' => 'JWT'])
        ->and(json_decode($decode($payload), true))->toMatchArray(['iss' => '123456'])
        ->and(openssl_verify("{$header}.{$payload}", $decode($signature), $public, OPENSSL_ALGO_SHA256))->toBe(1);

    $claims = json_decode($decode($payload), true);
    expect($claims['exp'] - $claims['iat'])->toBeLessThanOrEqual(600);
});

it('exchanges the JWT for an installation token and caches it', function (): void {
    Http::fake([
        ...fakeTokenExchange(),
        'api.github.com/repos/*' => Http::response(['id' => 1]),
    ]);

    $client = gitHubClient();
    $client->rest('GET', '/repos/ArtisanPack-UI/accessibility');
    $client->rest('GET', '/repos/ArtisanPack-UI/accessibility');

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens')
        && str_starts_with($request->header('Authorization')[0], 'Bearer ey'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/repos/')
        && 'Bearer ghs_installation' === $request->header('Authorization')[0]
        && '2022-11-28' === $request->header('X-GitHub-Api-Version')[0]);
});

it('does not cache a token that is about to expire', function (): void {
    Http::fake([
        'api.github.com/app/installations/987654/access_tokens' => Http::response([
            'token' => 'ghs_short', 'expires_at' => now()->addSeconds(30)->toIso8601String(),
        ], 201),
    ]);

    $client = gitHubClient();
    $client->installationToken();
    $client->installationToken();

    Http::assertSentCount(2);
});

it('retries once with a fresh token when the cached one is rejected', function (): void {
    Cache::put('artisanpack-ui:github:installation-token:' . sha1('123456|987654'), 'ghs_revoked', 600);

    Http::fake([
        ...fakeTokenExchange(),
        'api.github.com/repos/*' => function (Request $request) {
            return 'Bearer ghs_revoked' === $request->header('Authorization')[0]
                ? Http::response(['message' => 'Bad credentials'], 401)
                : Http::response(['id' => 1]);
        },
    ]);

    expect(gitHubClient()->rest('GET', '/repos/a/b')->data)->toBe(['id' => 1]);
});

it('does not retry when a freshly minted token is rejected', function (): void {
    Http::fake([
        ...fakeTokenExchange(),
        'api.github.com/repos/*' => Http::response(['message' => 'Bad credentials'], 401),
    ]);

    expect(fn () => gitHubClient()->rest('GET', '/repos/a/b'))->toThrow(GitHubException::class);

    Http::assertSentCount(2);
});

it('surfaces the remaining rate limit', function (): void {
    Http::fake([
        ...fakeTokenExchange(),
        'api.github.com/repos/*' => Http::response(['id' => 1], 200, ['X-RateLimit-Remaining' => '42', 'X-RateLimit-Reset' => '1900000000']),
    ]);

    $client   = gitHubClient();
    $response = $client->rest('GET', '/repos/a/b');

    expect($response->rateLimitRemaining)->toBe(42)
        ->and($response->rateLimitReset)->toBe(1900000000)
        ->and($client->rateLimitRemaining())->toBe(42);
});

it('throws a secondary rate limit with the Retry-After wait', function (): void {
    Http::fake([
        ...fakeTokenExchange(),
        'api.github.com/repos/*' => Http::response(['message' => 'You have exceeded a secondary rate limit.'], 403, ['Retry-After' => '30']),
    ]);

    try {
        gitHubClient()->rest('GET', '/repos/a/b');
        $this->fail('Expected a rate-limit exception.');
    } catch (GitHubRateLimitException $exception) {
        expect($exception->secondary)->toBeTrue()
            ->and($exception->retryAfter)->toBe(30)
            ->and($exception->status)->toBe(403);
    }
});

it('throws a primary rate limit when the quota is spent', function (): void {
    $this->travelTo(now());

    Http::fake([
        ...fakeTokenExchange(),
        'api.github.com/repos/*' => Http::response(['message' => 'API rate limit exceeded'], 403, [
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset'     => (string) (now()->getTimestamp() + 120),
        ]),
    ]);

    try {
        gitHubClient()->rest('GET', '/repos/a/b');
        $this->fail('Expected a rate-limit exception.');
    } catch (GitHubRateLimitException $exception) {
        expect($exception->secondary)->toBeFalse()
            ->and($exception->retryAfter)->toBe(120);
    }
});

it('treats a plain 403 as a permission error', function (): void {
    Http::fake([
        ...fakeTokenExchange(),
        'api.github.com/repos/*' => Http::response(['message' => 'Resource not accessible by integration'], 403),
    ]);

    expect(fn () => gitHubClient()->rest('GET', '/repos/a/b'))
        ->toThrow(fn (GitHubException $exception) => expect($exception)->not->toBeInstanceOf(GitHubRateLimitException::class)
            ->and($exception->getMessage())->toContain('Resource not accessible by integration'));
});

it('returns GraphQL data and throws on GraphQL errors', function (): void {
    Http::fake([
        ...fakeTokenExchange(),
        'api.github.com/graphql' => Http::sequence()
            ->push(['data' => ['viewer' => ['login' => 'bot']]])
            ->push(['data' => null, 'errors' => [['message' => 'Field is missing']]]),
    ]);

    $client = gitHubClient();

    expect($client->graphql('{ viewer { login } }')->data)->toBe(['viewer' => ['login' => 'bot']])
        ->and(fn () => $client->graphql('{ broken }'))->toThrow(GitHubException::class, 'Field is missing');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/graphql')
        && '{ viewer { login } }' === $request['query']);
});

it('maps a GraphQL RATE_LIMITED error to a rate-limit exception', function (): void {
    Http::fake([
        ...fakeTokenExchange(),
        'api.github.com/graphql' => Http::response(['errors' => [['type' => 'RATE_LIMITED', 'message' => 'API rate limit exceeded']]]),
    ]);

    expect(fn () => gitHubClient()->graphql('{ viewer { login } }'))->toThrow(GitHubRateLimitException::class);
});

it('refuses to run unconfigured or with a bad key', function (): void {
    Http::fake();

    expect(fn () => gitHubClient(['github_private_key' => null])->installationToken())
        ->toThrow(IntegrationNotConfiguredException::class)
        ->and(fn () => gitHubClient(['github_private_key' => 'nope'])->appJwt())
        ->toThrow(GitHubException::class, 'not a valid PEM private key');

    Http::assertNothingSent();
});
