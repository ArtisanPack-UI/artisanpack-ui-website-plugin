<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * A Bluesky or Mastodon call failed. The message is written for an admin.
 *
 * {@see self::$status} is the HTTP status (0 when the network was never
 * reached). {@see self::$permanent} marks a failure that retrying won't fix,
 * such as rejected credentials or a post the network refused, so the
 * announcement job gives up at once instead of retrying it.
 *
 * @since 1.4.0
 */
final class SocialPostException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly bool $permanent = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /**
     * Map a failed response to an admin-friendly exception. A 401 or 403
     * names the credential to check; any other 4xx but 429 is permanent.
     *
     * @param  string  $network  The network's display name.
     * @param  string  $credential  What to check on a 401 or 403, e.g. "app password".
     */
    public static function fromResponse(string $network, Response $response, string $credential): self
    {
        $status = $response->status();
        $detail = self::detail($response);

        if (401 === $status || 403 === $status) {
            return new self(__(':network rejected the :credential. Check it in ArtisanPack UI settings.', [
                'network'    => $network,
                'credential' => $credential,
            ]), $status, true);
        }

        $message = '' === $detail
            ? __(':network answered with HTTP :status.', ['network' => $network, 'status' => $status])
            : __(':network answered with HTTP :status: :detail', ['network' => $network, 'status' => $status, 'detail' => $detail]);

        return new self($message, $status, $status >= 400 && $status < 500 && 429 !== $status);
    }

    public static function unreachable(string $network, ?Throwable $previous = null): self
    {
        return new self(__(':network couldn\'t be reached.', ['network' => $network]), 0, false, $previous);
    }

    public static function unexpectedResponse(string $network, int $status): self
    {
        return new self(__(':network sent a response the plugin didn\'t understand (HTTP :status).', [
            'network' => $network,
            'status'  => $status,
        ]), $status);
    }

    /**
     * The error text a network sent, if any, cut short so a large error
     * page can't flood the log. Bluesky sends `message`, Mastodon `error`.
     */
    private static function detail(Response $response): string
    {
        $json   = $response->json();
        $detail = is_array($json) ? ($json['message'] ?? $json['error'] ?? '') : '';

        return is_string($detail) ? mb_substr(trim($detail), 0, 200) : '';
    }
}
