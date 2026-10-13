<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\SboNetworkAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureVerifiedSboNetworkAccess
{
    public function __construct(private readonly SboNetworkAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()?->fresh();

        if ( ! $user) {
            abort(403);
        }

        /*
         * Was a bare `abort(403, 'Only fully verified, active SBOs can access
         * the company network.')` — an error page for what is really the same
         * "you are not through onboarding yet" state the payment wall already
         * explains. Sending them to the designed wall (279:15798 / 384:17211)
         * means one screen answers "why can I not get in, and what do I do
         * about it" wherever they hit the edge.
         */
        if ( ! $this->access->userCanUseNetwork($user)) {
            return redirect()->route('sbo.payment-required');
        }

        return $next($request);
    }
}
