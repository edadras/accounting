<?php

declare(strict_types=1);

namespace Modules\AI\Contracts;

/**
 * Text in, a vector out — the second seam of the AI layer, alongside
 * AiProvider.
 *
 * It is separate from AiProvider on purpose. Embeddings are bought from a
 * different model, priced differently, and cached forever (docs/08-ai-layer.md
 * §7), and a workspace that has switched the chat model off still wants its
 * search to work. Keeping the two interfaces apart means the local
 * implementation can be the default for embeddings while a gateway answers
 * chat, or the other way round.
 *
 * Every implementation returns L2-normalised vectors of exactly
 * `dimensions()` floats, so a stored vector can be compared with a query
 * vector by dot product alone.
 */
interface EmbeddingProvider
{
    /** Identifies the model that produced a vector; stored beside it. */
    public function name(): string;

    public function dimensions(): int;

    /** @return list<float> */
    public function embed(string $text): array;

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embedBatch(array $texts): array;
}
