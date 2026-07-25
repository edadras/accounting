<?php

declare(strict_types=1);

namespace Modules\Search\Support;

/**
 * Persian/Arabic text normalisation, applied both before indexing and before
 * searching (docs/06-i18n-rtl.md §7).
 *
 * Without it, `گوشت` typed on an Arabic keyboard and `گوشت` typed on a Persian
 * one are different byte sequences and one never finds the other. The same
 * rules are implemented in Flutter for offline search, against shared cases.
 */
final class TextNormalizer
{
    /**
     * Arabic letters that have a Persian twin. `ى` (alef maksura) is folded
     * too: Arabic keyboards produce it where Persian expects `ی`.
     *
     * @var array<string, string>
     */
    private const LETTERS = [
        'ي' => 'ی',
        'ى' => 'ی',
        'ك' => 'ک',
    ];

    /**
     * Harakat, tanwin, shadda, sukun, superscript alef — decoration that
     * changes nothing about which word was written — and the kashida, which is
     * pure typographic stretching.
     *
     * @var array<string, string>
     */
    private const MARKS = [
        "\u{064B}" => '', // tanwin fath
        "\u{064C}" => '', // tanwin damm
        "\u{064D}" => '', // tanwin kasr
        "\u{064E}" => '', // fatha
        "\u{064F}" => '', // damma
        "\u{0650}" => '', // kasra
        "\u{0651}" => '', // shadda
        "\u{0652}" => '', // sukun
        "\u{0653}" => '', // maddah above
        "\u{0654}" => '', // hamza above
        "\u{0655}" => '', // hamza below
        "\u{0670}" => '', // superscript alef
        "\u{0640}" => '', // kashida / tatweel
    ];

    /** @var array<string, string> */
    private const DIGITS = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    /**
     * ZWNJ becomes an ordinary space so that `می‌رود` and `می رود` index the
     * same way.
     *
     * @var array<string, string>
     */
    private const SPACES = [
        "\u{200C}" => ' ',
        "\r" => ' ',
        "\n" => ' ',
        "\t" => ' ',
    ];

    public static function normalize(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $text = strtr($text, self::replacements());
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    /**
     * The replacement table, in the order it is applied. Public so the Flutter
     * port and any future engine stay derived from one source.
     *
     * @return array<string, string>
     */
    public static function replacements(): array
    {
        return array_merge(self::LETTERS, self::MARKS, self::DIGITS, self::SPACES);
    }
}
