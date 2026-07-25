<?php

declare(strict_types=1);

namespace Modules\Business\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Business\Models\Contact;
use Modules\Core\Models\WorkspaceMember;

final class ContactController
{
    public function index(Request $request): JsonResponse
    {
        $query = Contact::query()->orderBy('name');

        if ($type = $request->query('type')) {
            $query->ofType((string) $type);
        }

        if ($search = $request->query('q')) {
            $needle = '%'.$search.'%';
            $query->where(function ($builder) use ($needle): void {
                $builder->where('name', 'like', $needle)
                    ->orWhere('phone', 'like', $needle)
                    ->orWhere('email', 'like', $needle);
            });
        }

        $perPage = min((int) $request->query('per_page', 50), 200);
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => array_map($this->present(...), $page->items()),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $member = $request->attributes->get('workspace_member');

        abort_unless($member instanceof WorkspaceMember && $member->canWrite(), 403);

        $data = $request->validate([
            'id' => ['sometimes', 'string', 'size:26'],
            'type' => ['required', Rule::in(Contact::TYPES)],
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:160'],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:1000'],
        ]);

        $contact = new Contact;

        if (! empty($data['id'])) {
            $contact->id = $data['id'];
        }

        $contact->fill($data)->save();

        return response()->json(['data' => $this->present($contact)], 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->present(Contact::query()->findOrFail($id))]);
    }

    /** @return array<string, mixed> */
    private function present(Contact $contact): array
    {
        return [
            'id' => $contact->id,
            'type' => $contact->type,
            'name' => $contact->name,
            'phone' => $contact->phone,
            'email' => $contact->email,
            'tax_id' => $contact->tax_id,
            'address' => $contact->address,
            'version' => $contact->version,
            'created_at' => $contact->created_at?->toIso8601String(),
        ];
    }
}
