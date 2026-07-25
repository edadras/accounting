<?php

declare(strict_types=1);

namespace Modules\AI\Actions;

use Modules\AI\Contracts\AiProvider;
use Modules\AI\Support\PromptBuilder;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;

/**
 * Which category and which account a draft should point at.
 *
 * Shared by the text, voice and receipt pipelines so all three suggest the same
 * way — and so the rule that a suggested category is re-read through the
 * workspace-scoped model is written once instead of three times.
 */
final readonly class SuggestClassification
{
    private const CATEGORY_CANDIDATES = 300;

    public function __construct(private AiProvider $provider) {}

    /**
     * The model's only job in the drafting pipelines: pick a label.
     *
     * It chooses from a list the server assembled from this workspace's own
     * categories, and whatever it returns is looked up again through the scoped
     * model before it is believed.
     *
     * @return array{id: string, path: string, confidence: float, reason: string}|null
     */
    public function category(string $description, string $type): ?array
    {
        if (trim($description) === '') {
            return null;
        }

        // A JSON array, not an object: the block is a numbered list of choices
        // and the model is asked to pick one, so the keys carry no meaning.
        $candidates = array_values(Category::query()
            ->whereIn('type', [$type, 'both'])
            ->orderBy('depth')
            ->limit(self::CATEGORY_CANDIDATES)
            ->get(['id', 'name', 'path'])
            ->map(static fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'path' => $category->path,
            ])->all());

        if ($candidates === []) {
            return null;
        }

        $answer = $this->provider->complete(
            PromptBuilder::system(PromptBuilder::TASK_CATEGORIZE, [
                'Choose exactly one id from the categories block, or null when none fits.',
                'Answer with JSON: {"category_id": string|null, "path": string|null, "confidence": number, "reason": string}.',
            ]),
            PromptBuilder::make()
                ->contextJson('categories', $candidates)
                ->data('description', $description)
                ->toString(),
        )->json();

        $id = $answer['category_id'] ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        $category = Category::query()->find($id);

        if ($category === null) {
            return null;
        }

        return [
            'id' => (string) $category->id,
            'path' => (string) $category->path,
            'confidence' => round((float) ($answer['confidence'] ?? 0.5), 3),
            'reason' => (string) ($answer['reason'] ?? ''),
        ];
    }

    /**
     * The account the money most likely moved through: the one in that
     * currency the user actually uses.
     *
     * @return array{id: string, name: string, currency: string, reason: string}|null
     */
    public function account(string $currency): ?array
    {
        $account = Account::query()
            ->where('currency', $currency)
            ->whereNull('archived_at')
            ->withCount('transactions')
            ->orderByDesc('transactions_count')
            ->orderBy('sort_order')
            ->first();

        if ($account === null) {
            return null;
        }

        return [
            'id' => (string) $account->id,
            'name' => (string) $account->name,
            'currency' => (string) $account->currency,
            'reason' => 'most_used_'.$currency.'_account',
        ];
    }
}
