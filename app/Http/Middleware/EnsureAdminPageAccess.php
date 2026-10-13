<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\AdminPages;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAdminPageAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()?->fresh();

        if ( ! $user || ! $user->role->isAdmin()) {
            abort(403);
        }

        $page = AdminPages::keyForRoute($request->route()?->getName());

        if ( ! $page || ! $user->hasAdminPage($page)) {
            abort(403);
        }

        return $next($request);
    }
}
