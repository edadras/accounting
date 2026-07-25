<?php

declare(strict_types=1);

namespace Modules\I18n\Console;

use Illuminate\Console\Command;
use Modules\I18n\Models\Translation;

/**
 * Reports keys that exist in one locale but not another.
 *
 * A key present in Persian and missing in Arabic falls back to English at
 * runtime — which nobody notices until an Arabic-speaking user does.
 */
final class MissingTranslationsCommand extends Command
{
    protected $signature = 'i18n:missing {--reference=en : locale to compare against}';

    protected $description = 'List translation keys missing from each locale';

    public function handle(): int
    {
        $reference = (string) $this->option('reference');

        $keysFor = fn (string $locale): array => Translation::query()
            ->where('locale', $locale)
            ->whereNotNull('value')
            ->pluck('key', 'group')
            ->keys()
            ->all();

        $referenceKeys = Translation::query()
            ->where('locale', $reference)
            ->whereNotNull('value')
            ->get(['group', 'key'])
            ->map(fn ($row) => "{$row->group}|{$row->key}")
            ->all();

        if ($referenceKeys === []) {
            $this->warn("No translations stored for the reference locale [{$reference}].");

            return self::SUCCESS;
        }

        $locales = Translation::query()->distinct()->pluck('locale')->all();
        $rows = [];
        $total = 0;

        foreach ($locales as $locale) {
            if ($locale === $reference) {
                continue;
            }

            $have = Translation::query()
                ->where('locale', $locale)
                ->whereNotNull('value')
                ->get(['group', 'key'])
                ->map(fn ($row) => "{$row->group}|{$row->key}")
                ->all();

            $missing = array_values(array_diff($referenceKeys, $have));
            $total += count($missing);

            $rows[] = [
                $locale,
                count($missing),
                implode(', ', array_map(
                    fn (string $k) => explode('|', $k)[1],
                    array_slice($missing, 0, 5),
                )).(count($missing) > 5 ? ' …' : ''),
            ];
        }

        $this->table(['locale', 'missing', 'examples'], $rows);
        $this->info($total === 0 ? 'Every locale is complete.' : "{$total} missing translations.");

        return self::SUCCESS;
    }
}
