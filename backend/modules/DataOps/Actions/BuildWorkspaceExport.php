<?php

declare(strict_types=1);

namespace Modules\DataOps\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use League\Csv\Writer;
use Modules\Audit\Support\AuditRecorder;
use Modules\Core\Models\Workspace;
use Modules\DataOps\Models\DataExport;
use Modules\DataOps\Support\ExportSchema;
use Throwable;
use ZipArchive;

/**
 * Builds the archive behind a DataExport: CSV per table, the same rows again as
 * JSON, and the stored documents themselves (docs/07-security.md §7 and §8).
 *
 * Every query here is filtered on the export's own workspace id rather than on
 * the ambient scope: an export that quietly included a second set of books
 * would hand one tenant another's ledger in a file they can open offline.
 */
final readonly class BuildWorkspaceExport
{
    public function __construct(private AuditRecorder $audit) {}

    public function handle(DataExport $export): DataExport
    {
        $workspace = Workspace::query()->find($export->workspace_id);

        if ($workspace === null) {
            return $this->fail($export, 'The workspace no longer exists.');
        }

        $export->forceFill(['status' => DataExport::STATUS_RUNNING])->save();

        try {
            $tables = $this->tables($workspace);
            $documents = $this->documents($workspace);

            $archive = $this->writeArchive($workspace, $tables, $documents);
            $disk = (string) config('dataops.exports.disk');
            $path = trim((string) config('dataops.exports.directory'), '/')
                ."/{$workspace->id}/{$export->id}.zip";

            $stream = fopen($archive, 'rb');
            Storage::disk($disk)->writeStream($path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            @unlink($archive);

            $export->forceFill([
                'status' => DataExport::STATUS_READY,
                'disk' => $disk,
                'path' => $path,
                'size' => (int) Storage::disk($disk)->size($path),
                'error' => null,
                'expires_at' => now()->addDays((int) config('dataops.exports.expires_after_days')),
            ])->save();

            $this->audit->record(
                action: 'data_export.completed',
                subject: $export,
                after: ['size' => $export->size, 'tables' => array_map('count', $tables)],
                workspaceId: $workspace->id,
            );

            return $export;
        } catch (Throwable $e) {
            $this->fail($export, $e->getMessage());

            throw $e;
        }
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function tables(Workspace $workspace): array
    {
        $tables = [];

        foreach (ExportSchema::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'workspace_id')) {
                continue;
            }

            $query = DB::table($table)->where('workspace_id', $workspace->id);

            if (Schema::hasColumn($table, 'deleted_at')) {
                $query->whereNull('deleted_at');
            }

            $tables[$table] = $query
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => ExportSchema::present(
                    $table,
                    (array) $row,
                    $workspace->base_currency,
                ))
                ->all();
        }

        return $tables;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function documents(Workspace $workspace): array
    {
        if (! Schema::hasTable('documents')) {
            return [];
        }

        return DB::table('documents')
            ->where('workspace_id', $workspace->id)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $tables
     * @param  list<array<string, mixed>>  $documents
     * @return string the local path of the finished zip
     */
    private function writeArchive(Workspace $workspace, array $tables, array $documents): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'finora-export-');

        if ($temporary === false) {
            throw new \RuntimeException('Could not open a temporary file for the export.');
        }

        $zip = new ZipArchive;
        $zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($tables as $table => $rows) {
            $zip->addFromString("{$table}.csv", $this->csv($table, $rows));
        }

        $manifest = [];

        foreach ($documents as $document) {
            $entry = $this->addDocument($zip, $document);

            $manifest[] = [
                'id' => $document['id'],
                'original_name' => $document['original_name'],
                'mime' => $document['mime'],
                'size' => $document['size'],
                'checksum' => $document['checksum'] ?? null,
                'file' => $entry,
            ];
        }

        $zip->addFromString('data.json', (string) json_encode([
            'exported_at' => now()->toIso8601String(),
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'type' => $workspace->type,
                'base_currency' => $workspace->base_currency,
                'timezone' => $workspace->timezone,
                'locale' => $workspace->locale,
            ],
            'tables' => $tables,
            'documents' => $manifest,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $zip->close();

        return $temporary;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function csv(string $table, array $rows): string
    {
        $writer = Writer::createFromString();

        // An empty table still gets its header: a file with column names and no
        // rows says "nothing here", an empty file says "something went wrong".
        $header = $rows === [] ? Schema::getColumnListing($table) : array_keys($rows[0]);

        $writer->insertOne($header);

        foreach ($rows as $row) {
            $writer->insertOne(array_map(
                fn (mixed $value): string => match (true) {
                    $value === null => '',
                    is_bool($value) => $value ? '1' : '0',
                    is_scalar($value) => (string) $value,
                    default => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
                },
                array_values($row),
            ));
        }

        return $writer->toString();
    }

    /**
     * @param  array<string, mixed>  $document
     * @return string|null the path inside the archive, or null if the blob is gone
     */
    private function addDocument(ZipArchive $zip, array $document): ?string
    {
        $disk = (string) ($document['disk'] ?? 'local');
        $path = (string) ($document['path'] ?? '');

        if ($path === '' || ! Storage::disk($disk)->exists($path)) {
            return null;
        }

        // The id keeps two receipts photographed on the same phone from
        // colliding on "IMG_0001.jpg".
        $name = basename((string) $document['original_name']);
        $entry = "documents/{$document['id']}-{$name}";

        $zip->addFromString($entry, (string) Storage::disk($disk)->get($path));

        return $entry;
    }

    private function fail(DataExport $export, string $reason): DataExport
    {
        $export->forceFill([
            'status' => DataExport::STATUS_FAILED,
            'error' => mb_substr($reason, 0, 1000),
        ])->save();

        $this->audit->record(
            action: 'data_export.failed',
            subject: $export,
            after: ['error' => $export->error],
            workspaceId: $export->workspace_id,
        );

        return $export;
    }
}
