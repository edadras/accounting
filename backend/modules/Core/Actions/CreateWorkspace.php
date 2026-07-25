<?php

declare(strict_types=1);

namespace Modules\Core\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Support\AuditRecorder;
use Modules\Core\Models\Workspace;
use Modules\Core\Models\WorkspaceMember;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Database\Seeders\DefaultLedgerSeeder;

final readonly class CreateWorkspace
{
    public function __construct(
        private WorkspaceContext $context,
        private DefaultLedgerSeeder $seeder,
    ) {}

    public function handle(
        User $owner,
        string $name,
        string $type = 'personal',
        string $baseCurrency = 'IRR',
        string $locale = 'fa',
        string $timezone = 'Asia/Tehran',
    ): Workspace {
        return DB::transaction(function () use ($owner, $name, $type, $baseCurrency, $locale, $timezone): Workspace {
            $workspace = Workspace::query()->create([
                'owner_id' => $owner->id,
                'name' => $name,
                'type' => $type,
                'base_currency' => $baseCurrency,
                'locale' => $locale,
                'timezone' => $timezone,
                'calendar' => $locale === 'fa' ? 'jalali' : 'gregorian',
            ]);

            WorkspaceMember::query()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $owner->id,
                'role' => WorkspaceMember::ROLE_OWNER,
                'joined_at' => now(),
            ]);

            // An empty workspace is a dead end — the user opens it and has
            // nothing to record against. Seed a starter account and category
            // tree inside the new workspace's own scope.
            //
            // Seeding is not something a person did, and thirty category rows
            // would bury the first real entry, so the trail stays quiet for it.
            $this->context->runFor($workspace, fn () => app(AuditRecorder::class)
                ->withoutRecording(fn () => $this->seeder->seed($workspace)));

            return $workspace->refresh();
        });
    }
}
