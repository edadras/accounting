<?php

declare(strict_types=1);

namespace Modules\AI\Support;

use Modules\Search\Support\TextNormalizer;

/**
 * The AI layer's single door onto Search's normaliser.
 *
 * Persian text has to be folded the same way here as it is at index time or
 * «لیر» typed on one keyboard stops matching «لیر» typed on another. Rather
 * than copy those rules, this class points at the one implementation and adds
 * only what parsing needs on top of it: the ZWNJ that TextNormalizer turns
 * into a space is already handled, but a stray Arabic thousands separator
 * would otherwise survive into a number.
 */
final class TextNormalizerBridge
{
    public static function normalize(?string $text): string
    {
        return TextNormalizer::normalize($text);
    }

    /** Normalised and stripped of the separators that would break number parsing. */
    public static function forParsing(?string $text): string
    {
        return str_replace(["\u{066C}", '٬'], '', self::normalize($text));
    }
}
