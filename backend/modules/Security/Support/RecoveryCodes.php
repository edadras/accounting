<?php

declare(strict_types=1);

namespace Modules\Security\Support;

use Illuminate\Support\Str;

/**
 * The way back in when the phone with the authenticator on it is gone.
 */
final class RecoveryCodes
{
    public const COUNT = 8;

    /** @return list<string> */
    public static function generate(int $count = self::COUNT): array
    {
        return array_map(
            static fn (): string => Str::upper(Str::random(5).'-'.Str::random(5)),
            range(1, $count),
        );
    }
}
