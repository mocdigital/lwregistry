<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

final class TurnstileVerifier
{
    public function enabled(): bool
    {
        return null !== $this->secretKey();
    }

    public function siteKey(): ?string
    {
        $key = config('services.turnstile.site_key');

        return is_string($key) && '' !== $key ? $key : null;
    }

    public function secretKey(): ?string
    {
        $key = config('services.turnstile.secret_key');

        return is_string($key) && '' !== $key ? $key : null;
    }

    public function verify(?string $token, ?string $ip = null): bool
    {
        if ( ! $this->enabled()) {
            return true;
        }

        if ( ! is_string($token) || '' === mb_trim($token)) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->post((string) config('services.turnstile.siteverify_url'), array_filter([
                    'secret' => $this->secretKey(),
                    'response' => $token,
                    'remoteip' => $ip,
                ]));
        } catch (Throwable) {
            return false;
        }

        return $response->ok() && true === $response->json('success');
    }
}
