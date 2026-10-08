<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * A GitHub API call failed. The message is written for an admin; the HTTP
 * status (0 when GitHub was never reached) is kept for callers that branch
 * on it.
 *
 * @since 1.0.0
 */
class GitHubException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }

    /**
     * Build an exception from a failed response, using GitHub's own
     * `message` when it sends one.
     */
    public static function fromResponse(Response $response): self
    {
        $status = $response->status();
        $detail = trim((string) $response->json('message', ''));

        $message = match (true) {
            401 === $status => __('GitHub rejected the App credentials. Check the App ID, private key and installation ID.'),
            403 === $status => __('The GitHub App doesn\'t have permission for this request.'),
            404 === $status => __('GitHub couldn\'t find that resource, or the App can\'t see it.'),
            $status >= 500  => __('GitHub had a problem handling the request (HTTP :status). Try again shortly.', ['status' => $status]),
            default         => __('GitHub returned HTTP :status.', ['status' => $status]),
        };

        if ('' !== $detail) {
            $message .= ' (' . $detail . ')';
        }

        return new self($message, $status);
    }

    public static function unreachable(Throwable $previous): self
    {
        return new self(__('Could not reach GitHub. Check the server\'s network connection.'), 0, $previous);
    }
}
