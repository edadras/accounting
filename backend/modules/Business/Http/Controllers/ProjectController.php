<?php

declare(strict_types=1);

namespace Modules\Business\Http\Controllers;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Business\Http\Concerns\PresentsMoney;
use Modules\Business\Models\Project;
use Modules\Business\Queries\ProjectProfitability;
use Modules\Core\Models\WorkspaceMember;

final class ProjectController
{
    use PresentsMoney;

    public function index(Request $request): JsonResponse
    {
        $projects = Project::query()
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $projects->map($this->present(...))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $member = $request->attributes->get('workspace_member');

        abort_unless($member instanceof WorkspaceMember && $member->canWrite(), 403);

        $data = $request->validate([
            'id' => ['sometimes', 'string', 'size:26'],
            'name' => ['required', 'string', 'max:160'],
            'contact_id' => ['nullable', 'string', 'size:26', 'exists:contacts,id'],
            'status' => ['nullable', Rule::in(Project::STATUSES)],
            'budget_amount' => ['nullable', 'integer', 'min:0'],
            'currency' => ['required', 'string', Rule::in(Currency::codes())],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        $project = new Project;

        if (! empty($data['id'])) {
            $project->id = $data['id'];
        }

        $project->fill($data);
        $project->status ??= Project::STATUS_ACTIVE;
        $project->budget_amount ??= 0;
        $project->save();

        return response()->json(['data' => $this->present($project)], 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->present(Project::query()->findOrFail($id))]);
    }

    public function profitability(string $id, ProjectProfitability $profitability): JsonResponse
    {
        $report = $profitability->forProject($id);

        return response()->json([
            'data' => array_map(
                fn (mixed $value) => $value instanceof Money ? $this->presentMoney($value) : $value,
                $report,
            ),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'contact_id' => $project->contact_id,
            'status' => $project->status,
            'budget' => $this->presentMoney($project->budget()),
            'currency' => $project->currency,
            'starts_at' => $project->starts_at?->toDateString(),
            'ends_at' => $project->ends_at?->toDateString(),
            'version' => $project->version,
        ];
    }
}
