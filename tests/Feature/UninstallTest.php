<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Support\PluginBootstrapper;
use Illuminate\Support\Facades\Cache;

/**
 * Deleting the plugin removes the credentials and cached token it keeps
 * outside its own tables (plan item 2.7).
 */

beforeEach(function (): void {
    IntegrationSettings::create([
        'docs_base_url'          => 'https://docs.example.invalid',
        'docs_api_token'         => 'docs-token',
        'github_app_id'          => '123',
        'github_installation_id' => '456',
        'github_private_key'     => testPrivateKey(),
    ]);

    $this->tokenKey = 'artisanpack-ui:github:installation-token:' . sha1('123|456');
    Cache::put($this->tokenKey, 'cached', 600);
});

it('purges the stored settings and cached token when the plugin is deleted', function (): void {
    PluginBootstrapper::purgeOnDelete('artisanpack-ui');

    expect(IntegrationSettings::count())->toBe(0)
        ->and(Cache::has($this->tokenKey))->toBeFalse();
});

it('runs the purge from the framework\'s deleting hook', function (): void {
    doAction('ap.cmsFramework.plugin.deleting', 'artisanpack-ui');

    expect(IntegrationSettings::count())->toBe(0);
});

it('leaves everything alone when another plugin is deleted', function (): void {
    doAction('ap.cmsFramework.plugin.deleting', 'some-other-plugin');

    expect(IntegrationSettings::count())->toBe(1)
        ->and(Cache::has($this->tokenKey))->toBeTrue();
});
