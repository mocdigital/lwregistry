<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Livewire\SboFees;
use App\Models\Sbo;
use App\Models\SboFeeSetting;
use App\Models\SboPaymentTransaction;
use App\Models\User;
use App\Notifications\AdminEspeesPaymentFailedNotification;
use App\Notifications\AdminSboPaymentSuccessfulNotification;
use App\Notifications\SboFeesPaidNotification;
use App\Support\EspeesTestAccount;
use App\Support\SboFeeCalculator;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    config(['espees.test_account.enabled' => true]);

    SboFeeCalculator::flushCache();
    SboFeeSetting::set('currency', 'Espees');
    SboFeeSetting::set('espees_enabled', '1');
    SboFeeSetting::set('espees_token', 'TOKEN-TEST');
    SboFeeSetting::set('espees_merchant_wallet', 'WALLET-TEST');
    SboFeeSetting::set('espees_test_mode', '1');

    $this->user = User::factory()->create([
        'email' => EspeesTestAccount::username(),
        'role' => UserRole::SBO_MANAGER,
    ]);

    $this->sbo = Sbo::create([
        'name' => 'Espees Test Organization ' . Str::random(5),
        'slug' => 'espees-test-' . Str::lower(Str::random(8)),
        'created_by' => $this->user->id,
        'verification_status' => 'approved',
        'documents_status' => 'pending',
        'payment_status' => 'unpaid',
        'calculated_fee' => null,
        'share_structure' => null,
    ]);
});

afterEach(function (): void {
    app()->instance('env', 'testing');
    config([
        'espees.test_account.enabled' => false,
        'espees.test_account.allow_production' => false,
    ]);
});

function testSboTransaction(Sbo $sbo, array $overrides = []): SboPaymentTransaction
{
    return SboPaymentTransaction::create(array_merge([
        'sbo_id' => $sbo->id,
        'provider' => 'espees',
        'local_reference' => 'ESPEES-TEST-' . Str::upper(Str::random(10)),
        'provider_reference' => 'PAYREF-TEST',
        'amount' => EspeesTestAccount::PAYMENT_AMOUNT,
        'currency' => 'Espees',
        'gateway_status' => 'redirected',
        'review_status' => 'none',
    ], $overrides));
}

test('the dedicated test account sees the one-Espee checkout option', function (): void {
    Livewire::actingAs($this->user)
        ->test(SboFees::class, ['sboId' => $this->sbo->id])
        ->assertSee('Espees test checkout')
        ->assertSee('Pay 1 Espee with Espees')
        ->assertSee('will charge exactly')
        ->assertSee('1 Espee')
        ->assertDontSee('Share structure')
        ->assertDontSee('Manual payment');
});

test('the one-Espee option is hidden when the test-account flag is disabled', function (): void {
    config(['espees.test_account.enabled' => false]);

    Livewire::actingAs($this->user)
        ->test(SboFees::class, ['sboId' => $this->sbo->id])
        ->assertDontSee('Espees test checkout')
        ->assertDontSee('Pay 1 Espee with Espees');
});

test('the production triage opt-in permits only the dedicated account to use the gateway', function (): void {
    config([
        'espees.test_account.enabled' => true,
        'espees.test_account.allow_production' => true,
    ]);
    app()->instance('env', 'production');
    SboFeeSetting::set('espees_test_mode', '0');
    SboFeeCalculator::flushCache();

    expect(EspeesTestAccount::enabled())->toBeTrue()
        ->and(EspeesTestAccount::canUseCheckout($this->user))->toBeTrue();
});

/*
 * How the browser is handed off to payment.espees.org.
 *
 * The button posts over Inertia's XHR, and a 302 inside an XHR is followed by
 * the request rather than the address bar — the follow-up went cross-origin to
 * payment.espees.org, the browser blocked it for want of CORS headers, and the
 * checkout was created and then thrown away. The applicant saw nothing happen.
 *
 * Inertia::location() serves both callers: 409 + X-Inertia-Location for an
 * Inertia request, an ordinary redirect for anything else — which is what the
 * native-form fallback on the button relies on.
 */
test('an Inertia request is sent to the checkout by location header', function (): void {
    Http::fake([
        'https://api.espees.org/payment/product' => Http::response([
            'statusCode' => 200,
            'payment_ref' => 'PAYREF-INERTIA',
        ]),
    ]);

    $this->actingAs($this->user)
        ->post(route('sbo.payments.espees.start', $this->sbo), [], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://payment.espees.org/pay/PAYREF-INERTIA');
});

test('a plain form post is sent to the checkout by ordinary redirect', function (): void {
    Http::fake([
        'https://api.espees.org/payment/product' => Http::response([
            'statusCode' => 200,
            'payment_ref' => 'PAYREF-NATIVE',
        ]),
    ]);

    // The fallback path: no X-Inertia header, so the browser is doing the
    // navigating and a 409 would strand it on a blank page.
    $this->actingAs($this->user)
        ->post(route('sbo.payments.espees.start', $this->sbo))
        ->assertRedirect('https://payment.espees.org/pay/PAYREF-NATIVE');
});

test('retrying resumes the checkout already in flight rather than opening a second', function (): void {
    Http::fake([
        'https://api.espees.org/payment/product' => Http::response([
            'statusCode' => 200,
            'payment_ref' => 'PAYREF-FIRST',
        ]),
    ]);

    $this->actingAs($this->user)
        ->post(route('sbo.payments.espees.start', $this->sbo), [], ['X-Inertia' => 'true'])
        ->assertStatus(409);

    /*
     * The fallback fires exactly when the applicant has already posted once,
     * so the second attempt MUST land on the same payment_ref. Espees would
     * hand back a different one if asked again, so a fresh product here would
     * mean two live checkouts against one fee — and the chance of paying both.
     */
    Http::fake([
        'https://api.espees.org/payment/product' => Http::response([
            'statusCode' => 200,
            'payment_ref' => 'PAYREF-SECOND',
        ]),
    ]);

    $this->actingAs($this->user)
        ->post(route('sbo.payments.espees.start', $this->sbo))
        ->assertRedirect('https://payment.espees.org/pay/PAYREF-FIRST');

    expect(SboPaymentTransaction::query()->count())->toBe(1);

    // Nothing was asked of Espees the second time round.
    Http::assertNothingSent();
});

test('a failure to start is still an ordinary redirect carrying the reason', function (): void {
    Http::fake([
        'https://api.espees.org/payment/product' => Http::response([], 500),
    ]);

    /*
     * Only the hand-off is a location response. An error stays a normal
     * redirect back into the app — a 409 would send the browser out of it —
     * so the flash renders and the applicant is told what went wrong.
     */
    $this->actingAs($this->user)
        ->post(route('sbo.payments.espees.start', $this->sbo), [], ['X-Inertia' => 'true'])
        ->assertHeaderMissing('X-Inertia-Location')
        ->assertRedirect()
        ->assertSessionHas('fees_error');
});

test('the applicant is never shown the gateway\'s own error', function (): void {
    Http::fake([
        'https://api.espees.org/payment/product' => Http::response(['message' => 'Forbidden'], 403),
    ]);

    /*
     * The diagnostics — status, body, the request we sent — go to the
     * transaction row and the admin alert; "checkout failure records Espees
     * provider diagnostics" above covers those. What the APPLICANT gets is the
     * one line they can act on: try again, or pay manually.
     */
    $this->actingAs($this->user)
        ->post(route('sbo.payments.espees.start', $this->sbo))
        ->assertSessionHas('fees_error', 'Espees checkout could not be started. Please try again shortly.');
});

/*
 * The shape of the live merchant API, pinned against what it actually does.
 *
 * Three separate mistakes stopped checkout from ever starting, and each one
 * masked the next:
 *
 *  1. The endpoints carried a /v2 prefix. That is a different API Gateway
 *     stage which answers 403 {"message":"Forbidden"} to everything — the same
 *     response for a valid key, a bogus key and no key — so the failure was
 *     indistinguishable from an expired merchant token.
 *  2. Product creation returns `productid`, which was not among the reference
 *     aliases, so a successful call was read as "Espees did not return a
 *     payment reference".
 *  3. Confirmation reads `product_id` from the request body; `payment_ref`
 *     came back as a KeyError from the function behind the endpoint.
 */
test('the unversioned merchant endpoints are the ones called', function (): void {
    Http::fake([
        'https://api.espees.org/payment/product' => Http::response([
            'statusCode' => 200,
            'productid' => 'de5298f5e20ac2890b065621a4bdc7b5',
            'message' => 'Successfully Done',
        ]),
    ]);

    $this->actingAs($this->user)
        ->post(route('sbo.payments.espees.start', $this->sbo))
        ->assertRedirect('https://payment.espees.org/pay/de5298f5e20ac2890b065621a4bdc7b5');

    Http::assertSent(fn (HttpRequest $request): bool => 'https://api.espees.org/payment/product' === $request->url()
        && ! str_contains($request->url(), '/v2/'));
});

test('the payment reference is read from productid', function (): void {
    // The only key the live API returns it under, and the one the /pay/{ref}
    // checkout URL takes.
    Http::fake([
        'https://api.espees.org/payment/product' => Http::response([
            'statusCode' => 200,
            'productid' => 'abc123def456',
            'message' => 'Successfully Done',
        ]),
    ]);

    $this->actingAs($this->user)
        ->post(route('sbo.payments.espees.start', $this->sbo));

    expect(SboPaymentTransaction::query()->latest('id')->value('provider_reference'))
        ->toBe('abc123def456');
});

test('confirmation asks by product_id', function (): void {
    $transaction = testSboTransaction($this->sbo, [
        'provider_reference' => 'abc123def456',
        'gateway_status' => 'redirected',
        'amount' => 1,
    ]);

    Http::fake([
        'https://api.espees.org/payment/confirm' => Http::response([
            'transaction_status' => 'NOT FOUND',
        ]),
    ]);

    $this->actingAs($this->user)
        ->get(route('sbo.payments.espees.success', $transaction));

    Http::assertSent(function (HttpRequest $request): bool {
        $data = $request->data();

        // `payment_ref` here is a KeyError from the endpoint's own code.
        return 'https://api.espees.org/payment/confirm' === $request->url()
            && ($data['product_id'] ?? null) === 'abc123def456'
            && ! array_key_exists('payment_ref', $data);
    });
});

test('the test checkout creates an Espees product for exactly one Espee', function (): void {
    Http::fake([
        'https://api.espees.org/payment/product' => Http::response([
            'statusCode' => 200,
            'payment_ref' => 'PAYREF-ONE-ESPEE',
        ]),
    ]);

    $this->actingAs($this->user)
        ->post(route('sbo.payments.espees.start', $this->sbo))
        ->assertRedirect('https://payment.espees.org/pay/PAYREF-ONE-ESPEE');

    $transaction = SboPaymentTransaction::query()->firstOrFail();

    expect($transaction->amount)->toBe(1)
        ->and($transaction->gateway_status)->toBe('redirected')
        ->and($transaction->provider_reference)->toBe('PAYREF-ONE-ESPEE');

    Http::assertSent(function (HttpRequest $request) use ($transaction): bool {
        $payload = $request->data();

        return $request->hasHeader('x-api-key', 'TOKEN-TEST')
            && $payload['product_sku'] === $transaction->local_reference
            && 1 === $payload['price']
            && 'WALLET-TEST' === $payload['merchant_wallet']
            && true === $payload['user_data']['test_account']
            && 1 === $payload['user_data']['test_amount'];
    });
});

test('checkout honors https proxy headers for Espees callbacks', function (): void {
    Http::fake([
        'https://api.espees.org/payment/product' => Http::response([
            'statusCode' => 200,
            'payment_ref' => 'PAYREF-PROXY-HTTPS',
        ]),
    ]);

    $this
        ->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.10',
            'HTTP_HOST' => 'registrytest.lwcommerce.org',
            'HTTP_X_FORWARDED_HOST' => 'registrytest.lwcommerce.org',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PORT' => '443',
            'SERVER_PORT' => '80',
            'HTTPS' => 'off',
        ])
        ->actingAs($this->user)
        ->post('/sbo/applications/' . $this->sbo->id . '/payments/espees/start')
        ->assertRedirect('https://payment.espees.org/pay/PAYREF-PROXY-HTTPS');

    Http::assertSent(function (HttpRequest $request): bool {
        $payload = $request->data();

        return str_starts_with($payload['success_url'], 'https://registrytest.lwcommerce.org/sbo/payments/espees/')
            && str_ends_with($payload['success_url'], '/success')
            && str_starts_with($payload['fail_url'], 'https://registrytest.lwcommerce.org/sbo/payments/espees/')
            && str_ends_with($payload['fail_url'], '/failure');
    });
});

test('checkout accepts Espees payment reference aliases', function (): void {
    Http::fake([
        'https://api.espees.org/payment/product' => Http::response([
            'statusCode' => 200,
            'data' => [
                'payment_reference' => 'PAYREF-NESTED-ALIAS',
            ],
        ]),
    ]);

    $this->actingAs($this->user)
        ->post(route('sbo.payments.espees.start', $this->sbo))
        ->assertRedirect('https://payment.espees.org/pay/PAYREF-NESTED-ALIAS');

    $transaction = SboPaymentTransaction::query()->firstOrFail();

    expect($transaction->provider_reference)->toBe('PAYREF-NESTED-ALIAS')
        ->and($transaction->gateway_status)->toBe('redirected');
});

test('checkout failure records Espees provider diagnostics', function (): void {
    Notification::fake();

    Http::fake([
        'https://api.espees.org/payment/product' => Http::response([
            'message' => 'Invalid callback URL',
        ], 422),
    ]);

    $this->actingAs($this->user)
        ->post(route('sbo.payments.espees.start', $this->sbo))
        ->assertRedirect(route('application.show', ['step' => 'fees']));

    $transaction = SboPaymentTransaction::query()->firstOrFail();
    $payload = $transaction->raw_payload;

    expect($transaction->gateway_status)->toBe('failed')
        ->and($payload['product_error'])->toContain('Invalid callback URL')
        ->and($payload['product_request']['merchant_wallet'])->toBe('WALLET-TEST')
        ->and($payload['product_error_context']['response_status'])->toBe(422)
        ->and($payload['product_error_context']['response_json']['message'])->toBe('Invalid callback URL');
});

test('an approved one-Espee confirmation marks the test SBO paid', function (): void {
    Notification::fake();
    $superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);

    $transaction = testSboTransaction($this->sbo, ['local_reference' => 'ESPEES-TEST-APPROVED']);

    Http::fake([
        'https://api.espees.org/payment/confirm' => Http::response([
            'transaction_status' => 'APPROVED',
            'product_sku' => $transaction->local_reference,
            'price' => 1,
        ]),
    ]);

    /*
     * Forward to Step 3, not back to the payment screen. Returning a payer to
     * the step they just completed made them hunt for the next one; the
     * destination is now whichever step the application is actually on.
     */
    $this->actingAs($this->user)
        ->get(route('sbo.payments.espees.success', $transaction))
        ->assertRedirect(route('application.show', ['step' => 'documents']));

    expect($this->sbo->fresh()->feesPaid())->toBeTrue()
        ->and($this->sbo->fresh()->calculated_fee)->toBeNull()
        ->and($transaction->fresh()->review_status)->toBe('approved')
        ->and($transaction->fresh()->gateway_status)->toBe('submitted');

    Notification::assertSentTo($this->user, SboFeesPaidNotification::class);
    Notification::assertSentTo($superAdmin, AdminSboPaymentSuccessfulNotification::class);
    Notification::assertNotSentTo($admin, AdminSboPaymentSuccessfulNotification::class);
});

test('a mismatched provider amount cannot mark the test SBO paid', function (): void {
    Notification::fake();

    // AdminRecipients falls back to an anonymous mail route when no admin
    // exists, so one is created here to have a notifiable to assert against.
    $admin = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);

    $transaction = testSboTransaction($this->sbo, ['local_reference' => 'ESPEES-TEST-MISMATCH']);

    Http::fake([
        'https://api.espees.org/payment/confirm' => Http::response([
            'transaction_status' => 'APPROVED',
            'product_sku' => $transaction->local_reference,
            'price' => 25,
        ]),
    ]);

    // The payment did not clear, so the fee is still outstanding and the
    // resolved step is still `fees`.
    $this->actingAs($this->user)
        ->get(route('sbo.payments.espees.success', $transaction))
        ->assertRedirect(route('application.show', ['step' => 'fees']));

    expect($this->sbo->fresh()->feesPaid())->toBeFalse()
        ->and($transaction->fresh()->gateway_status)->toBe('verification_failed');

    /*
     * The owner is told nothing that implies they have paid — no receipt, no
     * fees-paid notice. What used to be asserted here is that *nothing at all*
     * was sent, but a provider reporting a different amount than we charged is
     * exactly the case an admin needs to hear about, so the mismatch now raises
     * an alert instead of failing silently.
     */
    Notification::assertNotSentTo($this->user, SboFeesPaidNotification::class);
    Notification::assertNotSentTo($this->user, AdminSboPaymentSuccessfulNotification::class);
    Notification::assertSentTo($admin, AdminEspeesPaymentFailedNotification::class);
});

test('a normal user cannot access another owner’s Espees checkout route', function (): void {
    Http::fake();
    $normalUser = User::factory()->create(['role' => UserRole::SBO_MANAGER]);
    Sbo::create([
        'name' => 'Normal User Organization',
        'slug' => 'normal-user-' . Str::lower(Str::random(8)),
        'created_by' => $normalUser->id,
        'verification_status' => 'approved',
        'payment_status' => 'unpaid',
    ]);

    $this->actingAs($normalUser)
        ->post(route('sbo.payments.espees.start', $this->sbo))
        ->assertForbidden();

    Http::assertNothingSent();
});

test('a normal unpaid SBO owner starts checkout for the calculated registration fee', function (): void {
    Http::fake([
        'https://api.espees.org/payment/product' => Http::response([
            'statusCode' => 200,
            'payment_ref' => 'PAYREF-NORMAL-250',
        ]),
    ]);

    $owner = User::factory()->create(['role' => UserRole::SBO_MANAGER]);
    $sbo = Sbo::create([
        'name' => 'Normal Espees Organization',
        'slug' => 'normal-espees-' . Str::lower(Str::random(8)),
        'created_by' => $owner->id,
        'verification_status' => 'approved',
        'payment_status' => 'unpaid',
        'calculated_fee' => 250,
        'share_structure' => 'no_shares',
    ]);

    $this->actingAs($owner)
        ->post(route('sbo.payments.espees.start', $sbo))
        ->assertRedirect('https://payment.espees.org/pay/PAYREF-NORMAL-250');

    $transaction = $sbo->paymentTransactions()->firstOrFail();

    expect($transaction->amount)->toBe(250)
        ->and($transaction->raw_payload['product_request']['price'])->toBe(250)
        ->and($transaction->raw_payload['product_request']['user_data']['test_account'])->toBeFalse();
});

test('a normal approved Espees payment confirms the calculated registration fee', function (): void {
    Notification::fake();

    $owner = User::factory()->create(['role' => UserRole::SBO_MANAGER]);
    $sbo = Sbo::create([
        'name' => 'Normal Approved Organization',
        'slug' => 'normal-approved-' . Str::lower(Str::random(8)),
        'created_by' => $owner->id,
        'verification_status' => 'approved',
        'payment_status' => 'unpaid',
        'calculated_fee' => 250,
        'share_structure' => 'no_shares',
    ]);
    $transaction = SboPaymentTransaction::create([
        'sbo_id' => $sbo->id,
        'provider' => 'espees',
        'local_reference' => 'ESPEES-NORMAL-APPROVED',
        'provider_reference' => 'PAYREF-NORMAL-APPROVED',
        'amount' => 250,
        'currency' => 'Espees',
        'gateway_status' => 'redirected',
        'review_status' => 'none',
    ]);

    Http::fake([
        'https://api.espees.org/payment/confirm' => Http::response([
            'transaction_status' => 'APPROVED',
            'product_sku' => $transaction->local_reference,
            'price' => 250,
        ]),
    ]);

    $this->actingAs($owner)
        ->get(route('sbo.payments.espees.success', $transaction))
        ->assertRedirect(route('application.show', ['step' => 'documents']));

    expect($sbo->fresh()->feesPaid())->toBeTrue()
        ->and($transaction->fresh()->gateway_status)->toBe('submitted');

    Notification::assertSentTo($owner, SboFeesPaidNotification::class);
});
