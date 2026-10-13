<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AdCampaign;
use App\Models\AdPaymentTransaction;
use App\Models\AdRateCard;
use App\Rules\AdCreativeDimensions;
use App\Support\EspeesMerchantGateway;
use App\Support\SboFeeCalculator;
use App\Support\SboNetworkAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Throwable;

final class AdCampaignController extends Controller
{
    /**
     * The self-service builder: create a campaign for a chosen placement and a
     * date range, priced from the placement's daily rate × the days, then hand
     * straight off to Espees to pay for it.
     *
     * The price is computed here, never taken from the browser — a client that
     * posts its own figure does not get to name what it pays.
     */
    public function submit(Request $request): RedirectResponse
    {
        /*
         * The builder is for verified businesses, and the screen only shows the
         * form to one — but the endpoint has to enforce it too, or a direct post
         * walks around the screen. Same gate the SBO network uses: a member who
         * owns a fully verified, active listing.
         */
        if ( ! app(SboNetworkAccess::class)->userCanUseNetwork($request->user())) {
            return back()->withErrors([
                'slot' => 'Only verified businesses can submit an advertisement. Please complete your verification first.',
            ]);
        }

        $slots = config('ads.slots', []);
        $slot = (string) $request->input('slot');
        $slotConfig = $slots[$slot] ?? null;
        $requiresMobile = is_array($slotConfig['mobile'] ?? null) && 2 === count($slotConfig['mobile']);

        $data = $request->validate([
            'slot' => ['required', 'string', Rule::in(array_keys($slots))],
            'title' => ['required', 'string', 'max:255'],
            'cta_text' => ['required', 'string', 'max:60'],
            'cta_url' => ['required', 'url', 'max:2048'],
            'starts_at' => ['required', 'date', 'after_or_equal:today'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'desktop_creative' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', new AdCreativeDimensions($slot)],
            'mobile_creative' => [
                $requiresMobile ? 'required' : 'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
                new AdCreativeDimensions($slot, true),
            ],
        ]);

        $starts = Carbon::parse($data['starts_at'])->startOfDay();
        $ends = Carbon::parse($data['ends_at'])->startOfDay();
        $days = (int) $starts->diffInDays($ends);

        /*
         * The daily rate comes from the admin's rate card for this placement —
         * price ÷ days on the most granular active card — not from the browser
         * and not from the config, which is only a fallback where no card
         * exists. The campaign is linked to that card so the price is traceable.
         */
        $rateCard = AdRateCard::query()
            ->active()
            ->where('slot', $slot)
            ->orderBy('duration_days')
            ->first();

        $rate = $rateCard && $rateCard->duration_days > 0
            ? (int) round($rateCard->price / $rateCard->duration_days)
            : (int) ($rateCard->price ?? $slotConfig['rate'] ?? 0);

        $price = $rate * $days;

        if ($price <= 0) {
            return back()
                ->withErrors(['ends_at' => 'This placement is not priced yet, or the date range is empty. Please contact support.'])
                ->withInput();
        }

        $gateway = SboFeeCalculator::espeesGateway();

        if ( ! $gateway['configured']) {
            return back()->withErrors(['slot' => 'Espees checkout is not configured yet. Please contact support.'])->withInput();
        }

        $campaign = AdCampaign::create([
            'user_id' => $request->user()->id,
            'ad_rate_card_id' => $rateCard?->id,
            'slot' => $slot,
            'title' => $data['title'],
            'cta_text' => $data['cta_text'],
            'cta_url' => $data['cta_url'],
            'starts_at' => $starts,
            'ends_at' => $ends,
            // Also the requested start, which admin approval reads to schedule
            // the placement on the date the advertiser chose.
            'desired_start_date' => $starts,
            'duration_days' => $days,
            'price' => $price,
            'currency' => SboFeeCalculator::currency(),
            'status' => AdCampaign::STATUS_PENDING_PAYMENT,
        ]);

        $campaign->addMedia($request->file('desktop_creative')->getRealPath())
            ->usingFileName($request->file('desktop_creative')->getClientOriginalName())
            ->toMediaCollection('desktop_creative');

        if ($request->hasFile('mobile_creative')) {
            $campaign->addMedia($request->file('mobile_creative')->getRealPath())
                ->usingFileName($request->file('mobile_creative')->getClientOriginalName())
                ->toMediaCollection('mobile_creative');
        }

        // Straight to Espees: create the checkout, then hand the browser off
        // to it (see the redirect at the end of the method).
        $espees = app(EspeesMerchantGateway::class);

        $transaction = AdPaymentTransaction::create([
            'ad_campaign_id' => $campaign->id,
            'provider' => 'espees',
            'local_reference' => 'ADV-' . $campaign->id . '-' . mb_strtoupper(bin2hex(random_bytes(3))),
            'amount' => $campaign->price,
            'currency' => $campaign->currency,
            'gateway_status' => 'initiated',
            'review_status' => 'none',
        ]);

        try {
            $payment = $espees->createProduct(
                gateway: $gateway,
                localReference: $transaction->local_reference,
                narration: 'Advertising campaign - ' . $campaign->title,
                amount: $transaction->amount,
                successUrl: route('advertise.campaigns.payment.success', $transaction),
                failUrl: route('advertise.campaigns.payment.failure', $transaction),
                userData: [
                    'ad_campaign_id' => $campaign->id,
                    'owner_id' => $campaign->user_id,
                    'slot' => $campaign->slot,
                    'local_reference' => $transaction->local_reference,
                ],
            );
        } catch (Throwable $exception) {
            $transaction->update(['gateway_status' => 'failed']);
            $campaign->update(['status' => AdCampaign::STATUS_PAYMENT_FAILED]);

            return back()->withErrors(['slot' => 'Espees checkout could not be started. Please try again shortly.'])->withInput();
        }

        $transaction->update([
            'provider_reference' => $payment['payment_ref'],
            'gateway_status' => 'redirected',
            'raw_payload' => [
                'product_request' => $payment['payload'],
                'product_response' => $payment['response'],
            ],
        ]);

        $checkoutUrl = $espees->paymentUrl($payment['payment_ref']);

        /*
         * Not Inertia::location() here. That answers with a 409 and an
         * X-Inertia-Location header, and this multipart POST goes through the
         * proxy in front of the app, which drops that header — so the browser
         * never left for Espees and the button looked dead. The URL is flashed
         * instead and the SPA navigates to it with a plain window.location,
         * which nothing in the middle can strip. A non-Inertia caller still
         * gets a real redirect to the same page, where the flash is read.
         */
        return redirect()
            ->to(route('advertise') . '#builder')
            ->with('espees_redirect', $checkoutUrl);
    }

    public function store(Request $request): RedirectResponse
    {
        $slots = config('ads.slots', []);
        $slot = (string) $request->input('slot');
        $slotConfig = $slots[$slot] ?? null;
        $requiresMobile = is_array($slotConfig['mobile'] ?? null) && 2 === count($slotConfig['mobile']);

        $data = $request->validate([
            'slot' => ['required', 'string', Rule::in(array_keys($slots))],
            'ad_rate_card_id' => ['required', 'integer', 'exists:ad_rate_cards,id'],
            'title' => ['required', 'string', 'max:255'],
            'cta_text' => ['required', 'string', 'max:60'],
            'cta_url' => ['required', 'url', 'max:2048'],
            'desired_start_date' => ['nullable', 'date', 'after_or_equal:today'],
            'desktop_creative' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', new AdCreativeDimensions($slot)],
            'mobile_creative' => [
                $requiresMobile ? 'required' : 'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
                new AdCreativeDimensions($slot, true),
            ],
        ]);

        $rateCard = AdRateCard::query()
            ->active()
            ->where('id', $data['ad_rate_card_id'])
            ->where('slot', $data['slot'])
            ->first();

        if ( ! $rateCard) {
            return back()
                ->withErrors(['ad_rate_card_id' => 'Please choose an active package for the selected placement.'])
                ->withInput();
        }

        $campaign = AdCampaign::create([
            'user_id' => $request->user()->id,
            'ad_rate_card_id' => $rateCard->id,
            'slot' => $rateCard->slot,
            'title' => $data['title'],
            'cta_text' => $data['cta_text'],
            'cta_url' => $data['cta_url'],
            'duration_days' => $rateCard->duration_days,
            'price' => $rateCard->price,
            'currency' => SboFeeCalculator::currency(),
            'desired_start_date' => $data['desired_start_date'] ?? null,
            'status' => AdCampaign::STATUS_PENDING_PAYMENT,
        ]);

        $campaign->addMedia($request->file('desktop_creative')->getRealPath())
            ->usingFileName($request->file('desktop_creative')->getClientOriginalName())
            ->toMediaCollection('desktop_creative');

        if ($request->hasFile('mobile_creative')) {
            $campaign->addMedia($request->file('mobile_creative')->getRealPath())
                ->usingFileName($request->file('mobile_creative')->getClientOriginalName())
                ->toMediaCollection('mobile_creative');
        }

        return redirect()
            ->to(route('advertise') . '#advertise-campaign-builder')
            ->with('advertising_campaign_success', 'Campaign created. Please complete Espees payment to submit it for review.');
    }
}
