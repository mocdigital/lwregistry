<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class EspeesMerchantGateway
{
    /**
     * @param  array{token: string, merchant_wallet: string, product_url?: string, payment_url?: string, confirm_url?: string}  $gateway
     * @param  array<string, mixed>  $userData
     * @return array{payment_ref: string, response: array<string, mixed>, payload: array<string, mixed>}
     */
    public function createProduct(
        array $gateway,
        string $localReference,
        string $narration,
        int $amount,
        string $successUrl,
        string $failUrl,
        array $userData = [],
    ): array {
        $payload = [
            'product_sku' => $localReference,
            'narration' => $narration,
            'price' => $amount,
            'merchant_wallet' => $gateway['merchant_wallet'],
            'success_url' => $this->publicCallbackUrl($successUrl),
            'fail_url' => $this->publicCallbackUrl($failUrl),
            'user_data' => $userData,
        ];

        $endpoint = $this->productUrl($gateway);

        try {
            $response = Http::withHeaders([
                'x-api-key' => $gateway['token'],
            ])
                ->asJson()
                ->timeout(15)
                ->post($endpoint, $payload);
        } catch (Throwable $exception) {
            throw new EspeesGatewayException('Espees payment setup request failed.', [
                'operation' => 'create_product',
                'endpoint' => $endpoint,
                'payload' => $payload,
                'network_error' => $exception->getMessage(),
            ], $exception);
        }

        $data = $response->json();

        if ( ! $response->successful() || ! is_array($data)) {
            throw $this->responseException('Espees payment setup failed.', $response, $data, [
                'operation' => 'create_product',
                'endpoint' => $endpoint,
                'payload' => $payload,
            ]);
        }

        $paymentRef = $this->paymentReferenceFrom($data);

        if (null === $paymentRef) {
            throw new EspeesGatewayException('Espees did not return a payment reference.', [
                'operation' => 'create_product',
                'endpoint' => $endpoint,
                'payload' => $payload,
                'response_status' => $response->status(),
                'response_json' => $data,
                'response_body' => $this->bodyExcerpt($response),
            ]);
        }

        return [
            'payment_ref' => $paymentRef,
            'response' => $data,
            'payload' => $payload,
        ];
    }

    /**
     * @param  array{token: string, confirm_url?: string}  $gateway
     * @return array<string, mixed>
     */
    public function confirmPaymentReference(?string $paymentRef, array $gateway): array
    {
        if ( ! is_string($paymentRef) || '' === $paymentRef) {
            throw new EspeesGatewayException('Missing Espees payment reference.', [
                'operation' => 'confirm_payment',
            ]);
        }

        $endpoint = $this->confirmUrl($gateway);

        // `product_id` is the key the confirm endpoint reads; anything else
        // comes back as a KeyError from the function behind it.
        $payload = ['product_id' => $paymentRef];

        try {
            $response = Http::withHeaders([
                'x-api-key' => $gateway['token'],
            ])
                ->asJson()
                ->timeout(15)
                ->post($endpoint, $payload);
        } catch (Throwable $exception) {
            throw new EspeesGatewayException('Espees payment confirmation request failed.', [
                'operation' => 'confirm_payment',
                'endpoint' => $endpoint,
                'payload' => $payload,
                'network_error' => $exception->getMessage(),
            ], $exception);
        }

        $data = $response->json();

        if ( ! $response->successful() || ! is_array($data)) {
            throw $this->responseException('Espees payment confirmation failed.', $response, $data, [
                'operation' => 'confirm_payment',
                'endpoint' => $endpoint,
                'payload' => $payload,
            ]);
        }

        return $data;
    }

    public function paymentUrl(string $paymentRef): string
    {
        return mb_rtrim((string) config('services.espees.payment_url'), '/') . '/' . rawurlencode($paymentRef);
    }

    /**
     * @param  array<string, mixed>  $gateway
     */
    private function productUrl(array $gateway): string
    {
        return $this->endpoint($gateway, 'product_url', (string) config('services.espees.product_url'));
    }

    /**
     * @param  array<string, mixed>  $gateway
     */
    private function confirmUrl(array $gateway): string
    {
        return $this->endpoint($gateway, 'confirm_url', (string) config('services.espees.confirm_url'));
    }

    /**
     * @param  array<string, mixed>  $gateway
     */
    private function endpoint(array $gateway, string $key, string $fallback): string
    {
        $endpoint = $gateway[$key] ?? $fallback;

        return is_string($endpoint) ? $endpoint : $fallback;
    }

    private function publicCallbackUrl(string $url): string
    {
        $appUrl = (string) config('app.url', '');

        if ( ! str_starts_with($appUrl, 'https://') || ! str_starts_with($url, 'http://')) {
            return $url;
        }

        $appHost = parse_url($appUrl, PHP_URL_HOST);
        $urlHost = parse_url($url, PHP_URL_HOST);

        if ( ! is_string($appHost) || ! is_string($urlHost) || 0 !== strcasecmp($appHost, $urlHost)) {
            return $url;
        }

        return 'https://' . mb_substr($url, 7);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function paymentReferenceFrom(array $data): ?string
    {
        $paymentRef = $this->firstString($data, [
            /*
             * What the live API actually returns is `productid`, and it is the
             * same value the /pay/{ref} checkout URL takes. Without it here a
             * successful product creation was read as "Espees did not return a
             * payment reference" and thrown away.
             */
            'productid',
            'product_id',
            'payment_ref',
            'payment_reference',
            'reference',
            'transaction_reference',
            'transaction_ref',
            'tx_ref',
            'trxref',
            'trx_ref',
            'ref',
        ]);

        if (null !== $paymentRef) {
            return $paymentRef;
        }

        foreach (['data', 'payment', 'transaction', 'result'] as $container) {
            $nested = $data[$container] ?? null;

            if (is_array($nested)) {
                $paymentRef = $this->paymentReferenceFrom($nested);

                if (null !== $paymentRef) {
                    return $paymentRef;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $keys
     */
    private function firstString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;

            if (is_string($value) && '' !== mb_trim($value)) {
                return mb_trim($value);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function responseException(string $message, Response $response, mixed $data, array $context): EspeesGatewayException
    {
        $providerMessage = is_array($data) ? $this->providerMessage($data) : null;

        if (null !== $providerMessage) {
            $message .= ' Provider response: ' . $providerMessage;
        }

        return new EspeesGatewayException($message, $this->withoutNullValues(array_merge($context, [
            'response_status' => $response->status(),
            'response_json' => is_array($data) ? $data : null,
            'response_body' => $this->bodyExcerpt($response),
        ])));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function providerMessage(array $data): ?string
    {
        foreach (['message', 'error', 'detail', 'reason', 'status_message', 'statusMessage'] as $key) {
            $value = $data[$key] ?? null;

            if (is_string($value) && '' !== mb_trim($value)) {
                return mb_trim($value);
            }

            if (is_array($value)) {
                return mb_substr((string) json_encode($value), 0, 500);
            }
        }

        return null;
    }

    private function bodyExcerpt(Response $response): string
    {
        return mb_substr($response->body(), 0, 2000);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function withoutNullValues(array $values): array
    {
        return array_filter($values, static fn (mixed $value): bool => null !== $value);
    }
}
