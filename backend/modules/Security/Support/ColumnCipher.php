<?php

declare(strict_types=1);

namespace Modules\Security\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypts and decrypts a single column value, and — the part that matters —
 * can tell whether a value has been through it already.
 *
 * Every entry point that writes a sensitive column is reachable twice: a
 * migration can be re-run, a model can be saved again. Without a way to
 * recognise ciphertext, the second pass encrypts the first pass's output and
 * the plaintext is gone for good.
 */
final class ColumnCipher
{
    public static function encrypt(string $plaintext): string
    {
        return Crypt::encryptString($plaintext);
    }

    /** Returns the value untouched when it was never encrypted. */
    public static function decrypt(string $value): string
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return $value;
        }
    }

    public static function isEncrypted(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
}
