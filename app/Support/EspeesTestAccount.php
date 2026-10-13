<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

final class EspeesTestAccount
{
    public const PAYMENT_AMOUNT = 1;

    public static function enabled(): bool
    {
        if ( ! (bool) config('espees.test_account.enabled', false)) {
            return false;
        }

        return app()->environment(['local', 'testing', 'staging'])
            || (app()->environment('production') && self::productionAllowed());
    }

    public static function productionAllowed(): bool
    {
        return (bool) config('espees.test_account.allow_production', false);
    }

    public static function username(): string
    {
        return (string) config('espees.test_account.email', 'espees.test@lwregistry.test');
    }

    public static function password(): string
    {
        return (string) config('espees.test_account.password', 'EspeesTest!2026');
    }

    public static function matches(?User $user): bool
    {
        return self::enabled()
            && null !== $user
            && 0 === strcasecmp((string) $user->email, self::username());
    }

    public static function canUseCheckout(?User $user, ?int $countryId = null): bool
    {
        if ( ! self::matches($user)) {
            return false;
        }

        $gateway = SboFeeCalculator::espeesGateway($countryId);

        return self::gatewayMayRun($gateway);
    }

    /**
     * The production exception is explicit because it may call the live
     * provider endpoint. The account identity and fixed one-Espee amount still
     * remain enforced separately by this class and the payment controller.
     *
     * @param  array{configured: bool, test_mode: bool}  $gateway
     */
    public static function gatewayMayRun(array $gateway): bool
    {
        return $gateway['configured']
            && ($gateway['test_mode'] || (app()->environment('production') && self::productionAllowed()));
    }
}
