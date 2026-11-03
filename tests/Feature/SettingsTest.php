<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Casts\SafeEncrypted;
use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Support\OutboundUrlPolicy;
use ArtisanPackUI\Site\Support\Permissions;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    actingAsUserWith([Permissions::SETTINGS_MANAGE]);
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

it('treats credentials the app key can no longer decrypt as unset', function (): void {
    IntegrationSettings::create(validSettings());

    config()->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');

    $this->get('/admin/artisanpack-ui/settings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('settings.hasDocsApiToken', false)
            ->where('settings.hasGitHubPrivateKey', false)
            ->where('settings.docsBaseUrl', 'https://docs.example.invalid'));

    $this->postJson('/admin/artisanpack-ui/settings/test/docs')
        ->assertOk()
        ->assertJson(['ok' => false])
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'isn\'t configured'));
});

it('reads values the stock encrypted cast wrote', function (): void {
    $settings = IntegrationSettings::create(['docs_base_url' => 'https://docs.example.invalid']);

    // How Laravel's `encrypted` cast stores a value.
    DB::table('artisanpack_ui_settings')->where('id', $settings->id)->update([
        'docs_api_token' => app(Encrypter::class)->encrypt('legacy-token', false),
    ]);

    expect(IntegrationSettings::current()->docs_api_token)->toBe('legacy-token')
        ->and((new SafeEncrypted)->get($settings, 'docs_api_token', null, []))->toBeNull();
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

it('requires the API token again when the docs site URL moves to another host', function (): void {
    IntegrationSettings::create(validSettings());

    $this->putJson('/admin/artisanpack-ui/settings', validSettings(['docs_base_url' => 'https://attacker.example.invalid', 'docs_api_token' => '']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['docs_api_token' => 'Re-enter the API token when you change the docs site URL.']);

    $settings = IntegrationSettings::current();
    expect($settings->docsBaseUrl())->toBe('https://docs.example.invalid')
        ->and($settings->docs_api_token)->toBe('docs-token');
});

it('accepts a new docs site host with a new API token', function (): void {
    IntegrationSettings::create(validSettings());

    $this->putJson('/admin/artisanpack-ui/settings', validSettings(['docs_base_url' => 'https://docs2.example.invalid', 'docs_api_token' => 'new-token']))
        ->assertOk();

    expect(IntegrationSettings::current()->docs_api_token)->toBe('new-token');
});

it('keeps the API token when the docs site URL keeps its origin', function (string $url): void {
    IntegrationSettings::create(validSettings());

    $this->putJson('/admin/artisanpack-ui/settings', validSettings(['docs_base_url' => $url, 'docs_api_token' => '']))
        ->assertOk();

    expect(IntegrationSettings::current()->docs_api_token)->toBe('docs-token');
})->with([
    'same url'       => ['https://docs.example.invalid'],
    'trailing slash' => ['https://docs.example.invalid/'],
]);

it('requires the private key again when the App or installation ID changes', function (string $key): void {
    IntegrationSettings::create(validSettings());

    $this->putJson('/admin/artisanpack-ui/settings', validSettings([$key => '555', 'github_private_key' => '']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('github_private_key');
})->with(['github_app_id', 'github_installation_id']);

it('drops the cached installation token when the App credentials change', function (): void {
    IntegrationSettings::create(validSettings());
    $tokenKey = 'artisanpack-ui:github:installation-token:' . sha1('123456|987654');
    Cache::put($tokenKey, 'cached', 600);

    $this->putJson('/admin/artisanpack-ui/settings', validSettings(['docs_api_token' => '', 'github_private_key' => '']))->assertOk();
    expect(Cache::has($tokenKey))->toBeTrue();

    openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $newKey);

    $this->putJson('/admin/artisanpack-ui/settings', validSettings(['docs_api_token' => '', 'github_private_key' => $newKey]))->assertOk();
    expect(Cache::has($tokenKey))->toBeFalse();
});

it('drops the cached Status field when the project changes', function (): void {
    IntegrationSettings::create(validSettings());
    $statusKey = 'artisanpack-ui:board:status-field:' . sha1('artisanpack-ui|3');
    Cache::put($statusKey, ['projectId' => 'p', 'fieldId' => 'f', 'options' => []], 600);

    $this->putJson('/admin/artisanpack-ui/settings', validSettings(['docs_api_token' => '', 'github_project_number' => 4]))->assertOk();

    expect(Cache::has($statusKey))->toBeFalse();
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
    'nat64'         => ['https://[64:ff9b::7f00:1]/'],
    'cgnat'         => ['https://100.64.0.1/'],
    'ipv4-mapped'   => ['https://[::ffff:127.0.0.1]/'],
    'unresolvable'  => ['https://nonexistent.invalid/'],
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

describe('release announcement settings', function (): void {
    /**
     * @return array<string, mixed>
     */
    function announcementSettings(array $overrides = []): array
    {
        return validSettings([
            'github_webhook_secret'              => 'a-webhook-secret-of-some-length',
            'bluesky_handle'                     => '@artisanpackui.bsky.social',
            'bluesky_app_password'               => 'abcd-efgh-ijkl-mnop',
            'mastodon_instance_url'              => 'https://mastodon.example/',
            'mastodon_access_token'              => 'mastodon-token',
            'announcement_excluded_repositories' => "artisanpack-ui-website-theme\nArtisanPack-UI-Website-Plugin, forms\n\nforms",
            ...$overrides,
        ]);
    }

    it('saves the announcement settings and encrypts the secrets at rest', function (): void {
        $this->putJson('/admin/artisanpack-ui/settings', announcementSettings())
            ->assertOk()
            ->assertJsonPath('settings.hasWebhookSecret', true)
            ->assertJsonPath('settings.blueskyHandle', 'artisanpackui.bsky.social')
            ->assertJsonPath('settings.hasBlueskyAppPassword', true)
            ->assertJsonPath('settings.mastodonInstanceUrl', 'https://mastodon.example')
            ->assertJsonPath('settings.hasMastodonAccessToken', true)
            ->assertJsonPath('settings.excludedRepositories', ['artisanpack-ui-website-theme', 'artisanpack-ui-website-plugin', 'forms']);

        $settings = IntegrationSettings::current();
        $raw      = DB::table('artisanpack_ui_settings')->first();

        foreach (['github_webhook_secret', 'bluesky_app_password', 'mastodon_access_token'] as $column) {
            expect($raw->{$column})->not->toBeNull()->not->toContain($settings->{$column});
        }
    });

    it('never sends the announcement secrets to the browser', function (): void {
        IntegrationSettings::create(announcementSettings());

        $this->get('/admin/artisanpack-ui/settings')
            ->assertOk()
            ->assertDontSee('a-webhook-secret-of-some-length')
            ->assertDontSee('abcd-efgh-ijkl-mnop')
            ->assertDontSee('mastodon-token')
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.hasWebhookSecret', true)
                ->where('endpoints.webhook', url('/plugins/artisanpack-ui/webhooks/github'))
                ->has('endpoints.testBluesky')
                ->has('endpoints.testMastodon'));
    });

    it('excludes the site theme and plugin until a list is saved', function (): void {
        expect(IntegrationSettings::current()->excludedRepositories())->toBe(IntegrationSettings::DEFAULT_EXCLUDED_REPOSITORIES);
    });

    it('saves an empty exclusion list as excluding nothing', function (?string $list): void {
        IntegrationSettings::create(announcementSettings());

        $this->putJson('/admin/artisanpack-ui/settings', announcementSettings([
            'github_webhook_secret'              => '',
            'bluesky_app_password'               => '',
            'mastodon_access_token'              => '',
            'announcement_excluded_repositories' => $list,
        ]))->assertOk()->assertJsonPath('settings.excludedRepositories', []);
    })->with(['empty' => [''], 'null' => [null]]);

    it('keeps the exclusion list when a save leaves it out', function (): void {
        IntegrationSettings::create(announcementSettings(['announcement_excluded_repositories' => 'forms']));

        $payload = announcementSettings(['github_webhook_secret' => '', 'bluesky_app_password' => '', 'mastodon_access_token' => '']);
        unset($payload['announcement_excluded_repositories']);

        $this->putJson('/admin/artisanpack-ui/settings', $payload)->assertOk()->assertJsonPath('settings.excludedRepositories', ['forms']);
    });

    it('requires the Mastodon token again when the instance moves to another host', function (): void {
        IntegrationSettings::create(announcementSettings());

        $this->putJson('/admin/artisanpack-ui/settings', announcementSettings([
            'mastodon_instance_url' => 'https://attacker.example',
            'mastodon_access_token' => '',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mastodon_access_token' => 'Re-enter the access token when you change the Mastodon instance URL.']);
    });

    it('clears the announcement secrets on request', function (): void {
        IntegrationSettings::create(announcementSettings());

        $this->putJson('/admin/artisanpack-ui/settings', announcementSettings([
            'github_webhook_secret'        => '',
            'bluesky_app_password'         => '',
            'mastodon_access_token'        => '',
            'remove_github_webhook_secret' => true,
            'remove_bluesky_app_password'  => true,
            'remove_mastodon_access_token' => true,
        ]))
            ->assertOk()
            ->assertJsonPath('settings.hasWebhookSecret', false)
            ->assertJsonPath('settings.hasBlueskyAppPassword', false)
            ->assertJsonPath('settings.hasMastodonAccessToken', false);
    });

    it('rejects invalid announcement settings', function (array $overrides, string $field): void {
        $this->putJson('/admin/artisanpack-ui/settings', announcementSettings($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'short secret'      => [['github_webhook_secret' => 'too-short'], 'github_webhook_secret'],
        'bad handle'        => [['bluesky_handle' => 'not a handle'], 'bluesky_handle'],
        'bare handle'       => [['bluesky_handle' => 'artisanpackui'], 'bluesky_handle'],
        'bad instance'      => [['mastodon_instance_url' => 'not a url'], 'mastodon_instance_url'],
        'private instance'  => [['mastodon_instance_url' => 'https://localhost'], 'mastodon_instance_url'],
        'org/repo excluded' => [['announcement_excluded_repositories' => 'ArtisanPack-UI/seo'], 'announcement_excluded_repositories'],
    ]);

    it('refuses a new secret sent together with its removal', function (string $key): void {
        IntegrationSettings::create(announcementSettings());

        $this->putJson('/admin/artisanpack-ui/settings', announcementSettings([
            'github_private_key' => '',
            'docs_api_token'     => '',
            $key                 => 'x-replacement-secret-value-long-enough',
            'remove_' . $key     => true,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($key);
    })->with(['docs_api_token', 'github_webhook_secret', 'bluesky_app_password', 'mastodon_access_token']);

    it('saves only the Mastodon instance origin', function (): void {
        $this->putJson('/admin/artisanpack-ui/settings', announcementSettings(['mastodon_instance_url' => 'https://Phpc.Social/@artisanpackui?x=1']))
            ->assertOk()
            ->assertJsonPath('settings.mastodonInstanceUrl', 'https://phpc.social');
    });

    it('accepts a DID as the Bluesky handle', function (): void {
        $this->putJson('/admin/artisanpack-ui/settings', announcementSettings(['bluesky_handle' => 'did:plc:abcdefghijklmnopqrstuvwx']))
            ->assertOk();
    });

    it('passes the Bluesky check without posting', function (): void {
        IntegrationSettings::create(announcementSettings());
        Http::fake(['bsky.social/xrpc/com.atproto.server.createSession' => Http::response([
            'accessJwt' => 'jwt',
            'did'       => 'did:plc:abcdefghijklmnopqrstuvwx',
            'handle'    => 'artisanpackui.bsky.social',
        ])]);

        $this->postJson('/admin/artisanpack-ui/settings/test/bluesky')
            ->assertOk()
            ->assertJson(['ok' => true, 'details' => ['Signed in as @artisanpackui.bsky.social.']]);

        Http::assertSentCount(1);
    });

    it('fails the Bluesky check with an admin-friendly message', function (): void {
        IntegrationSettings::create(announcementSettings());
        Http::fake(['bsky.social/*' => Http::response(['error' => 'AuthenticationRequired'], 401)]);

        $this->postJson('/admin/artisanpack-ui/settings/test/bluesky')
            ->assertOk()
            ->assertJson(['ok' => false])
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Bluesky rejected the handle or app password'));
    });

    it('passes the Mastodon check without posting', function (): void {
        IntegrationSettings::create(announcementSettings());
        Http::fake(['mastodon.example/api/v1/accounts/verify_credentials' => Http::response(['acct' => 'artisanpackui'])]);

        $this->postJson('/admin/artisanpack-ui/settings/test/mastodon')
            ->assertOk()
            ->assertJson(['ok' => true])
            ->assertJsonPath('details.0', 'Signed in as @artisanpackui.');
    });

    it('reports unconfigured networks without calling them', function (string $network): void {
        Http::fake();

        $this->postJson('/admin/artisanpack-ui/settings/test/' . $network)
            ->assertOk()
            ->assertJson(['ok' => false])
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'isn\'t configured'));

        Http::assertNothingSent();
    })->with(['bluesky', 'mastodon']);
});
