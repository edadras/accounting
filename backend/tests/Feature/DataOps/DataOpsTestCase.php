<?php

declare(strict_types=1);

namespace Tests\Feature\DataOps;

use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use League\Csv\Reader;
use Modules\Core\Models\Workspace;
use Modules\Core\Models\WorkspaceMember;
use Modules\DataOps\Models\DataExport;
use Modules\DataOps\Providers\DataOpsServiceProvider;
use Tests\Feature\LedgerTestCase;
use ZipArchive;

/**
 * DataOps is not in bootstrap/providers.php yet — that line lands when the
 * milestone is wired into the app — so these tests bring the module's own
 * migrations, routes and commands rather than depending on the wiring having
 * happened.
 */
abstract class DataOpsTestCase extends LedgerTestCase
{
    private static bool $dataOpsTablesMigrated = false;

    protected function setUp(): void
    {
        if (! self::$dataOpsTablesMigrated) {
            // The whole suite shares one in-memory database, migrated once by
            // whichever test class ran first — and that class had no reason to
            // register this provider, so `data_exports` and the deletion
            // columns on `users` are missing. Asking for one more migration
            // run, from a class that does register it, is what puts them there
            // without touching bootstrap/providers.php.
            RefreshDatabaseState::$migrated = false;
            self::$dataOpsTablesMigrated = true;
        }

        parent::setUp();
    }

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if ($app->getProvider(DataOpsServiceProvider::class) === null) {
            $app->register(DataOpsServiceProvider::class);
        }

        return $app;
    }

    /** @return array<string, string> */
    protected function headers(Workspace $workspace): array
    {
        return ['X-Workspace-Id' => $workspace->id, 'Accept' => 'application/json'];
    }

    protected function addMember(Workspace $workspace, User $user, string $role): WorkspaceMember
    {
        return WorkspaceMember::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
        ]);
    }

    protected function latestExport(Workspace $workspace): DataExport
    {
        return $this->inWorkspace(
            $workspace,
            fn () => DataExport::query()->orderByDesc('created_at')->orderByDesc('id')->firstOrFail(),
        );
    }

    /**
     * Everything inside the produced archive, keyed by its name.
     *
     * @return array<string, string>
     */
    protected function archiveContents(DataExport $export): array
    {
        $bytes = Storage::disk((string) $export->disk)->get((string) $export->path);

        $temporary = tempnam(sys_get_temp_dir(), 'finora-test-');
        file_put_contents($temporary, $bytes);

        $zip = new ZipArchive;
        $zip->open($temporary);

        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $entries[$name] = (string) $zip->getFromIndex($i);
        }

        $zip->close();
        @unlink($temporary);

        return $entries;
    }

    /**
     * @return list<array<string, string>>
     */
    protected function csvRows(string $csv): array
    {
        $reader = Reader::createFromString($csv);
        $reader->setHeaderOffset(0);

        return iterator_to_array($reader->getRecords(), false);
    }

    /**
     * `artisan()` hands back a bare exit code instead of a command when console
     * output is not mocked. These tests always drive the mocked command, so the
     * distinction is settled once here rather than at every call site.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function runArtisan(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}
