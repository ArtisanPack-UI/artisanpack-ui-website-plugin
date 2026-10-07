<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Support\OutboundUrlPolicy;
use ArtisanPackUI\Site\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    actingAsUserWith([Permissions::SYNC]);
});

/**
 * @return array<string, mixed>
 */
function validSettings(array $overrides = []): array
{
    return [
        'docs_base_url'          => 'https://docs.example.invalid/',
        'docs_api_token'         => 'docs-token',
        'github_app_id'          => '123456',
        'github_installation_id' => '987654',
        'github_private_key'     => testPrivateKey(),
        'github_organization'    => 'ArtisanPack-UI',
        'github_project_number'  => 3,
        ...$overrides,
    ];
}

it('saves the settings and encrypts the credentials at rest', function (): void {
    $this->putJson('/admin/artisanpack-ui/settings', validSettings())
        ->assertOk()
        ->assertJsonPath('settings.docsBaseUrl', 'https://docs.example.invalid')
        ->assertJsonPath('settings.hasDocsApiToken', true)
        ->assertJsonPath('settings.hasGitHubPrivateKey', true)
        ->assertJsonPath('settings.githubProjectNumber', 3);

    $settings = IntegrationSettings::current();
    expect($settings->docs_api_token)->toBe('docs-token')
        ->and($settings->github_private_key)->toBe(trim(testPrivateKey()));

    $raw = DB::table('artisanpack_ui_settings')->first();
    foreach (['docs_api_token', 'github_app_id', 'github_private_key', 'github_installation_id'] as $column) {
        expect($raw->{$column})->not->toBeNull()->not->toContain($settings->{$column});
    }
});

it('never sends the stored secrets to the browser', function (): void {
    IntegrationSettings::create(validSettings());

    $this->get('/admin/artisanpack-ui/settings')
        ->assertOk()
        ->assertDontSee('docs-token')
        ->assertDontSee('PRIVATE KEY')
        ->assertInertia(fn (Assert $page) => $page
            ->component('plugins/artisanpack-ui/settings', false)
            ->where('settings.hasDocsApiToken', true)
            ->where('settings.githubAppId', '123456')
            ->missing('settings.docsApiToken')
            ->has('endpoints.testGitHub'));
});

it('keeps a stored secret when the field is left blank', function (): void {
    IntegrationSettings::create(validSettings());

    $this->putJson('/admin/artisanpack-ui/settings', validSettings(['docs_api_token' => '', 'github_private_key' => null]))
        ->assertOk();

    expect(IntegrationSettings::current()->docs_api_token)->toBe('docs-token')
        ->and(IntegrationSettings::current()->github_private_key)->toBe(testPrivateKey());
});

it('clears a stored secret on request', function (): void {
    IntegrationSettings::create(validSettings());

    $this->putJson('/admin/artisanpack-ui/settings', validSettings(['docs_api_token' => '', 'remove_docs_api_token' => true]))
        ->assertOk()
        ->assertJsonPath('settings.hasDocsApiToken', false);
});

it('rejects invalid settings', function (array $overrides, string $field): void {
    $this->putJson('/admin/artisanpack-ui/settings', validSettings($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'bad url'           => [['docs_base_url' => 'not a url'], 'docs_base_url'],
    'bad private key'   => [['github_private_key' => 'not a key'], 'github_private_key'],
    'bad installation'  => [['github_installation_id' => 'abc'], 'github_installation_id'],
    'bad project'       => [['github_project_number' => 0], 'github_project_number'],
    'bad organization'  => [['github_organization' => 'no spaces allowed'], 'github_organization'],
]);

it('reports an unconfigured docs site without calling it', function (): void {
    Http::fake();

    $this->postJson('/admin/artisanpack-ui/settings/test/docs')
        ->assertOk()
        ->assertJson(['ok' => false])
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'isn\'t configured'));

    Http::assertNothingSent();
});

it('passes the docs site check with a working token', function (): void {
    IntegrationSettings::create(validSettings());
    Http::fake(['docs.example.invalid/api/v1/packages*' => Http::response(['data' => [['id' => 1, 'name' => 'A', 'slug' => 'a']]])]);

    $this->postJson('/admin/artisanpack-ui/settings/test/docs')
        ->assertOk()
        ->assertJson(['ok' => true, 'details' => ['1 package found.']]);
});

it('fails the docs site check with an admin-friendly message', function (): void {
    IntegrationSettings::create(validSettings());
    Http::fake(['docs.example.invalid/*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    $this->postJson('/admin/artisanpack-ui/settings/test/docs')
        ->assertOk()
        ->assertJson(['ok' => false])
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'rejected the API token'));
});

it('passes the GitHub check through the app, token and project', function (): void {
    IntegrationSettings::create(validSettings());
    Http::fake([
        'api.github.com/app/installations/*' => Http::response(['token' => 'ghs_1', 'expires_at' => now()->addHour()->toIso8601String()], 201),
        'api.github.com/app'                 => Http::response(['name' => 'ArtisanPack Bot']),
        'api.github.com/graphql'             => Http::response(['data' => ['organization' => ['projectV2' => ['title' => 'Roadmap']]]], 200, ['X-RateLimit-Remaining' => '4999']),
    ]);

    $this->postJson('/admin/artisanpack-ui/settings/test/github')
        ->assertOk()
        ->assertJson(['ok' => true])
        ->assertJsonPath('details', [
            'Authenticated as the "ArtisanPack Bot" GitHub App.',
            'Installation token issued.',
            'Found project #3, "Roadmap".',
            '4999 API requests left this hour.',
        ]);
});

it('fails the GitHub check when the project is missing', function (): void {
    IntegrationSettings::create(validSettings());
    Http::fake([
        'api.github.com/app/installations/*' => Http::response(['token' => 'ghs_1', 'expires_at' => now()->addHour()->toIso8601String()], 201),
        'api.github.com/app'                 => Http::response(['name' => 'ArtisanPack Bot']),
        'api.github.com/graphql'             => Http::response(['data' => ['organization' => ['projectV2' => null]]]),
    ]);

    $this->postJson('/admin/artisanpack-ui/settings/test/github')
        ->assertOk()
        ->assertJson(['ok' => false])
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Project #3 wasn\'t found'));
});

it('refuses a docs site URL the server must not call', function (string $url): void {
    $this->putJson('/admin/artisanpack-ui/settings', validSettings(['docs_base_url' => $url]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('docs_base_url');
})->with([
    'plain http'    => ['http://docs.example.invalid'],
    'loopback'      => ['https://127.0.0.1'],
    'private range' => ['https://10.0.0.5'],
    'metadata'      => ['https://169.254.169.254'],
    'localhost'     => ['https://localhost'],
]);

it('allows http and loopback docs URLs in local development', function (): void {
    expect(OutboundUrlPolicy::problem('http://127.0.0.1:8000', allowPrivate: true))->toBeNull()
        ->and(OutboundUrlPolicy::problem('ftp://docs.example.invalid', allowPrivate: true))->not->toBeNull();
});

it('limits how often the connection checks can run', function (): void {
    Http::fake();

    foreach (range(1, 10) as $attempt) {
        $this->postJson('/admin/artisanpack-ui/settings/test/docs')->assertOk();
    }

    $this->postJson('/admin/artisanpack-ui/settings/test/docs')->assertTooManyRequests();
});
