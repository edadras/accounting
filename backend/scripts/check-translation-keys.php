<?php

declare(strict_types=1);

/**
 * Fails when the four bundled locales do not define the same set of keys.
 *
 * docs/10-quality-and-dod.md §2 requires every display string to exist in every
 * locale, and §5 step 7 requires CI to check it. The failure this guards against
 * is silent: a missing key falls back to the raw key id, so an untranslated
 * screen renders `budget.rollover` at the user instead of a word — it looks like
 * a rendering bug, not a missing translation, and nobody files it.
 *
 * The file is parsed as text rather than through Dart. Adding a Dart toolchain
 * to the backend CI job to read one const map would cost minutes per run, and
 * the map is a flat literal with one key per line — the shape is checked below
 * so a future restructuring fails loudly instead of quietly matching nothing.
 *
 * Usage:  php backend/scripts/check-translation-keys.php [path-to-translations.dart]
 * Exit:   0 all locales agree, 1 mismatch or unparseable input.
 */

const LOCALES = ['fa', 'en', 'tr', 'ar'];

$path = $argv[1] ?? dirname(__DIR__, 2).'/app/lib/core/i18n/translations.dart';

if (! is_file($path)) {
    fwrite(STDERR, "translation source not found: {$path}\n");
    exit(1);
}

$lines = file($path, FILE_IGNORE_NEW_LINES);
$keys = [];
$locale = null;

foreach ($lines as $line) {
    // A locale header opens a map: `    'fa': {`. Checked before the key
    // pattern below, which it would otherwise also match.
    if (preg_match("/^\s*'([a-z]{2})':\s*\{\s*$/", $line, $m)) {
        $locale = in_array($m[1], LOCALES, true) ? $m[1] : null;
        if ($locale !== null) {
            $keys[$locale] = [];
        }

        continue;
    }

    if ($locale !== null && preg_match("/^\s*'([^']+)'\s*:/", $line, $m)) {
        $keys[$locale][] = $m[1];
    }
}

$missingLocales = array_diff(LOCALES, array_keys($keys));

if ($missingLocales !== []) {
    fwrite(STDERR, 'no key map found for locale(s): '.implode(', ', $missingLocales)."\n");
    fwrite(STDERR, "the file shape changed — update this script rather than deleting it.\n");
    exit(1);
}

// A locale that parsed to a handful of keys means the regex stopped matching,
// not that the app shrank. Treat it as a parse failure, not as agreement.
foreach ($keys as $code => $found) {
    if (count($found) < 50) {
        fwrite(STDERR, "locale [{$code}] parsed to only ".count($found)." keys — the parser is out of step with the file.\n");
        exit(1);
    }
}

$failed = false;

// Duplicates are reported separately: two entries for one key make the counts
// agree while one of the two values is unreachable.
foreach (LOCALES as $code) {
    $duplicates = array_keys(array_filter(array_count_values($keys[$code]), fn (int $n) => $n > 1));

    if ($duplicates !== []) {
        $failed = true;
        fwrite(STDERR, "[{$code}] duplicate key(s): ".implode(', ', $duplicates)."\n");
    }
}

$union = [];

foreach (LOCALES as $code) {
    $union = array_merge($union, $keys[$code]);
}

$union = array_values(array_unique($union));
sort($union);

foreach (LOCALES as $code) {
    $missing = array_values(array_diff($union, $keys[$code]));

    if ($missing !== []) {
        $failed = true;
        sort($missing);
        fwrite(STDERR, '[' .$code.'] missing '.count($missing).' key(s):'."\n");

        foreach ($missing as $key) {
            $present = implode(', ', array_values(array_filter(
                LOCALES,
                fn (string $other) => in_array($key, $keys[$other], true),
            )));
            fwrite(STDERR, "    {$key}  (present in: {$present})\n");
        }
    }
}

if ($failed) {
    fwrite(STDERR, "\ntranslation key sets differ across locales.\n");
    exit(1);
}

printf(
    "translation keys agree across %s: %d keys each (%d total entries) in %s\n",
    implode('/', LOCALES),
    count($union),
    array_sum(array_map('count', $keys)),
    str_replace(dirname(__DIR__, 2).'/', '', $path),
);

exit(0);
