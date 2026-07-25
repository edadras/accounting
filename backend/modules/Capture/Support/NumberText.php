<?php

declare(strict_types=1);

namespace Modules\Capture\Support;

/**
 * Rewrites a bank's number into the one shape DecimalValue understands.
 *
 * «250,00 TL» is two hundred and fifty lira in Istanbul and twenty-five
 * thousand in the parser's default reading. Which one it is cannot be inferred
 * from the digits — only from knowing whose SMS this is — so it comes from the
 * pattern's `number_format` key rather than from a heuristic.
 */
final class NumberText
{
    public const PLAIN = 'plain';

    public const EUROPEAN = 'european';

    public static function canonical(string $number, string $format): string
    {
        $number = trim($number);

        if ($format !== self::EUROPEAN) {
            return $number;
        }

        return str_replace(',', '.', str_replace('.', '', $number));
    }
}
