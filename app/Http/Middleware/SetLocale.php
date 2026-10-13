<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the request into the reader's language.
 *
 * Runs ahead of HandleInertiaRequests, because that middleware shares the
 * translation dictionary with every page — sharing it before the locale is set
 * would send English to a French reader on every response.
 */
final class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = Locales::resolve($request, $request->user());

        app()->setLocale($locale);

        /*
         * Mirrored into the session so a guest's choice survives the next
         * request without a database row, and so a member who signs out keeps
         * reading in the language they were already reading in.
         */
        if ($request->hasSession() && $request->session()->get(Locales::SESSION_KEY) !== $locale) {
            $request->session()->put(Locales::SESSION_KEY, $locale);
        }

        return $next($request);
    }
}
