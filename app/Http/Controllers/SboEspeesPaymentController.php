<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Sbo;
use App\Models\SboPaymentTransaction;
use App\Notifications\AdminEspeesPaymentFailedNotification;
use App\Support\AdminRecipients;
use App\Support\EspeesGatewayException;
use App\Support\EspeesMerchantGateway;
use App\Support\EspeesTestAccount;
use App\Support\SboApplicationProgressBar;
use App\Support\SboFeeCalculator;
use App\Support\SboPaymentConfirmer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

final class SboEspeesPaymentController extends Controller
{
    /**
     * Every failure path still returns a plain RedirectResponse so the flash
     * message renders in place; only the hand-off to Espees is a location
     * response. See toCheckout().
     */
    public function start(Request $request, Sbo $sbo, EspeesMerchantGateway $espees): RedirectResponse|SymfonyResponse
    {
        $this->authorizeOwner($request, $sbo);

        $isTestAccount = EspeesTestAccount::matches($request->user());

        if ($sbo->feesPaid()) {
            return $this->backWith($sbo, 'fees_success', 'This registration-fee payment has already been confirmed.');
        }

        if (null === $sbo->calculated_fee && ! $isTestAccount) {
            return $this->backWith($sbo, 'fees_error', 'Confirm the registration fee before starting Espees checkout.');
        }

        $gateway = SboFeeCalculator::espeesGateway($sbo->country_id);
        $gatewayMayRun = $isTestAccount
            ? EspeesTestAccount::gatewayMayRun($gateway)
            : $gateway['configured'];

        if ( ! $gatewayMayRun) {
            return $this->backWith($sbo, 'fees_error', 'Espees checkout is not configured yet. Please contact support.');
        }

        $amount = $isTestAccount ? EspeesTestAccount::PAYMENT_AMOUNT : (int) $sbo->calculated_fee;

        if ($amount < 1) {
            return $this->backWith($sbo, 'fees_error', 'The registration fee must be greater than zero before starting Espees checkout.');
        }

        /** @var SboPaymentTransaction|null $existing */
        $existing = $sbo->paymentTransactions()
            ->where('provider', 'espees')
            ->whereIn('gateway_status', ['initiated', 'redirected', 'pending'])
            ->latest('id')
            ->first();

        /*
         * Resume rather than start again. A second attempt — the browser back
         * button, a double click, or the native-form fallback retrying after
         * the Inertia post failed — must reach the SAME checkout, or the
         * applicant ends up with two live payment_refs and can be charged
         * twice. This is the property the client-side fallback relies on.
         */
        if ($existing?->provider_reference) {
            return $this->toCheckout($espees->paymentUrl($existing->provider_reference));
        }

        /** @var SboPaymentTransaction $transaction */
        $transaction = $sbo->paymentTransactions()->create([
            'provider' => 'espees',
            'local_reference' => $this->localReference($sbo, $isTestAccount),
            'amount' => $amount,
            'currency' => SboFeeCalculator::currency($sbo->country_id),
            'gateway_status' => 'initiated',
            'review_status' => 'none',
        ]);

        try {
            $payment = $espees->createProduct(
                gateway: $gateway,
                localReference: $transaction->local_reference,
                narration: $isTestAccount
                    ? 'SBO registration fee (test) - ' . $sbo->name
                    : 'SBO registration fee - ' . $sbo->name,
                amount: $transaction->amount,
                successUrl: route('sbo.payments.espees.success', $transaction),
                failUrl: route('sbo.payments.espees.failure', $transaction),
                userData: [
                    'sbo_id' => $sbo->id,
                    'application_id' => $sbo->application_id,
                    'owner_id' => $sbo->created_by,
                    'local_reference' => $transaction->local_reference,
                    'test_account' => $isTestAccount,
                    'test_amount' => $isTestAccount ? EspeesTestAccount::PAYMENT_AMOUNT : null,
                ],
            );
        } catch (Throwable $exception) {
            $transaction->update([
                'gateway_status' => 'failed',
                'raw_payload' => $this->gatewayFailurePayload($exception, 'product'),
            ]);

            $this->notifyFailure($sbo, 'checkout_setup', $exception->getMessage(), $transaction);

            return $this->backWith($sbo, 'fees_error', 'Espees checkout could not be started. Please try again shortly.');
        }

        $transaction->update([
            'provider_reference' => $payment['payment_ref'],
            'gateway_status' => 'redirected',
            'raw_payload' => [
                'product_request' => $payment['payload'],
                'product_response' => $payment['response'],
            ],
        ]);

        return $this->toCheckout($espees->paymentUrl($payment['payment_ref']));
    }

    public function success(
        Request $request,
        SboPaymentTransaction $transaction,
        EspeesMerchantGateway $espees,
        SboPaymentConfirmer $confirmer,
    ): RedirectResponse {
        $transaction->loadMissing(['sbo.creator']);
        $sbo = $transaction->sbo;
        abort_unless($sbo instanceof Sbo, 404);

        $this->authorizeOwner($request, $sbo);
        abort_if('espees' !== $transaction->provider, 404);

        if ($transaction->isApproved() || $sbo->feesPaid()) {
            return $this->backWith($sbo, 'fees_success', 'Espees payment confirmed.');
        }

        $gateway = SboFeeCalculator::espeesGateway($sbo->country_id);
        $isTestAccount = EspeesTestAccount::matches($request->user());
        $gatewayMayRun = $isTestAccount
            ? EspeesTestAccount::gatewayMayRun($gateway)
            : $gateway['configured'];

        if ( ! $gatewayMayRun) {
            $this->mergePayload($transaction, ['redirect_query' => $request->query()]);

            return $this->backWith($sbo, 'fees_error', 'Espees checkout is not configured yet. Please contact support.');
        }

        try {
            $confirmation = $espees->confirmPaymentReference($transaction->provider_reference, $gateway);
        } catch (Throwable $exception) {
            $transaction->update([
                'gateway_status' => 'verification_failed',
                'raw_payload' => $this->payloadWith($transaction, [
                    'redirect_query' => $request->query(),
                    'confirm_error' => $exception->getMessage(),
                ] + $this->gatewayFailureContext($exception, 'confirm')),
            ]);

            $this->notifyFailure($sbo, 'confirmation', $exception->getMessage(), $transaction);

            return $this->backWith($sbo, 'fees_error', 'We could not verify your Espees payment yet. Please try again shortly.');
        }

        $this->mergePayload($transaction, [
            'redirect_query' => $request->query(),
            'confirm_response' => $confirmation,
        ]);

        if ( ! $this->confirmationIsApproved($transaction, $confirmation)) {
            $status = mb_strtoupper((string) ($confirmation['transaction_status'] ?? ''));
            $gatewayStatus = match ($status) {
                'PENDING' => 'pending',
                'DECLINE', 'NOT FOUND' => 'failed',
                default => 'verification_failed',
            };

            $transaction->update([
                'gateway_status' => $gatewayStatus,
                'review_status' => 'PENDING' === $status ? 'pending' : 'none',
            ]);

            if ('PENDING' !== $status) {
                $this->notifyFailure($sbo, 'confirmation', 'Espees returned ' . ($status ?: 'an unapproved status') . '.', $transaction);
            }

            return $this->backWith($sbo, 'fees_error', 'Espees has not approved this payment yet. Please retry once payment is complete.');
        }

        $submittedAt = $transaction->submitted_at ?? now();

        $transaction->update([
            'gateway_status' => 'submitted',
            'review_status' => 'approved',
            'submitted_at' => $submittedAt,
            'reviewed_at' => now(),
        ]);

        $sbo->update([
            'payment_reference' => $transaction->provider_reference ?: $transaction->local_reference,
            'payment_submitted_at' => $submittedAt,
        ]);

        $confirmer->confirm(
            $sbo->fresh(),
            null,
            $isTestAccount ? 'sbo_espees_payment_approved' : 'sbo_espees_payment_auto_approved',
            ($isTestAccount ? 'Espees test payment approved for <strong>' : 'Espees payment approved for <strong>') . e($sbo->name) . '</strong>.',
            $transaction->amount,
        );

        return $this->backWith(
            $sbo,
            'fees_success',
            $isTestAccount
                ? 'Test payment confirmed: 1 Espee.'
                : 'Espees payment confirmed. Your registration fees are now paid.',
        );
    }

    public function failure(Request $request, SboPaymentTransaction $transaction): RedirectResponse
    {
        $transaction->loadMissing(['sbo']);
        $sbo = $transaction->sbo;
        abort_unless($sbo instanceof Sbo, 404);

        $this->authorizeOwner($request, $sbo);
        abort_if('espees' !== $transaction->provider, 404);

        if ( ! $transaction->isApproved() && ! $sbo->feesPaid()) {
            $transaction->update([
                'provider_reference' => $this->providerReference($request) ?: $transaction->provider_reference,
                'gateway_status' => 'failed',
                'raw_payload' => $this->payloadWith($transaction, [
                    'failure_query' => $request->query(),
                ]),
            ]);

            $this->notifyFailure($sbo, 'provider_failure', 'The Espees checkout was not completed.', $transaction);
        }

        return $this->backWith($sbo, 'fees_error', 'Espees payment was not completed. You can try again.');
    }

    /**
     * Hand the browser off to Espees.
     *
     * `redirect()->away()` cannot work here: the button posts over Inertia's
     * XHR, and a 302 inside an XHR is followed by the request itself, never by
     * the address bar. The follow-up is cross-origin to payment.espees.org,
     * which sends no CORS headers for us, so the browser blocked it and the
     * page just sat there — the checkout was created and then thrown away.
     *
     * Inertia::location() answers an Inertia request with 409 and an
     * X-Inertia-Location header, which the client turns into a real
     * window.location visit. For any OTHER request — the native form fallback
     * on the Espees button, or a test calling the route directly — it falls
     * back to Redirect::away() on its own, so both transports are served by
     * this one call.
     */
    private function toCheckout(string $url): RedirectResponse|SymfonyResponse
    {
        return Inertia::location($url);
    }

    private function authorizeOwner(Request $request, Sbo $sbo): void
    {
        abort_unless((int) $sbo->created_by === (int) $request->user()?->id, 403);
    }

    private function localReference(Sbo $sbo, bool $isTestAccount): string
    {
        do {
            $prefix = $isTestAccount ? 'ESPEES-TEST-' : 'ESPEES-';
            $reference = $prefix . $sbo->id . '-' . Str::upper(Str::random(8));
        } while (SboPaymentTransaction::query()->where('local_reference', $reference)->exists());

        return $reference;
    }

    private function backWith(Sbo $sbo, string $key, string $message): RedirectResponse
    {
        /*
         * Back to whichever step the application is on NOW, not to `fees`.
         * A confirmed payment moves it to Step 3, and returning the payer to
         * the payment screen they just completed made them hunt for the next
         * one. `fresh()` because the payment was recorded moments ago and this
         * instance predates it.
         */
        $sbo = $sbo->fresh() ?? $sbo;

        return redirect()
            ->route('application.show', ['step' => SboApplicationProgressBar::currentStep($sbo)])
            ->with($key, $message);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function mergePayload(SboPaymentTransaction $transaction, array $extra): void
    {
        $transaction->update(['raw_payload' => $this->payloadWith($transaction, $extra)]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payloadWith(SboPaymentTransaction $transaction, array $extra): array
    {
        return array_merge($transaction->raw_payload ?? [], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function gatewayFailurePayload(Throwable $exception, string $prefix): array
    {
        return array_merge([
            $prefix . '_error' => $exception->getMessage(),
        ], $this->gatewayFailureContext($exception, $prefix));
    }

    /**
     * @return array<string, mixed>
     */
    private function gatewayFailureContext(Throwable $exception, string $prefix): array
    {
        if ( ! $exception instanceof EspeesGatewayException) {
            return [];
        }

        $context = $exception->context();
        $payload = $context['payload'] ?? null;
        unset($context['payload']);

        $failure = [];

        if (null !== $payload) {
            $failure[$prefix . '_request'] = $payload;
        }

        if ([] !== $context) {
            $failure[$prefix . '_error_context'] = $context;
        }

        return $failure;
    }

    /**
     * @param  array<string, mixed>  $confirmation
     */
    private function confirmationIsApproved(SboPaymentTransaction $transaction, array $confirmation): bool
    {
        if ('APPROVED' !== mb_strtoupper((string) ($confirmation['transaction_status'] ?? ''))) {
            return false;
        }

        if (($confirmation['product_sku'] ?? null) !== $transaction->local_reference) {
            return false;
        }

        return is_numeric($confirmation['price'] ?? null)
            && abs((float) $confirmation['price'] - (float) $transaction->amount) < 0.01;
    }

    private function providerReference(Request $request): ?string
    {
        foreach (['payment_ref', 'reference', 'transaction_reference', 'transaction_ref', 'tx_ref', 'trxref', 'trx_ref', 'ref'] as $key) {
            $value = $request->query($key);

            if (is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
    }

    private function notifyFailure(
        Sbo $sbo,
        string $kind,
        string $reason,
        ?SboPaymentTransaction $transaction = null,
    ): void {
        AdminRecipients::notify(new AdminEspeesPaymentFailedNotification($sbo, $kind, $reason, $transaction));
    }
}
