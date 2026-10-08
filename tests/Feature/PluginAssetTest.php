<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Http\Controllers\PluginAssetController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/**
 * The public route serving the federated bundle from `dist/assets/`
 * (plan item 2.9). Registered here the way the provider registers it, over
 * a throwaway plugins directory.
 */

beforeEach(function (): void {
    $directory = 'plugins-test-' . bin2hex(random_bytes(6));
    $assets    = base_path($directory . '/artisanpack-ui/dist/assets');

    File::ensureDirectoryExists($assets);
    File::put($assets . '/remoteEntry.js', 'export const x = 1;');
    File::put($assets . '/index.html', '<script>alert(1)</script>');
    File::put(base_path($directory . '/artisanpack-ui/plugin.json'), '{}');

    config()->set('cms.plugins.directory', $directory);
    $this->beforeApplicationDestroyed(static fn () => File::deleteDirectory(base_path($directory)));

    Route::name('plugins.artisanpack-ui.assets')
        ->get('/plugins/artisanpack-ui/assets/{path}', PluginAssetController::class)
        ->where('path', '.*');
});

it('serves the remote entry as JavaScript with nosniff', function (): void {
    $this->get('/plugins/artisanpack-ui/assets/remoteEntry.js')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/javascript; charset=utf-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'no-cache, public');
});

it('refuses file types the build never emits', function (): void {
    $this->get('/plugins/artisanpack-ui/assets/index.html')->assertNotFound();
});

it('refuses paths outside dist/assets', function (): void {
    $this->get('/plugins/artisanpack-ui/assets/../plugin.json')->assertNotFound();
    $this->get('/plugins/artisanpack-ui/assets/..%2F..%2Fplugin.json')->assertNotFound();
});
