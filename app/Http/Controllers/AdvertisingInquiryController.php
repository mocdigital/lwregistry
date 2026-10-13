<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AdvertisingInquiry;
use App\Notifications\AdvertisingInquirySubmittedNotification;
use App\Rules\ValidTurnstileToken;
use App\Support\AdminRecipients;
use App\Support\TurnstileVerifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AdvertisingInquiryController
{
    public function __invoke(Request $request): RedirectResponse
    {
        if ( ! $request->user()) {
            return redirect()
                ->to(route('advertise') . '#advertise-inquiry')
                ->with('advertising_auth_required', true)
                ->with('advertising_auth_message', 'Please register your business or log in before sending an advertising inquiry. Payment is not required.');
        }

        $slots = config('ads.slots', []);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'kc_handle' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'ad_location' => ['required', 'string', Rule::in(array_keys($slots))],
            'message' => ['required', 'string', 'max:5000'],
            'cf-turnstile-response' => [Rule::requiredIf(app(TurnstileVerifier::class)->enabled()), new ValidTurnstileToken()],
        ]);

        $inquiry = AdvertisingInquiry::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'kc_handle' => $data['kc_handle'],
            'email' => $data['email'],
            'ad_location' => $data['ad_location'],
            'message' => $data['message'],
            'status' => AdvertisingInquiry::STATUS_NEW,
        ]);

        AdminRecipients::notify(new AdvertisingInquirySubmittedNotification(
            inquiry: $inquiry->only(['name', 'kc_handle', 'email', 'ad_location', 'message']),
            slot: $slots[$data['ad_location']],
        ));

        return redirect()
            ->to(route('advertise') . '#advertise-inquiry')
            ->with('advertising_inquiry_success', 'Your advertising inquiry has been sent. Our team will contact you shortly.');
    }
}
