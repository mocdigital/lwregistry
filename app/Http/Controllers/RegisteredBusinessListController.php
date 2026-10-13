<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Sbo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Every registered business, behind one password, at an unlisted URL.
 *
 * Deliberately outside the product: no nav, no SPA shell, nothing links to it,
 * and it is not in any sitemap. The path is NOT added to robots.txt either —
 * robots.txt is world-readable, so listing it there would publish the very URL
 * that is meant to stay unlisted. The noindex header below is what keeps
 * crawlers off it, and the password is what keeps people off it.
 */
final class RegisteredBusinessListController extends Controller
{
    /** The list, or the password prompt if this session has not unlocked it. */
    public function show(Request $request): Response
    {
        if ( ! $this->unlocked($request)) {
            return $this->noindex('rlist.gate');
        }

        /*
         * Every business, whatever its state — the page is an inventory of what
         * has been registered, so a pending or unapproved entry belongs on it.
         * Only the columns asked for are selected: nothing about the owner,
         * their church details or the application is loaded, so nothing that is
         * not on the page can leak through the view.
         */
        $businesses = Sbo::query()
            ->select([
                'id', 'name', 'entity_type', 'category', 'business_type',
                'primary_contact_email', 'phone', 'website', 'verification_status',
            ])
            ->orderBy('name')
            ->get();

        return $this->noindex('rlist.businesses', ['businesses' => $businesses]);
    }

    /** Check the password and unlock this session. */
    public function unlock(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        if ( ! Hash::check(self::normalise((string) $request->input('password')), (string) config('rlist.password_hash'))) {
            throw ValidationException::withMessages([
                'password' => 'That password is not right.',
            ]);
        }

        // Regenerated so an unlock cannot be replayed onto a session fixed by
        // someone else — the same reason logging in regenerates.
        $request->session()->regenerate();
        $request->session()->put((string) config('rlist.session_key'), now()->timestamp);

        return redirect()->route('rlist.businesses');
    }

    /** Lock it again and drop the session state. */
    public function lock(Request $request): RedirectResponse
    {
        $request->session()->forget((string) config('rlist.session_key'));

        return redirect()->route('rlist.businesses');
    }

    /**
     * Forgive whitespace, and only whitespace.
     *
     * This password is shared, read off a note and typed by hand, and it
     * contains spaces — so "did I type one space or two?" would otherwise be a
     * lockout with no way to tell it from a wrong password. Surrounding
     * whitespace is dropped and internal runs collapse to a single space; every
     * other character still has to be exactly right.
     *
     * The stored hash is of the NORMALISED password. A replacement hash set via
     * RLIST_PASSWORD_HASH must be generated the same way.
     */
    public static function normalise(string $password): string
    {
        return mb_trim((string) preg_replace('/\s+/u', ' ', $password));
    }

    private function unlocked(Request $request): bool
    {
        $at = $request->session()->get((string) config('rlist.session_key'));

        if ( ! is_int($at) && ! is_numeric($at)) {
            return false;
        }

        return now()->timestamp - (int) $at < (int) config('rlist.unlock_minutes') * 60;
    }

    /**
     * Keep the page out of every index and every cache.
     *
     * `X-Robots-Tag` is the one that binds: it reaches a crawler even when the
     * response is not HTML and even when the crawler never parses the body, and
     * the views carry the equivalent meta tag as well.
     *
     * `Referrer-Policy: no-referrer` stops the URL itself leaking — without it,
     * following any outbound link from this page hands the destination the full
     * address of a page that is supposed to be unlisted.
     *
     * `no-store` keeps it out of shared caches and the browser's back/forward
     * cache, so it cannot be read off a machine after the session locks again.
     *
     * @param  array<string, mixed>  $data
     */
    private function noindex(string $view, array $data = []): Response
    {
        return response()
            ->view($view, $data)
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet, noimageindex')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache');
    }
}
