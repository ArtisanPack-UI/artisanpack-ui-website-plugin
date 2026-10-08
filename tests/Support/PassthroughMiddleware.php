<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Tests\Support;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test stand-in for the host's `two-factor` and `two-factor.enroll`
 * middleware, which only exist inside a running Keystone install. The
 * plugin's routes still name them, so they are aliased here to a no-op.
 */
class PassthroughMiddleware
{
    public function handle(Request $request, Closure $next, string ...$parameters): Response
    {
        return $next($request);
    }
}
