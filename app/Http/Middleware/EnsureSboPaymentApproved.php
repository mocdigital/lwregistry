<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\SboWorkspaceAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureSboPaymentApproved
{
    public function __construct(private readonly SboWorkspaceAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()?->fresh();

        // Access to the restricted platform is granted only once a business is
        // ADMIN-APPROVED (fully verified). Paying is not enough — a member who
        // has paid but is awaiting approval is still held back.
        if ( ! $user || ! $this->access->requiresVerification($user)) {
            return $next($request);
        }

        /*
         * The wall/status screen is reachable by anyone gated — it carries the
         * register call to action for a member with no business, and the
         * application status for one awaiting approval.
         */
        if ($request->routeIs('sbo.payment-required')) {
            return $next($request);
        }

        /*
         * Until an admin APPROVES the business, the whole member workspace is
         * held back — the SBO dashboard, the member dashboard, the profile and
         * the account listings included. The member sees only the wall (their
         * "awaiting approval" status screen) and the application flow (fees,
         * documents, payment, progress), which is not behind this middleware and
         * so stays reachable as their way forward.
         *
         * Everyone gated sees the same designed wall (279:15798 / 384:17211).
         * It explains why they cannot get in — the fee is outstanding, or the
         * application is under review — and carries the register-a-business call
         * to action for a member with no listing.
         */
        return redirect()->route('sbo.payment-required');
    }
}
