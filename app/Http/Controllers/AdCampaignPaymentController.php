<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AdCampaign;
use App\Models\AdPaymentTransaction;
use App\Notifications\AdminAdCampaignSubmittedNotification;
use App\Support\AdminRecipients;
use App\Support\EspeesGatewayException;
use App\Support\EspeesMerchantGateway;
use App\Support\SboFeeCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

final class AdCampaignPaymentController extends Controller
{
    public function start(Request $request, AdCampaign $campaign, EspeesMerchantGateway $espees): RedirectResponse
    {
        $this->authorizeOwner($request, $campaign);

        if ($campaign->isPaid()) {
            return $this->backWith('advertising_campaign_success', 'This campaign payment has already been confirmed.');
        }

        if ( ! in_array($campaign->status, [AdCampaign::STATUS_PENDING_PAYMENT, AdCampaign::STATUS_PAYMENT_FAILED], true)) {
            return $this->backWith('advertising_campaign_error', 'This campaign is not ready for payment.');
        }

        $gateway = SboFeeCalculator::espeesGateway();

        if ( ! $gateway['configured']) {
            return $this->backWith('advertising_campaign_error', 'Espees checkout is not configured yet. Please contact support.');
        }

        $transaction = AdPaymentTransaction::create([
            'ad_campaign_id' => $campaign->id,
            'provider' => 'espees',
            'local_reference' => $this->reference($campaign),
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
            $transaction->update([
                'gateway_status' => 'failed',
                'raw_payload' => $this->gatewayFailurePayload($exception, 'product'),
            ]);

            $campaign->update(['status' => AdCampaign::STATUS_PAYMENT_FAILED]);

            return $this->backWith('advertising_campaign_error', 'Espees checkout could not be started. Please try again shortly.');
        }

        $transaction->update([
            'provider_reference' => $payment['payment_ref'],
            'gateway_status' => 'redirected',
            'raw_payload' => [
                'product_request' => $payment['payload'],
                'product_response' => $payment['response'],
            ],
        ]);

        return redirect()->away($espees->paymentUrl($payment['payment_ref']));
    }

    public function success(Request $request, AdPaymentTransaction $transaction, EspeesMerchantGateway $espees): RedirectResponse
    {
        $transaction->loadMissing(['campaign.owner']);
        $campaign = $transaction->campaign;

        abort_unless($campaign instanceof AdCampaign, 404);

        $this->authorizeOwner($request, $campaign);

        abort_if('espees' !== $transaction->provider, 404);

        if ($transaction->isApproved() || $campaign->isPaid()) {
            return $this->backWith('advertising_campaign_success', 'Campaign payment confirmed. Your ad is awaiting admin review.');
        }

        $gateway = SboFeeCalculator::espeesGateway();

        if ( ! $gateway['configured']) {
            $this->mergePayload($transaction, ['redirect_query' => $request->query()]);

            return $this->backWith('advertising_campaign_error', 'Espees checkout is not configured yet. Please contact support.');
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

            $campaign->update(['status' => AdCampaign::STATUS_PAYMENT_FAILED]);

            return $this->backWith('advertising_campaign_error', 'We could not verify your Espees payment yet. Please try again shortly.');
        }

        $transaction->update([
            'raw_payload' => $this->payloadWith($transaction, [
                'redirect_query' => $request->query(),
                'confirm_response' => $confirmation,
            ]),
        ]);

        if ( ! $this->confirmationIsApproved($transaction, $confirmation)) {
            $transaction->update(['gateway_status' => $this->gatewayStatusForConfirmation($confirmation)]);
            $campaign->update(['status' => AdCampaign::STATUS_PAYMENT_FAILED]);

            return $this->backWith('advertising_campaign_error', 'Espees has not approved this campaign payment yet. Please retry once payment is complete.');
        }

        $submittedAt = $transaction->submitted_at ?? now();

        $transaction->update([
            'gateway_status' => 'submitted',
            'review_status' => 'approved',
            'submitted_at' => $submittedAt,
            'reviewed_at' => now(),
        ]);

        $campaign->update([
            'status' => AdCampaign::STATUS_PAID_PENDING_REVIEW,
            'payment_reference' => $transaction->provider_reference ?: $transaction->local_reference,
            'payment_confirmed_at' => $submittedAt,
        ]);

        $campaign->refresh();
        AdminRecipients::notify(new AdminAdCampaignSubmittedNotification($campaign));

        return $this->backWith('advertising_campaign_success', 'Campaign payment confirmed. Your ad is now awaiting admin review.');
    }

    public function failure(Request $request, AdPaymentTransaction $transaction): RedirectResponse
    {
        $transaction->loadMissing(['campaign']);
        $campaign = $transaction->campaign;

        abort_unless($campaign instanceof AdCampaign, 404);

        $this->authorizeOwner($request, $campaign);

        abort_if('espees' !== $transaction->provider, 404);

        if ( ! $transaction->isApproved()) {
            $transaction->update([
                'provider_reference' => $this->providerReference($request) ?: $transaction->provider_reference,
                'gateway_status' => 'failed',
                'raw_payload' => $this->payloadWith($transaction, [
                    'failure_query' => $request->query(),
                ]),
            ]);

            $campaign->update(['status' => AdCampaign::STATUS_PAYMENT_FAILED]);
        }

        return $this->backWith('advertising_campaign_error', 'Espees payment was not completed. You can try again from your campaign list.');
    }

    private function authorizeOwner(Request $request, AdCampaign $campaign): void
    {
        abort_unless((int) $campaign->user_id === (int) $request->user()?->id, 403);
    }

    private function backWith(string $key, string $message): RedirectResponse
    {
        return redirect()
            ->to(route('advertise') . '#advertise-campaign-builder')
            ->with($key, $message);
    }

    private function reference(AdCampaign $campaign): string
    {
        do {
            $reference = 'ADS-' . $campaign->id . '-' . Str::upper(Str::random(8));
        } while (AdPaymentTransaction::query()->where('local_reference', $reference)->exists());

        return $reference;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function mergePayload(AdPaymentTransaction $transaction, array $extra): void
    {
        $transaction->update([
            'raw_payload' => $this->payloadWith($transaction, $extra),
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payloadWith(AdPaymentTransaction $transaction, array $extra): array
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
    private function confirmationIsApproved(AdPaymentTransaction $transaction, array $confirmation): bool
    {
        $status = $confirmation['transaction_status'] ?? null;
        $sku = $confirmation['product_sku'] ?? null;
        $price = $confirmation['price'] ?? null;

        if ('APPROVED' !== mb_strtoupper((string) $status)) {
            return false;
        }

        if ($sku !== $transaction->local_reference) {
            return false;
        }

        if ( ! is_numeric($price)) {
            return false;
        }

        return abs((float) $price - (float) $transaction->amount) < 0.01;
    }

    /**
     * @param  array<string, mixed>  $confirmation
     */
    private function gatewayStatusForConfirmation(array $confirmation): string
    {
        $status = mb_strtoupper((string) ($confirmation['transaction_status'] ?? ''));

        return match ($status) {
            'PENDING' => 'pending',
            'DECLINE', 'NOT FOUND' => 'failed',
            default => 'verification_failed',
        };
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
}
