<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Locales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The header language selector.
 *
 * Open to guests as well as members: someone deciding whether to register
 * should be able to read the site first. A guest's choice lives in the session,
 * a member's is saved to their account so it follows them between devices.
 */
final class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(Locales::codes())],
        ]);

        $request->session()->put(Locales::SESSION_KEY, $data['locale']);

        $user = $request->user();

        if (null !== $user) {
            // forceFill + saveQuietly: a language change is a preference, not
            // an event other listeners should react to.
            $user->forceFill(['locale' => $data['locale']])->saveQuietly();
        }

        app()->setLocale($data['locale']);

        /*
         * Back to where they were, so switching language does not also lose
         * their place — the point of the control is to re-read THIS page.
         */
        return back()->with('status', 'locale-updated');
    }
}
