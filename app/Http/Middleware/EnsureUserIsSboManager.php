<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\SboWorkspaceAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureUserIsSboManager
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()?->fresh();

        if ( ! $user) {
            abort(403);
        }

        if (app(SboWorkspaceAccess::class)->hasOwnerWorkspace($user) || $user->role->isAdmin()) {
            return $next($request);
        }

        /*
         * Owning no business is not "forbidden" — it is the state BEFORE the
         * workspace, and the answer to it is the form that creates one.
         *
         * This used to abort(403) flat, which a signed-in member met by simply
         * clicking Dashboard in the header. It became much easier to hit once
         * adding a business stopped saving anything until the fee is paid: a
         * member can now be signed in, verified, and own nothing for as long as
         * they take to pay.
         *
         * Only for a page they are navigating to. A POST that lands here is a
         * request to act on a workspace that does not exist, and bouncing it to
         * a form would swallow the action.
         */
        if ($request->isMethod('GET')) {
            return redirect()->route('sbo.listings.create');
        }

        abort(403);
    }
}
