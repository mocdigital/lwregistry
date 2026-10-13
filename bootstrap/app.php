<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        channels: __DIR__ . '/../routes/channels.php',
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('web')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '*'),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        $middleware->appendToGroup('web', [
            App\Http\Middleware\EnsureUserIsNotSuspended::class,
            /*
             * Before HandleInertiaRequests: that middleware shares the
             * translation dictionary with every response, so setting the
             * locale after it would ship English to a French reader.
             */
            App\Http\Middleware\SetLocale::class,
            App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        /*
         * The GBPC conference-registration endpoint is posted to by a static
         * site on another host, which cannot carry a session CSRF token. CORS
         * (config/cors.php) restricts who may call it; the route is rate-limited.
         */
        $middleware->validateCsrfTokens(except: [
            'gbpc/*',
        ]);

        $middleware->alias([
            'admin' => App\Http\Middleware\EnsureUserIsAdmin::class,
            'admin_page' => App\Http\Middleware\EnsureAdminPageAccess::class,
            'sbo_manager' => App\Http\Middleware\EnsureUserIsSboManager::class,
            'sbo_payment_approved' => App\Http\Middleware\EnsureSboPaymentApproved::class,
            'sbo_network_verified' => App\Http\Middleware\EnsureVerifiedSboNetworkAccess::class,
            'permission' => App\Http\Middleware\EnsureHasPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Expired-session (419 / CSRF token mismatch) handling. Done on the
         * final response status rather than a typed render callback: the
         * framework prepares TokenMismatchException into a 419 HttpException
         * before typed callbacks match, so keying off the status is what
         * reliably fires.
         */
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if (419 !== $response->getStatusCode()) {
                return $response;
            }

            // Signing out with a stale token: finish the sign-out and go home.
            if ($request->is('logout')) {
                Auth::guard('web')->logout();

                if ($request->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }

                return redirect()->route('home');
            }

            /*
             * A JSON caller that manages its own token keeps the 419 so it can
             * recover — the sales-assistant widget fetches a fresh token and
             * retries. Inertia is not that: it carries X-Inertia and wants a
             * normal redirect.
             */
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return $response;
            }

            /*
             * Everyone else hit an expired session on a stale page or form.
             * Send them to sign in rather than a dead "Page Expired" screen,
             * with a fresh token so the login form they land on submits.
             */
            if ($request->hasSession()) {
                $request->session()->regenerateToken();
            }

            return redirect()->route('login')
                ->with('status', 'Your session expired. Please sign in again.');
        });
    })->create();
