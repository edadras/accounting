<?php

declare(strict_types=1);

namespace Modules\AI\Providers\Gateway;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Modules\AI\Contracts\EmbeddingProvider;
use Modules\AI\Exceptions\AiException;
use Modules\AI\Support\TextNormalizerBridge;
use Modules\AI\Support\Vector;

/**
 * Embeddings from an OpenAI-compatible `/embeddings` endpoint.
 *
 * Same reasoning as OpenAiProvider: one POST, spoken over the HTTP client
 * Laravel already ships, so any server offering the same route — a proxy, a
 * self-hosted model, another vendor — works by changing `ai.embeddings.*`.
 *
 * The text is normalised before it is sent. That is not an optimisation: the
 * whole index has to be embedded through one consistent pre-processing step or
 * a query folded one way stops matching a document folded another, and it also
 * means the cache key is stable across keyboards.
 */
final class HttpEmbeddingProvider implements EmbeddingProvider
{
    public function name(): string
    {
        return (string) config('ai.embeddings.model', 'text-embedding-3-small');
    }

    public function dimensions(): int
    {
        return max(1, (int) config('ai.embeddings.dimensions', 1536));
    }

    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0] ?? array_fill(0, $this->dimensions(), 0.0);
    }

    public function embedBatch(array $texts): array
    {
        $inputs = array_map(
            static fn (string $text): string => TextNormalizerBridge::normalize($text),
            $texts,
        );

        if ($inputs === []) {
            return [];
        }

        $response = $this->client()->asJson()->post($this->url(), [
            'model' => $this->name(),
            'input' => $inputs,
            'dimensions' => $this->dimensions(),
        ]);

        if ($response->failed()) {
            throw AiException::providerUnavailable('embeddings', 'HTTP '.$response->status());
        }

        $rows = $response->json('data');

        if (! is_array($rows) || count($rows) !== count($inputs)) {
            throw AiException::providerUnavailable('embeddings', 'malformed response');
        }

        // Ordered by `index` rather than by arrival: the API documents that it
        // may return them out of order, and a mis-paired vector would be a
        // silent, permanent wrong answer rather than a visible failure.
        usort($rows, static fn (mixed $a, mixed $b): int => (int) ($a['index'] ?? 0) <=> (int) ($b['index'] ?? 0));

        return array_map(
            static fn (mixed $row): array => Vector::normalize(
                array_values(array_map(
                    static fn (mixed $value): float => (float) $value,
                    is_array($row['embedding'] ?? null) ? $row['embedding'] : [],
                )),
            ),
            $rows,
        );
    }

    private function client(): PendingRequest
    {
        $key = (string) (config('ai.embeddings.key') ?? config('ai.key'));

        if ($key === '') {
            throw AiException::providerUnavailable('embeddings', 'no API key configured');
        }

        return Http::withToken($key)
            ->timeout((int) config('ai.timeout', 30))
            ->retry((int) config('ai.retries', 2), 200, throw: false);
    }

    private function url(): string
    {
        $base = (string) (config('ai.embeddings.base_url') ?: config('ai.base_url'));

        return rtrim($base, '/').'/'.ltrim((string) config('ai.embeddings.endpoint', 'embeddings'), '/');
    }
}
