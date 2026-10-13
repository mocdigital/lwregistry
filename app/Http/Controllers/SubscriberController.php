<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Subscriber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/**
 * "Subscribe Now" — the opportunity-alerts card on the home rail.
 *
 * Open to guests: the card is on the public home page precisely to reach people
 * who are not members yet, so requiring an account would defeat it.
 */
final class SubscriberController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            /*
             * `email:filter` rather than a bare `email`: the default rule
             * accepts things no mail server will, and a list full of addresses
             * that bounce is worse than a shorter one.
             */
            'email' => ['required', 'string', 'email:filter', 'max:255'],
            'source' => ['nullable', 'string', Rule::in([Subscriber::SOURCE_HOME_RAIL])],
        ], [
            'name.required' => 'Tell us your name so we know who to write to.',
            'email.email' => 'That does not look like an email address.',
        ]);

        $email = mb_strtolower(mb_trim($data['email']));

        /*
         * Subscribing twice is somebody checking it worked, not a request for
         * two copies of everything — and it must not look like a failure. The
         * row is updated, and a previous unsubscribe is undone, because asking
         * again IS asking again.
         */
        $subscriber = Subscriber::updateOrCreate(
            ['email' => $email],
            [
                'name' => mb_trim($data['name']),
                'source' => $data['source'] ?? Subscriber::SOURCE_HOME_RAIL,
                'user_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
                'unsubscribed_at' => null,
            ],
        );

        /*
         * The confirmation says "you are on the list" either way. Telling a
         * stranger which addresses are already subscribed turns the form into
         * a way to test whether somebody uses this registry.
         */
        RateLimiter::hit('subscribe:' . $request->ip(), 3600);

        return back()->with('subscribed', __('subscribe.confirmed', [
            'email' => $subscriber->email,
        ]));
    }
}
