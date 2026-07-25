<?php

declare(strict_types=1);

namespace Modules\Security\Support;

use PragmaRX\Google2FA\Google2FA;
use Throwable;

/**
 * The TOTP half of two-factor: secrets, the URI an authenticator app scans, and
 * verification of the six digits it produces.
 */
final readonly class TwoFactorAuthenticator
{
    public function __construct(private Google2FA $google2fa) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    /** The `otpauth://` URI behind the QR code, per the Key Uri Format. */
    public function provisioningUri(string $issuer, string $holder, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl($issuer, $holder, $secret);
    }

    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if ($code === '') {
            return false;
        }

        try {
            // One step either side of now, so a code typed as the clock rolls
            // over is still accepted.
            return $this->google2fa->verifyKey($secret, $code, 1) !== false;
        } catch (Throwable) {
            // A malformed secret or a code with letters in it is a failed
            // attempt, not a server error.
            return false;
        }
    }

    /** The code an app would show right now — used by tests and by nothing else. */
    public function currentCode(string $secret): string
    {
        return $this->google2fa->getCurrentOtp($secret);
    }
}
