<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Tests\Support;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test stand-in for the host's `permission:{slug}` middleware (rbac's
 * `CheckPermission`): 401 for a guest, 403 unless the user can() one of the
 * listed permissions.
 */
class GatePermissionMiddleware
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        abort_if(null === $request->user(), Response::HTTP_UNAUTHORIZED);

        foreach ($permissions as $permission) {
            if ($request->user()->can($permission)) {
                return $next($request);
            }
        }

        abort(Response::HTTP_FORBIDDEN);
    }
}
