<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Laravel's `encrypted` cast, except that a value which no longer decrypts
 * reads as null instead of throwing.
 *
 * After an `APP_KEY` change every stored credential is unreadable. With the
 * stock cast that throws on every read, so the Settings page that would let
 * an admin re-enter them returns 500, and so does every endpoint and job
 * that touches the settings. Here the credential reads as unset, the
 * failure is logged once per attribute per process (by name, never the
 * value), and saving a new value repairs it.
 *
 * Values are stored with `encryptString()`, as the stock cast stores them,
 * so rows written before this cast existed read unchanged.
 *
 * @implements CastsAttributes<string|null, string|null>
 *
 * @since 1.0.0
 */
final class SafeEncrypted implements CastsAttributes
{
    /**
     * Attributes already logged as undecryptable in this process.
     *
     * @var array<string, true>
     */
    private static array $logged = [];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (null === $value) {
            return null;
        }

        try {
            return Crypt::decryptString((string) $value);
        } catch (DecryptException) {
            if (! isset(self::$logged[$key])) {
                self::$logged[$key] = true;

                Log::warning('ArtisanPack UI could not decrypt a stored credential; re-enter it in Settings.', [
                    'attribute' => $key,
                ]);
            }

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return null === $value ? null : Crypt::encryptString((string) $value);
    }
}
