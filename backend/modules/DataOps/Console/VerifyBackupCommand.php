<?php

declare(strict_types=1);

namespace Modules\DataOps\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Asserts that a backup that could actually be restored exists.
 *
 * docs/07-security.md §7: a backup nobody has verified is not a backup. The
 * failure this catches is the quiet one — the job kept "succeeding" for weeks
 * while writing nothing, and nobody found out until a restore was needed.
 *
 * A non-zero exit is the point: this is meant to be run from the scheduler or
 * from CI, where a failed command is what raises the alarm.
 */
final class VerifyBackupCommand extends Command
{
    protected $signature = 'backup:verify
        {--disk= : Verify on this disk instead of the configured destination}
        {--max-age= : Fail if the newest backup is older than this many hours}';

    protected $description = 'Check that the newest backup exists, is not empty and is recent';

    public function handle(): int
    {
        $disk = (string) ($this->option('disk') ?? $this->configuredDisk());
        $directory = (string) config('backup.backup.name', 'finora');
        $maxAgeHours = (int) ($this->option('max-age') ?? config('dataops.backup.max_age_hours'));
        $minBytes = (int) config('dataops.backup.min_bytes');

        try {
            $backups = collect(Storage::disk($disk)->files($directory))
                ->filter(fn (string $file): bool => str_ends_with(strtolower($file), '.zip'))
                ->values();
        } catch (Throwable $e) {
            $this->error("Cannot read disk [{$disk}]: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($backups->isEmpty()) {
            $this->error("No backup found in [{$directory}] on disk [{$disk}].");

            return self::FAILURE;
        }

        $newest = $backups
            ->sortByDesc(fn (string $file): int => Storage::disk($disk)->lastModified($file))
            ->first();

        $size = (int) Storage::disk($disk)->size($newest);

        if ($size < $minBytes) {
            $this->error("The newest backup [{$newest}] is empty ({$size} bytes).");

            return self::FAILURE;
        }

        $writtenAt = CarbonImmutable::createFromTimestamp(Storage::disk($disk)->lastModified($newest));
        $ageHours = (int) $writtenAt->diffInHours(CarbonImmutable::now());

        if ($ageHours > $maxAgeHours) {
            $this->error(sprintf(
                'The newest backup [%s] is %d hours old; the limit is %d.',
                $newest,
                $ageHours,
                $maxAgeHours,
            ));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Backup [%s] on disk [%s]: %d bytes, %d hour(s) old.',
            $newest,
            $disk,
            $size,
            $ageHours,
        ));

        return self::SUCCESS;
    }

    private function configuredDisk(): string
    {
        $disks = (array) config('backup.backup.destination.disks', ['local']);

        return (string) (reset($disks) ?: 'local');
    }
}
