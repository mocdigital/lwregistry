<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AdCampaign;
use App\Models\AdPaymentTransaction;
use App\Models\AdRateCard;
use App\Models\SboFeeSetting;
use App\Models\User;
use App\Notifications\AdminAdCampaignSubmittedNotification;
use App\Support\SboFeeCalculator;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    SboFeeCalculator::flushCache();

    SboFeeSetting::set('currency', 'Espees');
    SboFeeSetting::set('espees_enabled', '1');
    SboFeeSetting::set('espees_token', 'TOKEN-ADS');
    SboFeeSetting::set('espees_merchant_wallet', 'ADS-WALLET');
});

function adEspeesOwner(): User
{
    return User::factory()->create(['email_verified_at' => now()]);
}

function adEspeesCampaign(User $owner, array $overrides = []): AdCampaign
{
    $rate = AdRateCard::query()->where('slot', 'home_hero')->where('duration_days', 7)->firstOrFail();

    return AdCampaign::create(array_merge([
        'user_id' => $owner->id,
        'ad_rate_card_id' => $rate->id,
        'slot' => $rate->slot,
        'title' => 'Espees Ads Campaign',
        'cta_text' => 'Learn More',
        'cta_url' => 'https://example.com',
        'duration_days' => $rate->duration_days,
        'price' => 450,
        'currency' => 'Espees',
        'status' => AdCampaign::STATUS_PENDING_PAYMENT,
    ], $overrides));
}

function adEspeesTransaction(AdCampaign $campaign, array $overrides = []): AdPaymentTransaction
{
    return AdPaymentTransaction::create(array_merge([
        'ad_campaign_id' => $campaign->id,
        'provider' => 'espees',
        'local_reference' => 'ADS-' . Str::upper(Str::random(10)),
        'provider_reference' => 'AD-PAYREF-' . Str::upper(Str::random(6)),
        'amount' => $campaign->price,
        'currency' => $campaign->currency,
        'gateway_status' => 'redirected',
        'review_status' => 'none',
    ], $overrides));
}

function adEspeesApprovedPayload(AdPaymentTransaction $transaction): array
{
    return [
        'transaction_status' => 'APPROVED',
        'product_sku' => $transaction->local_reference,
        'price' => $transaction->amount,
        'status_details' => 'Successfully Done',
    ];
}

test('the builder submits a verified member straight to Espees via a flashed URL', function (): void {
    Storage::fake('public');
    Http::fake([
        'https://api.espees.org/payment/product' => Http::response([
            'statusCode' => 200,
            'payment_ref' => 'AD-BUILDER-REF',
        ]),
    ]);

    // A member who owns a fully verified, active business.
    $owner = paidMember();

    $this->actingAs($owner)
        ->from(route('advertise'))
        ->post(route('advertise.campaigns.submit'), [
            'slot' => 'home_hero',
            'title' => 'Launch week',
            'cta_text' => 'Learn More',
            'cta_url' => 'https://example.com/launch',
            'starts_at' => now()->addDay()->toDateString(),
            'ends_at' => now()->addDays(8)->toDateString(),
            'desktop_creative' => UploadedFile::fake()->image('desktop.png', 970, 250),
            'mobile_creative' => UploadedFile::fake()->image('mobile.png', 320, 100),
        ])
        ->assertRedirect(route('advertise') . '#builder')
        // Not Inertia::location: the proxy strips its header, so the checkout
        // URL is flashed for the SPA to navigate to with window.location.
        ->assertSessionHas('espees_redirect', 'https://payment.espees.org/pay/AD-BUILDER-REF');

    $campaign = AdCampaign::query()->firstOrFail();

    expect($campaign->status)->toBe(AdCampaign::STATUS_PENDING_PAYMENT)
        ->and($campaign->slot)->toBe('home_hero')
        ->and($campaign->user_id)->toBe($owner->id)
        ->and($campaign->price)->toBeGreaterThan(0)
        ->and($campaign->duration_days)->toBe(7);
});

test('the builder refuses a member whose business is not verified', function (): void {
    Storage::fake('public');
    Http::fake();

    // Email-verified, but owns no verified business — the network gate is closed.
    $owner = adEspeesOwner();

    $this->actingAs($owner)
        ->from(route('advertise'))
        ->post(route('advertise.campaigns.submit'), [
            'slot' => 'home_hero',
            'title' => 'Launch week',
            'cta_text' => 'Learn More',
            'cta_url' => 'https://example.com/launch',
            'starts_at' => now()->addDay()->toDateString(),
            'ends_at' => now()->addDays(8)->toDateString(),
            'desktop_creative' => UploadedFile::fake()->image('desktop.png', 970, 250),
            'mobile_creative' => UploadedFile::fake()->image('mobile.png', 320, 100),
        ])
        ->assertRedirect(route('advertise'))
        ->assertSessionHasErrors('slot');

    expect(AdCampaign::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('ad campaign payment start creates an Espees product and redirects to hosted payment', function (): void {
    Http::fake([
        'https://api.espees.org/payment/product' => Http::response([
            'statusCode' => 200,
            'payment_ref' => 'AD-PAYREF-123',
        ]),
    ]);

    $owner = adEspeesOwner();
    $campaign = adEspeesCampaign($owner);

    $this->actingAs($owner)
        ->post(route('advertise.campaigns.pay', $campaign))
        ->assertRedirect('https://payment.espees.org/pay/AD-PAYREF-123');

    $transaction = AdPaymentTransaction::query()->firstOrFail();

    expect($transaction->provider_reference)->toBe('AD-PAYREF-123')
        ->and($transaction->gateway_status)->toBe('redirected')
        ->and($transaction->amount)->toBe(450)
        ->and($campaign->fresh()->status)->toBe(AdCampaign::STATUS_PENDING_PAYMENT);

    Http::assertSent(function (HttpRequest $request) use ($campaign, $transaction): bool {
        $payload = $request->data();

        return 'https://api.espees.org/payment/product' === $request->url()
            && $request->hasHeader('x-api-key', 'TOKEN-ADS')
            && $payload['product_sku'] === $transaction->local_reference
            && 'Advertising campaign - Espees Ads Campaign' === $payload['narration']
            && 450 === $payload['price']
            && 'ADS-WALLET' === $payload['merchant_wallet']
            && $payload['success_url'] === route('advertise.campaigns.payment.success', $transaction)
            && $payload['fail_url'] === route('advertise.campaigns.payment.failure', $transaction)
            && $payload['user_data']['ad_campaign_id'] === $campaign->id;
    });
});

test('successful ad Espees payment confirms server-side and submits campaign for review', function (): void {
    Notification::fake();

    $admin = User::factory()->create(['role' => UserRole::ADMIN, 'email_verified_at' => now()]);
    $owner = adEspeesOwner();
    $campaign = adEspeesCampaign($owner);
    $transaction = adEspeesTransaction($campaign, [
        'local_reference' => 'ADS-SUCCESS-123',
        'provider_reference' => 'AD-PAYREF-OK',
    ]);

    Http::fake([
        'https://api.espees.org/payment/confirm' => Http::response(adEspeesApprovedPayload($transaction)),
    ]);

    $this->actingAs($owner)
        ->get(route('advertise.campaigns.payment.success', $transaction))
        ->assertRedirect(route('advertise') . '#advertise-campaign-builder');

    $campaign->refresh();
    $transaction->refresh();

    expect($transaction->gateway_status)->toBe('submitted')
        ->and($transaction->review_status)->toBe('approved')
        ->and($campaign->status)->toBe(AdCampaign::STATUS_PAID_PENDING_REVIEW)
        ->and($campaign->payment_reference)->toBe('AD-PAYREF-OK')
        ->and($campaign->payment_confirmed_at)->not->toBeNull();

    Notification::assertSentTo($admin, AdminAdCampaignSubmittedNotification::class);
});

test('ad Espees confirmation mismatch does not mark campaign paid', function (): void {
    Notification::fake();

    User::factory()->create(['role' => UserRole::ADMIN, 'email_verified_at' => now()]);
    $owner = adEspeesOwner();
    $campaign = adEspeesCampaign($owner);
    $transaction = adEspeesTransaction($campaign);

    Http::fake([
        'https://api.espees.org/payment/confirm' => Http::response(array_merge(adEspeesApprovedPayload($transaction), [
            'product_sku' => 'OTHER-SKU',
        ])),
    ]);

    $this->actingAs($owner)
        ->get(route('advertise.campaigns.payment.success', $transaction))
        ->assertRedirect(route('advertise') . '#advertise-campaign-builder');

    expect($campaign->fresh()->status)->toBe(AdCampaign::STATUS_PAYMENT_FAILED)
        ->and($transaction->fresh()->gateway_status)->toBe('verification_failed');

    Notification::assertNothingSent();
});

test('non-approved ad Espees statuses do not mark campaign paid', function (string $status, string $expectedGatewayStatus): void {
    $owner = adEspeesOwner();
    $campaign = adEspeesCampaign($owner);
    $transaction = adEspeesTransaction($campaign);

    Http::fake([
        'https://api.espees.org/payment/confirm' => Http::response(array_merge(adEspeesApprovedPayload($transaction), [
            'transaction_status' => $status,
        ])),
    ]);

    $this->actingAs($owner)
        ->get(route('advertise.campaigns.payment.success', $transaction))
        ->assertRedirect(route('advertise') . '#advertise-campaign-builder');

    expect($campaign->fresh()->status)->toBe(AdCampaign::STATUS_PAYMENT_FAILED)
        ->and($transaction->fresh()->gateway_status)->toBe($expectedGatewayStatus);
})->with([
    ['DECLINE', 'failed'],
    ['PENDING', 'pending'],
    ['NOT FOUND', 'failed'],
]);

test('duplicate ad Espees success callback is idempotent', function (): void {
    Notification::fake();

    $admin = User::factory()->create(['role' => UserRole::ADMIN, 'email_verified_at' => now()]);
    $owner = adEspeesOwner();
    $campaign = adEspeesCampaign($owner);
    $transaction = adEspeesTransaction($campaign);

    Http::fake([
        'https://api.espees.org/payment/confirm' => Http::response(adEspeesApprovedPayload($transaction)),
    ]);

    $this->actingAs($owner)->get(route('advertise.campaigns.payment.success', $transaction));
    $this->actingAs($owner)->get(route('advertise.campaigns.payment.success', $transaction));

    Notification::assertSentToTimes($admin, AdminAdCampaignSubmittedNotification::class, 1);
    Http::assertSentCount(1);
});

test('another user cannot pay or complete another advertisers campaign', function (): void {
    Http::fake();

    $owner = adEspeesOwner();
    $other = adEspeesOwner();
    $campaign = adEspeesCampaign($owner);
    $transaction = adEspeesTransaction($campaign);

    $this->actingAs($other)
        ->post(route('advertise.campaigns.pay', $campaign))
        ->assertForbidden();

    $this->actingAs($other)
        ->get(route('advertise.campaigns.payment.success', $transaction))
        ->assertForbidden();

    Http::assertNothingSent();
});
