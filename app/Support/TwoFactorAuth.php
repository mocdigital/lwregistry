<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor authentication (RFC 6238).
 *
 * Standard TOTP, so it works with any authenticator the member already uses —
 * Google Authenticator, Authy, 1Password, Microsoft Authenticator, Bitwarden.
 * Nothing here is vendor-specific; the QR is just an `otpauth://` URI.
 *
 * THE TWO-STAGE ENROLMENT MATTERS. `two_factor_secret` is written when setup
 * begins, but `two_factor_confirmed_at` only once the member has typed a code
 * their app generated. Login is gated on the CONFIRMED timestamp, never on the
 * secret. If it keyed off the secret, someone who scanned a QR badly — or
 * closed the tab midway — would be locked out of their own account by a factor
 * they were never able to produce.
 */
final class TwoFactorAuth
{
    /** How many recovery codes to mint. */
    public const RECOVERY_CODE_COUNT = 8;

    /**
     * Accepted drift, in 30-second steps, either side of now.
     *
     * One step (±30s) absorbs ordinary phone/server clock skew and a member
     * typing the last digit as the code rolls over. Wider would meaningfully
     * lengthen the window in which a shoulder-surfed code stays usable.
     */
    private const DRIFT_WINDOW = 1;

    public function __construct(private readonly Google2FA $engine) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    /**
     * The `otpauth://` URI an authenticator scans.
     *
     * The label carries the app name and the member's email so a phone holding
     * several accounts can tell them apart.
     */
    public function provisioningUri(User $user, string $secret): string
    {
        return $this->engine->getQRCodeUrl(
            (string) config('app.name', 'LW Registry'),
            (string) $user->email,
            $secret,
        );
    }

    /** The same URI as an inline SVG, so no third-party QR service sees the secret. */
    public function qrCodeSvg(string $uri): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(220, 0),
            new SvgImageBackEnd(),
        ));

        return $writer->writeString($uri);
    }

    /** Whether `$code` is currently valid for `$secret`. */
    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (6 !== mb_strlen($code)) {
            return false;
        }

        return false !== $this->engine->verifyKey($secret, $code, self::DRIFT_WINDOW);
    }

    /**
     * Single-use codes for a member who has lost their authenticator.
     *
     * Without these, losing a phone means losing the account, and the only
     * remedy is an administrator turning 2FA off by hand.
     *
     * @return list<string>
     */
    public function generateRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn (): string => Str::upper(Str::random(5) . '-' . Str::random(5)))
            ->all();
    }

    /**
     * Spend a recovery code.
     *
     * Returns false if the code is not held. On success the code is removed —
     * they are single-use, so a code read off a screenshot cannot be replayed.
     */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $code = Str::upper(mb_trim($code));
        $codes = $user->two_factor_recovery_codes ?? [];

        if ( ! in_array($code, $codes, true)) {
            return false;
        }

        $user->forceFill([
            'two_factor_recovery_codes' => array_values(array_diff($codes, [$code])),
        ])->save();

        return true;
    }

    /**
     * Turn 2FA off completely.
     *
     * Clears the secret as well as the confirmation — leaving a stale secret
     * behind would let a re-enable silently reuse a secret the member may have
     * already deleted from their authenticator.
     */
    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }
}
