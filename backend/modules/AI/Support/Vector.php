<?php

declare(strict_types=1);

namespace Modules\AI\Support;

/**
 * The arithmetic behind semantic search, kept in one place so the provider,
 * the store and the ranker cannot disagree about it.
 *
 * Vectors are stored already L2-normalised, which makes cosine similarity a
 * plain dot product at query time. Normalising once on write rather than twice
 * per comparison matters: a semantic query scores the whole candidate set.
 */
final class Vector
{
    /**
     * @param  list<float>  $vector
     * @return list<float>
     */
    public static function normalize(array $vector): array
    {
        $norm = 0.0;

        foreach ($vector as $value) {
            $norm += $value * $value;
        }

        if ($norm <= 0.0) {
            return $vector;
        }

        $norm = sqrt($norm);

        return array_map(static fn (float $value): float => $value / $norm, $vector);
    }

    /**
     * Cosine similarity of two already-normalised vectors.
     *
     * Vectors of different lengths score zero rather than throwing: that only
     * happens when the embedding model changed under a row that has not been
     * re-embedded yet, and dropping such a row out of the ranking is better
     * than failing the whole search.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        $length = count($a);

        if ($length === 0 || $length !== count($b)) {
            return 0.0;
        }

        $dot = 0.0;

        for ($i = 0; $i < $length; $i++) {
            $dot += $a[$i] * $b[$i];
        }

        return $dot;
    }

    /**
     * @param  list<float>  $vector
     */
    public static function encode(array $vector): string
    {
        // Six decimals is well below the noise floor of any embedding and
        // roughly halves what the column has to hold.
        return (string) json_encode(
            array_map(static fn (float $value): float => round($value, 6), $vector),
        );
    }

    /** @return list<float> */
    public static function decode(?string $encoded): array
    {
        $decoded = json_decode((string) $encoded, true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $value): float => (float) $value, $decoded));
    }
}
