<?php

declare(strict_types=1);

namespace Modules\AI\Support;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * 16 kHz mono, silence trimmed — the shape speech models want
 * (docs/08-ai-layer.md §2).
 *
 * ffmpeg is treated as an optimisation and never as a dependency. A worker
 * without it hands the original recording to the transcriber and says so; the
 * feature degrades, the queue does not break, and nobody has to install a
 * media stack to run the test suite.
 */
final class AudioNormalizer
{
    private static ?string $resolved = null;

    private static bool $looked = false;

    public function available(): bool
    {
        return $this->binary() !== null;
    }

    /**
     * @return array{path: string, normalized: bool, note: string}
     */
    public function normalize(string $path): array
    {
        $binary = $this->binary();

        if ($binary === null) {
            return ['path' => $path, 'normalized' => false, 'note' => 'ffmpeg_unavailable'];
        }

        if (! is_readable($path)) {
            return ['path' => $path, 'normalized' => false, 'note' => 'unreadable_input'];
        }

        $target = rtrim(sys_get_temp_dir(), '/').'/finora-'.Str::ulid()->toString().'.wav';

        try {
            $result = Process::timeout((int) config('ai.audio.max_seconds', 600))->run([
                $binary,
                '-hide_banner', '-loglevel', 'error', '-y',
                '-i', $path,
                '-ac', '1',
                '-ar', (string) (int) config('ai.audio.sample_rate', 16000),
                '-af', 'silenceremove=start_periods=1:start_threshold=-45dB:stop_periods=-1:stop_threshold=-45dB',
                $target,
            ]);
        } catch (\Throwable $e) {
            return ['path' => $path, 'normalized' => false, 'note' => 'ffmpeg_failed:'.$e->getMessage()];
        }

        if (! $result->successful() || ! is_file($target)) {
            return ['path' => $path, 'normalized' => false, 'note' => 'ffmpeg_failed'];
        }

        return ['path' => $target, 'normalized' => true, 'note' => 'ok'];
    }

    /** Located by hand rather than by spawning a probe process: cheaper, and it cannot hang. */
    private function binary(): ?string
    {
        if (self::$looked) {
            return self::$resolved;
        }

        self::$looked = true;
        $configured = (string) config('ai.audio.ffmpeg', 'ffmpeg');

        if (str_contains($configured, DIRECTORY_SEPARATOR)) {
            return self::$resolved = is_executable($configured) ? $configured : null;
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            $candidate = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$configured;

            if (is_executable($candidate)) {
                return self::$resolved = $candidate;
            }
        }

        return self::$resolved = null;
    }

    /** Testing seam: forget what was found so a changed config is picked up. */
    public static function forgetBinary(): void
    {
        self::$looked = false;
        self::$resolved = null;
    }
}
