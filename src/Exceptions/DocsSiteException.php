<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * A docs site API call failed. The message is written for an admin and
 * names the fix where there is one; {@see self::$status} is the HTTP status
 * (0 when the site was never reached) and {@see self::$errors} carries a
 * 422's per-field messages.
 *
 * @since 0.2.0
 */
final class DocsSiteException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly array $errors = [],
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /**
     * Map a failed response to an admin-friendly exception.
     *
     * @param  string|null  $ability  The token ability the call needs, named in the 403 message.
     */
    public static function fromResponse(Response $response, ?string $ability = null): self
    {
        $status = $response->status();
        $detail = trim((string) $response->json('message', ''));

        if (401 === $status) {
            return new self(__('The docs site rejected the API token. Check the token in ArtisanPack UI settings.'), $status);
        }

        if (403 === $status) {
            return new self(null === $ability
                ? __('The docs site API token isn\'t allowed to do this.')
                : __('The docs site API token isn\'t allowed to do this. It needs the ":ability" ability.', ['ability' => $ability]), $status);
        }

        if (404 === $status) {
            return new self(__('The docs site couldn\'t find that record.'), $status);
        }

        if (422 === $status) {
            $errors = $response->json('errors');

            return new self(
                '' !== $detail ? $detail : __('The docs site rejected the request as invalid.'),
                $status,
                is_array($errors) ? $errors : [],
            );
        }

        if (429 === $status) {
            $retryAfter = is_numeric($response->header('Retry-After')) ? (int) $response->header('Retry-After') : null;

            return new self(null === $retryAfter
                ? __('The docs site is rate limiting requests. Try again shortly.')
                : __('The docs site is rate limiting requests. Try again in :seconds seconds.', ['seconds' => $retryAfter]), $status, [], $retryAfter);
        }

        if ($status >= 500) {
            return new self(__('The docs site had a problem handling the request (HTTP :status). Try again shortly.', ['status' => $status]), $status);
        }

        return new self(__('The docs site returned HTTP :status.', ['status' => $status]), $status);
    }

    public static function unreachable(string $baseUrl, Throwable $previous): self
    {
        return new self(__('Could not reach the docs site at :url.', ['url' => $baseUrl]), 0, [], null, $previous);
    }

    public static function unexpectedResponse(int $status): self
    {
        return new self(__('The docs site sent a response the plugin doesn\'t understand (HTTP :status).', ['status' => $status]), $status);
    }
}
