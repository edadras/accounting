<?php

declare(strict_types=1);

namespace Modules\Reports\Support;

/**
 * Makes Persian and Arabic readable in a PDF that dompdf produced.
 *
 * dompdf has no text shaper and no bidi algorithm — verified: it writes the
 * codepoints it was given, in the order it was given them, and lays them out
 * left to right. Handed logical-order Persian it produces letters in isolated
 * form, unjoined, reading backwards. The `direction: rtl` property only picks a
 * default text-align (see Css\Style::_compute_text_align); it reorders nothing.
 *
 * So the shaping happens here, before the HTML is built:
 *
 *   1. Each letter is replaced by its Arabic Presentation Form — the isolated,
 *      initial, medial or final glyph the letter takes given its neighbours —
 *      and lam+alef pairs by their ligature. Vazirmatn carries the whole
 *      U+FB50–FDFF and U+FE70–FEFF range, so every form has a real glyph.
 *   2. The string is reordered from logical into visual order, so that laying
 *      it out left to right displays it right to left.
 *
 * This is a deliberately reduced bidi pass, not UAX #9: it resolves neutrals
 * between two strong characters, keeps Latin and digit runs intact and in their
 * own order, and mirrors brackets. That covers a report — dates, amounts,
 * account and category names, payees — and it is applied per cell, so a line
 * break inside a wrapped cell would split a visual run. PDF table cells are
 * therefore rendered `nowrap`.
 */
final class ArabicShaper
{
    /**
     * Letter to its presentation forms.
     *
     * Two entries mean the letter joins only to its right: [isolated, final].
     * Four mean it joins on both sides: [isolated, final, initial, medial].
     *
     * @var array<int, list<int>>
     */
    private const FORMS = [
        0x0621 => [0xFE80],
        0x0622 => [0xFE81, 0xFE82],
        0x0623 => [0xFE83, 0xFE84],
        0x0624 => [0xFE85, 0xFE86],
        0x0625 => [0xFE87, 0xFE88],
        0x0626 => [0xFE89, 0xFE8A, 0xFE8B, 0xFE8C],
        0x0627 => [0xFE8D, 0xFE8E],
        0x0628 => [0xFE8F, 0xFE90, 0xFE91, 0xFE92],
        0x0629 => [0xFE93, 0xFE94],
        0x062A => [0xFE95, 0xFE96, 0xFE97, 0xFE98],
        0x062B => [0xFE99, 0xFE9A, 0xFE9B, 0xFE9C],
        0x062C => [0xFE9D, 0xFE9E, 0xFE9F, 0xFEA0],
        0x062D => [0xFEA1, 0xFEA2, 0xFEA3, 0xFEA4],
        0x062E => [0xFEA5, 0xFEA6, 0xFEA7, 0xFEA8],
        0x062F => [0xFEA9, 0xFEAA],
        0x0630 => [0xFEAB, 0xFEAC],
        0x0631 => [0xFEAD, 0xFEAE],
        0x0632 => [0xFEAF, 0xFEB0],
        0x0633 => [0xFEB1, 0xFEB2, 0xFEB3, 0xFEB4],
        0x0634 => [0xFEB5, 0xFEB6, 0xFEB7, 0xFEB8],
        0x0635 => [0xFEB9, 0xFEBA, 0xFEBB, 0xFEBC],
        0x0636 => [0xFEBD, 0xFEBE, 0xFEBF, 0xFEC0],
        0x0637 => [0xFEC1, 0xFEC2, 0xFEC3, 0xFEC4],
        0x0638 => [0xFEC5, 0xFEC6, 0xFEC7, 0xFEC8],
        0x0639 => [0xFEC9, 0xFECA, 0xFECB, 0xFECC],
        0x063A => [0xFECD, 0xFECE, 0xFECF, 0xFED0],
        0x0641 => [0xFED1, 0xFED2, 0xFED3, 0xFED4],
        0x0642 => [0xFED5, 0xFED6, 0xFED7, 0xFED8],
        0x0643 => [0xFED9, 0xFEDA, 0xFEDB, 0xFEDC],
        0x0644 => [0xFEDD, 0xFEDE, 0xFEDF, 0xFEE0],
        0x0645 => [0xFEE1, 0xFEE2, 0xFEE3, 0xFEE4],
        0x0646 => [0xFEE5, 0xFEE6, 0xFEE7, 0xFEE8],
        0x0647 => [0xFEE9, 0xFEEA, 0xFEEB, 0xFEEC],
        0x0648 => [0xFEED, 0xFEEE],
        0x0649 => [0xFEEF, 0xFEF0],
        0x064A => [0xFEF1, 0xFEF2, 0xFEF3, 0xFEF4],

        // Persian and Urdu letters, which is what makes `fa` legible.
        0x067E => [0xFB56, 0xFB57, 0xFB58, 0xFB59], // پ
        0x0686 => [0xFB7A, 0xFB7B, 0xFB7C, 0xFB7D], // چ
        0x0698 => [0xFB8A, 0xFB8B],                 // ژ
        0x06A9 => [0xFB8E, 0xFB8F, 0xFB90, 0xFB91], // ک
        0x06AF => [0xFB92, 0xFB93, 0xFB94, 0xFB95], // گ
        0x06BE => [0xFBAA, 0xFBAB, 0xFBAC, 0xFBAD],
        0x06C0 => [0xFBA4, 0xFBA5],                 // ۀ
        0x06CC => [0xFBFC, 0xFBFD, 0xFBFE, 0xFBFF], // ی
        0x06D2 => [0xFBAE, 0xFBAF],
    ];

    /** lam + alef, which must be written as one glyph. @var array<int, list<int>> */
    private const LIGATURES = [
        0x0622 => [0xFEF5, 0xFEF6],
        0x0623 => [0xFEF7, 0xFEF8],
        0x0625 => [0xFEF9, 0xFEFA],
        0x0627 => [0xFEFB, 0xFEFC],
    ];

    private const LAM = 0x0644;

    private const TATWEEL = 0x0640;

    /**
     * The half-space: it separates two letters that would otherwise join, so
     * neither side of it may take a connected form. «نیم‌فاصله» is two joined
     * pieces, not one word.
     */
    private const ZWNJ = 0x200C;

    private const ZWJ = 0x200D;

    /** @var array<int, int> */
    private const MIRRORED = [
        0x0028 => 0x0029, 0x0029 => 0x0028,
        0x005B => 0x005D, 0x005D => 0x005B,
        0x007B => 0x007D, 0x007D => 0x007B,
        0x003C => 0x003E, 0x003E => 0x003C,
        0x00AB => 0x00BB, 0x00BB => 0x00AB,
    ];

    private const NEUTRAL = 0;

    private const LTR = 1;

    private const RTL = 2;

    /** Shaped and reordered for a left-to-right layout engine to draw. */
    public static function present(string $text): string
    {
        if ($text === '' || ! self::containsRtl($text)) {
            return $text;
        }

        return self::toString(self::reorder(self::shape(self::toCodepoints($text))));
    }

    public static function containsRtl(string $text): bool
    {
        foreach (self::toCodepoints($text) as $codepoint) {
            if (self::isRtl($codepoint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $codepoints
     * @return list<int>
     */
    private static function shape(array $codepoints): array
    {
        $out = [];
        $count = count($codepoints);

        for ($i = 0; $i < $count; $i++) {
            $current = $codepoints[$i];

            // Marks sit on the letter before them and take part in nothing.
            if (self::isTransparent($current)) {
                $out[] = $current;

                continue;
            }

            $previous = self::neighbour($codepoints, $i, -1);
            $next = self::neighbour($codepoints, $i, 1);

            $joinsBackwards = $previous !== null && self::joinsForward($previous);
            $joinsForwards = $next !== null && self::joinsBackward($next);

            // Only an alef directly after the lam: anything in between, even a
            // mark, means this is not the pair that ligates, and swallowing it
            // would lose a character.
            $following = $codepoints[$i + 1] ?? null;

            if ($current === self::LAM && $following !== null && isset(self::LIGATURES[$following])) {
                $out[] = self::LIGATURES[$following][$joinsBackwards ? 1 : 0];
                $i++;

                continue;
            }

            if (! isset(self::FORMS[$current])) {
                $out[] = $current;

                continue;
            }

            $forms = self::FORMS[$current];
            $dual = count($forms) === 4;

            $index = match (true) {
                $joinsBackwards && $joinsForwards => $dual ? 3 : 1,
                $joinsBackwards => 1,
                $joinsForwards => $dual ? 2 : 0,
                default => 0,
            };

            $out[] = $forms[$index] ?? $forms[0];
        }

        return $out;
    }

    /**
     * Logical order to visual order.
     *
     * @param  list<int>  $codepoints
     * @return list<int>
     */
    private static function reorder(array $codepoints): array
    {
        $classes = self::classify($codepoints);
        $runs = [];

        foreach ($codepoints as $index => $codepoint) {
            $direction = $classes[$index];
            $last = count($runs) - 1;

            if ($last >= 0 && $runs[$last]['direction'] === $direction) {
                $runs[$last]['chars'][] = $codepoint;

                continue;
            }

            $runs[] = ['direction' => $direction, 'chars' => [$codepoint]];
        }

        $out = [];

        // The paragraph is right-to-left, so the runs come out back to front;
        // inside a right-to-left run the characters do too, while a Latin or
        // numeric run keeps its own order.
        foreach (array_reverse($runs) as $run) {
            if ($run['direction'] !== self::RTL) {
                array_push($out, ...$run['chars']);

                continue;
            }

            // Reversed by cluster, not by character: a combining mark is drawn
            // onto whatever glyph precedes it, so it has to keep following its
            // own letter rather than being flung onto the next one.
            foreach (array_reverse(self::cluster($run['chars'])) as $cluster) {
                foreach ($cluster as $codepoint) {
                    $out[] = self::MIRRORED[$codepoint] ?? $codepoint;
                }
            }
        }

        return $out;
    }

    /**
     * Each letter with the marks that belong to it.
     *
     * @param  list<int>  $codepoints
     * @return list<list<int>>
     */
    private static function cluster(array $codepoints): array
    {
        $clusters = [];

        foreach ($codepoints as $codepoint) {
            if (self::isTransparent($codepoint) && $clusters !== []) {
                $clusters[count($clusters) - 1][] = $codepoint;

                continue;
            }

            $clusters[] = [$codepoint];
        }

        return $clusters;
    }

    /**
     * @param  list<int>  $codepoints
     * @return list<int> one direction per input character
     */
    private static function classify(array $codepoints): array
    {
        $classes = array_map(
            static fn (int $codepoint): int => match (true) {
                // Arabic-Indic digits sit inside right-to-left text but count
                // upwards left to right, exactly like Latin ones. Reversing
                // them would turn an account ending ۶۰۳۷ into ۷۳۰۶.
                self::isArabicIndicDigit($codepoint) => self::LTR,
                self::isRtl($codepoint) => self::RTL,
                self::isLtr($codepoint) => self::LTR,
                default => self::NEUTRAL,
            },
            $codepoints,
        );

        $count = count($codepoints);

        // A sign or separator touching a digit belongs to the number: "-42.00"
        // must stay one piece or the minus ends up on the far side of it.
        foreach ($codepoints as $index => $codepoint) {
            if ($classes[$index] !== self::NEUTRAL || ! self::isNumericTerminator($codepoint)) {
                continue;
            }

            $before = $index > 0 && ($classes[$index - 1] ?? null) === self::LTR;
            $after = $index + 1 < $count && ($classes[$index + 1] ?? null) === self::LTR;

            if ($before || $after) {
                $classes[$index] = self::LTR;
            }
        }

        // A neutral run between two runs of the same direction joins them;
        // otherwise it belongs to the paragraph, which is right-to-left.
        for ($i = 0; $i < $count; $i++) {
            if ($classes[$i] !== self::NEUTRAL) {
                continue;
            }

            $end = $i;

            while ($end + 1 < $count && $classes[$end + 1] === self::NEUTRAL) {
                $end++;
            }

            $before = $i > 0 ? $classes[$i - 1] : self::RTL;
            $after = $end + 1 < $count ? $classes[$end + 1] : self::RTL;
            $resolved = $before === $after ? $before : self::RTL;

            for ($j = $i; $j <= $end; $j++) {
                $classes[$j] = $resolved;
            }

            $i = $end;
        }

        // Every write above lands on an index that was already there, so this
        // only restates that the classes still line up with the codepoints.
        return array_values($classes);
    }

    /** @param  list<int>  $codepoints */
    private static function neighbour(array $codepoints, int $index, int $step): ?int
    {
        $position = self::indexOfNeighbour($codepoints, $index, $step);

        return $position === null ? null : $codepoints[$position];
    }

    /** @param  list<int>  $codepoints */
    private static function indexOfNeighbour(array $codepoints, int $index, int $step): ?int
    {
        for ($i = $index + $step; $i >= 0 && $i < count($codepoints); $i += $step) {
            if (! self::isTransparent($codepoints[$i])) {
                return $i;
            }
        }

        return null;
    }

    /** True when the letter before this one can connect to it. */
    private static function joinsForward(int $codepoint): bool
    {
        return $codepoint !== self::ZWNJ
            && ($codepoint === self::TATWEEL
                || $codepoint === self::ZWJ
                || (isset(self::FORMS[$codepoint]) && count(self::FORMS[$codepoint]) === 4));
    }

    /** True when this letter can take a final or medial form. */
    private static function joinsBackward(int $codepoint): bool
    {
        return $codepoint !== self::ZWNJ
            && ($codepoint === self::TATWEEL
                || $codepoint === self::ZWJ
                || (isset(self::FORMS[$codepoint]) && count(self::FORMS[$codepoint]) > 1));
    }

    /**
     * A mark that sits on the letter before it and takes no part in joining.
     *
     * The zero-width joiner and non-joiner are deliberately not here: their
     * whole purpose is to decide whether the letters around them connect, so
     * they have to be visible to that decision. "نیم‌فاصله" is two joined
     * pieces, not one word.
     */
    private static function isTransparent(int $codepoint): bool
    {
        return ($codepoint >= 0x064B && $codepoint <= 0x065F)
            || $codepoint === 0x0670
            || ($codepoint >= 0x06D6 && $codepoint <= 0x06ED);
    }

    private static function isRtl(int $codepoint): bool
    {
        return ($codepoint >= 0x0590 && $codepoint <= 0x05FF)   // Hebrew
            || ($codepoint >= 0x0600 && $codepoint <= 0x06FF)   // Arabic
            || ($codepoint >= 0x0750 && $codepoint <= 0x077F)   // Arabic supplement
            || ($codepoint >= 0xFB50 && $codepoint <= 0xFDFF)   // presentation forms A
            || ($codepoint >= 0xFE70 && $codepoint <= 0xFEFF);  // presentation forms B
    }

    private static function isLtr(int $codepoint): bool
    {
        return ($codepoint >= 0x0030 && $codepoint <= 0x0039)
            || ($codepoint >= 0x0041 && $codepoint <= 0x005A)
            || ($codepoint >= 0x0061 && $codepoint <= 0x007A)
            || ($codepoint >= 0x00C0 && $codepoint <= 0x02AF)
            || ($codepoint >= 0x0370 && $codepoint <= 0x058F);
    }

    private static function isArabicIndicDigit(int $codepoint): bool
    {
        return ($codepoint >= 0x0660 && $codepoint <= 0x0669)
            || ($codepoint >= 0x06F0 && $codepoint <= 0x06F9);
    }

    private static function isNumericTerminator(int $codepoint): bool
    {
        return in_array($codepoint, [
            0x002B, 0x002D, 0x002E, 0x002C, 0x0025, 0x0023, 0x002F, 0x003A,
            0x066B, 0x066C, // Arabic decimal and thousands separators
        ], true);
    }

    /** A Unicode scalar value: in range, and not one half of a surrogate pair. */
    private static function isEncodable(int $codepoint): bool
    {
        return $codepoint >= 0
            && $codepoint <= 0x10FFFF
            && ($codepoint < 0xD800 || $codepoint > 0xDFFF);
    }

    /** @return list<int> */
    private static function toCodepoints(string $text): array
    {
        $codepoints = [];

        foreach (mb_str_split($text, 1, 'UTF-8') as $character) {
            // A byte sequence that is not valid UTF-8 has no code point at all
            // — mb_ord answers false for it — so it becomes the replacement
            // character and the rest of the string stays shapeable.
            $codepoints[] = mb_check_encoding($character, 'UTF-8')
                ? mb_ord($character, 'UTF-8')
                : 0xFFFD;
        }

        return $codepoints;
    }

    /** @param  list<int>  $codepoints */
    private static function toString(array $codepoints): string
    {
        $text = '';

        foreach ($codepoints as $codepoint) {
            // Anything outside Unicode, and either half of a surrogate pair,
            // has no UTF-8 encoding; mb_chr would answer false and the
            // character is dropped rather than written as a literal "false".
            if (self::isEncodable($codepoint)) {
                $text .= mb_chr($codepoint, 'UTF-8');
            }
        }

        return $text;
    }
}
