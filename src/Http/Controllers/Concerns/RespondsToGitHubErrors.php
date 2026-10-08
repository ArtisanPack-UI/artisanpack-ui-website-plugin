<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Http\Controllers\Concerns;

use ArtisanPackUI\Site\Exceptions\GitHubException;
use ArtisanPackUI\Site\Exceptions\GitHubRateLimitException;
use ArtisanPackUI\Site\Exceptions\IntegrationNotConfiguredException;
use Closure;
use Illuminate\Http\JsonResponse;

/**
 * Turns the GitHub failures a board or issue endpoint can hit into JSON the
 * admin UI shows as-is: 429 with `Retry-After` for a rate limit, 404 when
 * GitHub can't find (or the App can't see) the resource, and 422 for
 * anything else, including missing settings.
 *
 * @since 1.0.0
 */
trait RespondsToGitHubErrors
{
    /**
     * @param  Closure(): JsonResponse  $call
     */
    private static function attempt(Closure $call): JsonResponse
    {
        try {
            return $call();
        } catch (GitHubRateLimitException $exception) {
            return response()->json(['message' => $exception->getMessage()], 429, ['Retry-After' => (string) $exception->retryAfter]);
        } catch (GitHubException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404 === $exception->status ? 404 : 422);
        } catch (IntegrationNotConfiguredException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
