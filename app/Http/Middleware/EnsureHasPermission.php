<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard: `permission:approve_sbos`. Passes if the (fresh) user holds
 * the capability. Super Admins implicitly pass via User::hasPermission().
 */
final class EnsureHasPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user()?->fresh();

        if ( ! $user || ! $user->hasPermission($permission)) {
            abort(403);
        }

        return $next($request);
    }
}
