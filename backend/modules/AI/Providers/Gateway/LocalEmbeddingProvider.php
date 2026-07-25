<?php

declare(strict_types=1);

namespace Modules\AI\Providers\Gateway;

use Modules\AI\Contracts\EmbeddingProvider;
use Modules\AI\Support\ConceptLexicon;
use Modules\AI\Support\TextNormalizerBridge;
use Modules\AI\Support\Vector;

/**
 * Semantic search with no model and no network.
 *
 * The vector has two kinds of feature in it:
 *
 *   Words. Each token of the normalised text is hashed into a bucket, the
 *   signed hashing trick from Weinberger et al. — the sign is what stops
 *   accidental collisions from quietly inflating every similarity score.
 *   This half is what makes «قصابی گوشت» match a query for «گوشت».
 *
 *   Concepts. Every ConceptLexicon topic the text touches gets its own bucket,
 *   weighted more heavily than a word. This half is the whole point: «بنزین»
 *   and «تعمیرگاه» and «بیمهٔ خودرو» share no letters with «ماشین», so nothing
 *   derived from the spelling could ever bring them together, and
 *   docs/08-ai-layer.md §4 requires exactly that they be brought together.
 *
 * What this is not: a language model. It knows the vocabulary written into
 * ConceptLexicon and nothing else, so an unfamiliar merchant, an unusual
 * phrasing or a language nobody wrote keywords for lands in word-overlap
 * territory — which is where keyword search already was. That long tail is
 * what a real embedding model is bought for, and swapping one in is a config
 * key. Everything downstream of this class — the store, the ranker, the
 * endpoint — is identical either way.
 */
final class LocalEmbeddingProvider implements EmbeddingProvider
{
    /**
     * Weight of a concept feature relative to a word feature.
     *
     * Two documents about cars that share no words still score around
     * w²/(w²+1) of each other, so this number is the answer to "how much more
     * does meaning count than spelling here".
     */
    private const CONCEPT_WEIGHT = 2.0;

    public function name(): string
    {
        return 'local-hashed-v1';
    }

    public function dimensions(): int
    {
        return max(32, (int) config('ai.embeddings.dimensions', 256));
    }

    public function embed(string $text): array
    {
        $dimensions = $this->dimensions();
        $normalized = TextNormalizerBridge::normalize($text);

        if ($normalized === '') {
            return array_fill(0, $dimensions, 0.0);
        }

        // Only the buckets a feature actually lands in are carried around; the
        // dense vector is laid out once at the end, which is also the only
        // place its length is decided.
        $buckets = [];

        foreach ($this->tokens($normalized) as $token) {
            $this->add($buckets, $dimensions, 'w:'.$token, 1.0);
        }

        foreach (ConceptLexicon::conceptsIn($normalized) as $concept => $weight) {
            $this->add($buckets, $dimensions, 'c:'.$concept, self::CONCEPT_WEIGHT * $weight);
        }

        return Vector::normalize(array_map(
            static fn (int $index): float => $buckets[$index] ?? 0.0,
            range(0, $dimensions - 1),
        ));
    }

    public function embedBatch(array $texts): array
    {
        return array_map(fn (string $text): array => $this->embed($text), $texts);
    }

    /**
     * @return list<string>
     */
    private function tokens(string $normalized): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            $parts,
            // Single characters are almost always a stray letter or an
            // enumeration marker, and they collide with everything.
            static fn (string $token): bool => mb_strlen($token) > 1 && ! ConceptLexicon::isStopWord($token),
        ));
    }

    /**
     * Adds one signed feature to the bucket it hashes into.
     *
     * @param  array<int, float>  $buckets
     */
    private function add(array &$buckets, int $dimensions, string $feature, float $weight): void
    {
        $bucket = crc32($feature) % $dimensions;
        $sign = (crc32('sign:'.$feature) & 1) === 1 ? 1.0 : -1.0;

        $buckets[$bucket] = ($buckets[$bucket] ?? 0.0) + $sign * $weight;
    }
}
