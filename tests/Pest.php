<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Tests\Fixtures\User;
use ArtisanPackUI\Site\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
