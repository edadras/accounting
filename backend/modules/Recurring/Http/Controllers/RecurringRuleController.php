<?php

declare(strict_types=1);

namespace Modules\Recurring\Http\Controllers;

use App\Core\Money\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Models\WorkspaceMember;
use Modules\Ledger\Models\Transaction;
use Modules\Recurring\Exceptions\RecurringException;
use Modules\Recurring\Models\RecurringRule;

final class RecurringRuleController
{
    public function index(): JsonResponse
    {
        $rules = RecurringRule::query()
            ->orderBy('next_run_at')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $rules->map(fn (RecurringRule $rule) => $this->present($rule))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertCanWrite($request);

        $data = $request->validate([
            'id' => ['sometimes', 'string', 'size:26'],
            'name' => ['nullable', 'string', 'max:120'],

            'template' => ['required', 'array'],
            'template.type' => ['required', Rule::in(Transaction::TYPES)],
            'template.account_id' => ['required', 'string', 'size:26'],
            'template.counter_account_id' => ['nullable', 'string', 'size:26'],
            'template.category_id' => ['nullable', 'string', 'size:26'],
            'template.amount' => ['required', 'integer', 'min:1'],
            'template.currency' => ['required', Rule::in(Currency::codes())],
            'template.description' => ['nullable', 'string', 'max:255'],
            'template.payee' => ['nullable', 'string', 'max:255'],
            'template.tags' => ['nullable', 'array'],

            'frequency' => ['required', Rule::in(RecurringRule::FREQUENCIES)],
            'interval' => ['nullable', 'integer', 'min:1', 'max:365'],
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
            'day_of_week' => ['nullable', 'integer', 'min:0', 'max:6'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'auto_post' => ['nullable', 'boolean'],
            'is_paused' => ['nullable', 'boolean'],
        ]);

        $rule = new RecurringRule;

        if (! empty($data['id'])) {
            $rule->id = $data['id'];
        }

        $rule->fill([
            'name' => $data['name'] ?? null,
            'template' => $data['template'],
            'frequency' => $data['frequency'],
            'interval' => $data['interval'] ?? 1,
            'day_of_month' => $data['day_of_month'] ?? null,
            'day_of_week' => $data['day_of_week'] ?? null,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'] ?? null,
            'auto_post' => $data['auto_post'] ?? true,
            'is_paused' => $data['is_paused'] ?? false,
        ]);

        $rule->save();

        return response()->json(['data' => $this->present($rule)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->assertCanWrite($request);

        $rule = $this->rule($id);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'ends_at' => ['nullable', 'date'],
            'auto_post' => ['sometimes', 'boolean'],
            'is_paused' => ['sometimes', 'boolean'],

            // The schedule and the template are editable too. Accepting only
            // the four fields above meant changing an amount or a frequency
            // required deleting the rule and building it again, which loses
            // its id and its posting history for what is an ordinary edit.
            'template' => ['sometimes', 'array'],
            'template.type' => ['required_with:template', Rule::in(Transaction::TYPES)],
            'template.account_id' => ['required_with:template', 'string', 'size:26'],
            'template.counter_account_id' => ['nullable', 'string', 'size:26'],
            'template.category_id' => ['nullable', 'string', 'size:26'],
            'template.amount' => ['required_with:template', 'integer', 'min:1'],
            'template.currency' => ['required_with:template', Rule::in(Currency::codes())],
            'template.description' => ['nullable', 'string', 'max:255'],
            'template.payee' => ['nullable', 'string', 'max:255'],
            'template.tags' => ['nullable', 'array'],

            'frequency' => ['sometimes', Rule::in(RecurringRule::FREQUENCIES)],
            'interval' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'day_of_month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:31'],
            'day_of_week' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:6'],
            'starts_at' => ['sometimes', 'date'],
        ]);

        $rule->fill($data);

        // A rule that has already run keeps its cursor: rewriting next_run_at
        // from a moved start date would either replay occurrences that were
        // posted or skip ones that were not.
        if (array_key_exists('starts_at', $data) && $rule->last_run_at === null) {
            $rule->next_run_at = $rule->starts_at;
        }

        $rule->save();

        return response()->json(['data' => $this->present($rule)]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->assertCanWrite($request);

        $this->rule($id)->delete();

        return response()->json(null, 204);
    }

    private function rule(string $id): RecurringRule
    {
        return RecurringRule::query()->findOr($id, callback: fn () => throw RecurringException::ruleNotFound($id));
    }

    private function assertCanWrite(Request $request): void
    {
        $member = $request->attributes->get('workspace_member');

        abort_unless($member instanceof WorkspaceMember && $member->canWrite(), 403);
    }

    /** @return array<string, mixed> */
    private function present(RecurringRule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'template' => $rule->template,
            'frequency' => $rule->frequency,
            'interval' => $rule->interval,
            'day_of_month' => $rule->day_of_month,
            'day_of_week' => $rule->day_of_week,
            'starts_at' => $rule->starts_at->toIso8601String(),
            'ends_at' => $rule->ends_at?->toIso8601String(),
            'next_run_at' => $rule->next_run_at?->toIso8601String(),
            'last_run_at' => $rule->last_run_at?->toIso8601String(),
            'auto_post' => $rule->auto_post,
            'is_paused' => $rule->is_paused,
        ];
    }
}
