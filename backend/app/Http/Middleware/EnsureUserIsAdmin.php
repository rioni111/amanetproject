<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defence in depth: every authenticated workspace route requires the admin role.
 * Fine-grained checks still happen in policies.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isAdmin()) {
            abort(403, 'This action is unauthorized.');
        }

        return $next($request);
    }
}
