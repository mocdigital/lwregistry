<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()?->fresh(); // 🔑 FORCE reload from DB

        if ( ! $user || ! $user->role->isAdmin()) {
            abort(403);
        }

        return $next($request);
    }
}
