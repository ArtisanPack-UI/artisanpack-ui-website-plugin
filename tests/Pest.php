<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Support\PackageIconSet;
use ArtisanPackUI\Site\Tests\Fixtures\User;
use ArtisanPackUI\Site\Tests\TestCase;
use ArtisanPackUI\VisualEditor\Services\Icon\SvgSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

pest()
    ->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/**
 * Create a fixture user holding exactly the given plugin permissions.
 *
 * @param  list<string>  $permissions
 */
function makeUser(array $permissions = []): User
{
    $user = User::create([
        'name'     => 'Test User',
        'email'    => uniqid('user-', true) . '@example.test',
        'password' => 'secret',
    ]);
    $user->permissions = $permissions;

    return $user;
}

/**
 * Sign in as a fixture user holding the given plugin permissions.
 *
 * @param  list<string>  $permissions
 */
function actingAsUserWith(array $permissions = []): User
{
    $user = makeUser($permissions);
    test()->actingAs($user);

    return $user;
}

/**
 * A throwaway RSA private key in PEM form, for the App JWT.
 */
function testPrivateKey(): string
{
    static $pem = null;

    if (null === $pem) {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
    }

    return $pem;
}

/**
 * Point the `apui` icon set at a fresh temporary directory for this test,
 * removed again when the test ends.
 */
function useTemporaryIconSet(): PackageIconSet
{
    $directory = sys_get_temp_dir() . '/artisanpack-ui-icons-' . bin2hex(random_bytes(6));
    $iconSet   = new PackageIconSet(new SvgSanitizer, $directory);

    app()->instance(PackageIconSet::class, $iconSet);
    test()->beforeApplicationDestroyed(static fn () => File::deleteDirectory($directory));

    return $iconSet;
}

/**
 * A Font Awesome-style icon a test can drop into an icon set.
 */
function testSvg(string $path = 'M0 0h24v24H0z'): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="' . $path . '"/></svg>';
}

/**
 * Add a blog post filed under the given category (Updates by default) and
 * return its id, the way the host's blog leaves one.
 *
 * @param  array<string, mixed>  $attributes
 */
function recapPost(string $title, string $publishedAt, array $attributes = [], string $categorySlug = 'updates'): int
{
    $categoryId = DB::table('post_categories')->where('slug', $categorySlug)->value('id')
        ?? DB::table('post_categories')->insertGetId(['name' => ucfirst($categorySlug), 'slug' => $categorySlug, 'created_at' => now(), 'updated_at' => now()]);

    $postId = DB::table('posts')->insertGetId([
        'title'        => $title,
        'slug'         => Str::slug($title) . '-' . Str::random(4),
        'status'       => 'published',
        'published_at' => $publishedAt,
        'created_at'   => now(),
        'updated_at'   => now(),
        ...$attributes,
    ]);

    DB::table('post_category_pivots')->insert(['post_id' => $postId, 'post_category_id' => $categoryId]);

    return (int) $postId;
}
