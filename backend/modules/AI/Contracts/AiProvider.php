<?php

declare(strict_types=1);

namespace Modules\AI\Contracts;

use Modules\AI\Support\AiResponse;

/**
 * The one seam between the domain and whoever is doing the thinking.
 *
 * Everything model-specific lives behind this interface so swapping vendors —
 * or dropping to rules when no key is configured — never reaches an Action
 * (docs/08-ai-layer.md §7).
 */
interface AiProvider
{
    public function name(): string;

    /**
     * One completion turn.
     *
     * `$system` states the task and the rules; `$user` carries the payload,
     * whose untrusted parts are already delimited by PromptBuilder. `$tools`
     * are JSON-schema declarations — a provider that supports tool calling
     * answers with ToolCalls instead of prose.
     *
     * @param  list<array<string, mixed>>  $tools
     */
    public function complete(string $system, string $user, array $tools = []): AiResponse;

    /** Speech to text. The audio has already been normalised where possible. */
    public function transcribe(string $audioPath): string;

    /**
     * Structured fields off a receipt image, each with its own confidence.
     *
     * @return array{
     *   merchant?: array{value: string|null, confidence: float},
     *   occurred_at?: array{value: string|null, confidence: float},
     *   currency?: array{value: string|null, confidence: float},
     *   tax?: array{value: int|null, confidence: float},
     *   total?: array{value: int|null, confidence: float},
     *   items?: array{value: list<array{name: string, quantity: int, unit_price: int, line_total: int}>, confidence: float},
     *   raw_text?: string,
     * }
     */
    public function extractReceipt(string $imagePath): array;
}
