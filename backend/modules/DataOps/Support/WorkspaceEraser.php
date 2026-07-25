<?php

declare(strict_types=1);

namespace Modules\DataOps\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Core\Models\Workspace;

/**
 * Removes a workspace and everything filed under it, for real.
 *
 * Not a soft delete: this runs at the end of the deletion grace period, and
 * docs/07-security.md §8 promises "real erasure after the stated retention
 * period" — a `deleted_at` stamp would not be that.
 *
 * Tables are discovered rather than listed. A module added next month brings a
 * table with a `workspace_id`, and a hand-maintained list is exactly the kind
 * of thing that would silently keep that module's rows after the erasure.
 */
final class WorkspaceEraser
{
    /** @var list<string> */
    private const KEEP = ['workspaces'];

    public function erase(Workspace $workspace): void
    {
        $this->deleteStoredFiles($workspace);

        // Every table that references another is emptied in the same sweep, so
        // the order rows disappear in would otherwise trip a foreign key that
        // is about to be satisfied a statement later.
        Schema::withoutForeignKeyConstraints(function () use ($workspace): void {
            foreach ($this->scopedTables() as $table) {
                DB::table($table)->where('workspace_id', $workspace->id)->delete();
            }

            DB::table('workspaces')->where('id', $workspace->id)->delete();
        });
    }

    /** @return list<string> */
    private function scopedTables(): array
    {
        $tables = [];

        foreach (Schema::getTableListing() as $name) {
            // Postgres returns names qualified with their schema.
            $table = Str::afterLast($name, '.');

            if (in_array($table, self::KEEP, true)) {
                continue;
            }

            if (Schema::hasColumn($table, 'workspace_id')) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /** The blobs behind the rows: deleting the row alone leaves the receipt. */
    private function deleteStoredFiles(Workspace $workspace): void
    {
        if (! Schema::hasTable('documents')) {
            return;
        }

        $documents = DB::table('documents')
            ->where('workspace_id', $workspace->id)
            ->get(['disk', 'path']);

        foreach ($documents as $document) {
            $disk = (string) ($document->disk ?? 'local');
            $path = (string) ($document->path ?? '');

            if ($path !== '' && Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }
        }

        $exportDisk = (string) config('dataops.exports.disk');
        $exportDirectory = trim((string) config('dataops.exports.directory'), '/')."/{$workspace->id}";

        if (Storage::disk($exportDisk)->exists($exportDirectory)) {
            Storage::disk($exportDisk)->deleteDirectory($exportDirectory);
        }
    }
}
