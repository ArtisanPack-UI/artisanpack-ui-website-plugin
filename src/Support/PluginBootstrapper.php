<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Support;

use ArtisanPackUI\Site\ArtisanPackUIServiceProvider;
use ArtisanPackUI\Site\Models\IntegrationSettings;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;
use Inertia\Inertia;

/**
 * The host-independent parts of {@see ArtisanPackUIServiceProvider}: the
 * container bindings, the Gate ability and the shared Inertia prop. The
 * provider and the test suite both call these, so the suite runs against
 * the same wiring the plugin ships with.
 *
 * @since 0.2.0
 */
final class PluginBootstrapper
{
    /**
     * Resolve {@see IntegrationSettings} to the saved row, fresh on every
     * resolution, so the API clients and connection checks autowire against
     * whatever the Settings page last saved.
     */
    public static function register(Application $app): void
    {
        $app->bind(IntegrationSettings::class, static fn (): IntegrationSettings => IntegrationSettings::current());
    }

    /**
     * Define {@see Permissions::ACCESS} and share the current user's plugin
     * abilities as the `artisanpackUi.can` Inertia prop, which the Edit
     * Package tabs read to gate themselves (the boot module can't read
     * page props when it registers them).
     */
    public static function boot(Application $app): void
    {
        $gate = $app->make(Gate::class);

        Permissions::defineGates($gate);

        Inertia::share('artisanpackUi', static fn (): array => [
            'can' => Permissions::abilitiesFor($gate, $app->make('auth')->user()),
        ]);
    }
}
