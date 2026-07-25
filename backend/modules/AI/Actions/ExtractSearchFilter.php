<?php

declare(strict_types=1);

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Contracts\AiProvider;
use Modules\AI\Support\AiSwitch;
use Modules\AI\Support\PromptBuilder;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Models\Transaction;

/**
 * Step 1 of the semantic search pipeline in docs/08-ai-layer.md §4: turn a
 * sentence into a structured filter.
 *
 * «هزینه‌های سفر استانبول پارسال» carries two facts a vector cannot express —
 * that these are expenses, and that "پارسال" is a specific pair of dates — and
 * a filter is both cheaper and exact where similarity is neither. What is left
 * over, the part that is actually about meaning, is what the embeddings rank.
 *
 * Everything the model returns is treated as a suggestion and validated here:
 * a type that is not one of the three the ledger has, or a date that does not
 * parse, is dropped rather than passed on. And when the workspace has switched
 * AI off, this returns an empty filter instead of throwing — search must keep
 * working with the AI layer disabled (docs/07-security.md §5.6), it simply
 * loses the date and type narrowing.
 */
final readonly class ExtractSearchFilter
{
    public function __construct(
        private WorkspaceContext $context,
        private AiProvider $provider,
    ) {}

    /**
     * @return array{type: string|null, from: string|null, to: string|null, provider: string|null}
     */
    public function handle(string $query): array
    {
        $empty = ['type' => null, 'from' => null, 'to' => null, 'provider' => null];

        if (trim($query) === '' || ! AiSwitch::enabledFor($this->context->get())) {
            return $empty;
        }

        $system = PromptBuilder::system(PromptBuilder::TASK_SEARCH_FILTER, [
            'Return only JSON with keys: type, from, to.',
            'type is one of '.implode(', ', Transaction::TYPES).', or null when the query does not say.',
            'from and to are YYYY-MM-DD, or null when the query names no period.',
            'Never invent a period the query does not imply.',
        ]);

        $payload = PromptBuilder::make()
            ->contextJson('workspace_profile', [
                'base_currency' => $this->context->baseCurrency(),
                'today' => CarbonImmutable::now()->toDateString(),
            ])
            ->data('query', $query)
            ->toString();

        $decoded = $this->provider->complete($system, $payload)->json();

        $type = (string) ($decoded['type'] ?? '');
        $from = $this->date($decoded['from'] ?? null);
        $to = $this->date($decoded['to'] ?? null);

        return [
            'type' => in_array($type, Transaction::TYPES, true) ? $type : null,

            // A reversed range is a model error, not a request for nothing:
            // swapping is what the user meant either way.
            'from' => $from !== null && $to !== null && $from > $to ? $to : $from,
            'to' => $from !== null && $to !== null && $from > $to ? $from : $to,
            'provider' => $this->provider->name(),
        ];
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
