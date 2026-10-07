<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Tests;

use ArtisanPackUI\Hooks\Providers\HooksServiceProvider;
use ArtisanPackUI\Site\ArtisanPackUIServiceProvider;
use ArtisanPackUI\Site\Support\Permissions;
use ArtisanPackUI\Site\Tests\Fixtures\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Base test case for the ArtisanPack UI plugin suite.
 *
 * The real {@see ArtisanPackUIServiceProvider} needs the Keystone host to
 * boot, so Testbench boots {@see TestPluginServiceProvider} instead: the
 * same bindings, Gate ability, Inertia prop and routes, without the host-only
 * nav, icon, block and federation wiring (verified in a running install).
 *
 * The host's RBAC is modelled as a Gate ability per plugin permission that
 * passes when the fixture user's `$permissions` lists it.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * @param  Application  $app
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            HooksServiceProvider::class,
            InertiaServiceProvider::class,
            TestPluginServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        // The `package` content type's records table, as the host's seeder
        // and the PackageFields provisioner leave it.
        Schema::create('packages', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->string('status', 32)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->longText('content')->nullable();
            $table->text('excerpt')->nullable();
            $table->unsignedBigInteger('featured_image_id')->nullable();
            $table->bigInteger('docs_package_id')->nullable();
            $table->string('registry')->nullable();
            $table->string('composer_name')->nullable();
            $table->string('npm_name')->nullable();
            $table->string('github_repo')->nullable();
            $table->string('version')->nullable();
            $table->text('icon')->nullable();
            $table->string('docs_url')->nullable();
            $table->dateTime('last_synced_at')->nullable();
        });

        $this->loadMigrationsFrom(dirname(__DIR__) . '/database/migrations');
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('inertia.testing.ensure_pages_exist', false);
        $app['config']->set('view.paths', [__DIR__ . '/Fixtures/views']);

        $gate = $app->make(Gate::class);

        foreach (Permissions::all() as $permission) {
            $gate->define($permission, static fn (User $user): bool => in_array($permission, $user->permissions, true));
        }
    }
}
