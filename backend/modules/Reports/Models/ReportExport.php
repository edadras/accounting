<?php

declare(strict_types=1);

namespace Modules\Reports\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;
use Modules\Reports\Support\ExportFormat;
use Modules\Reports\Support\ExportLocale;

/**
 * One requested export of one report.
 *
 * Workspace-scoped through the global scope, which is what makes a download
 * safe: the id in the URL is looked up inside the caller's workspace, so
 * another workspace's export is simply not there to be found.
 */
final class ReportExport extends Model
{
    use BelongsToWorkspace;
    use HasUlidKey;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $table = 'report_exports';

    protected $fillable = [
        'workspace_id', 'report_type', 'format', 'locale', 'filters',
        'status', 'disk', 'path', 'filename', 'size', 'row_count',
        'error', 'requested_by', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'size' => 'integer',
            'row_count' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function exportFormat(): ExportFormat
    {
        return ExportFormat::from($this->format);
    }

    public function exportLocale(): ExportLocale
    {
        return ExportLocale::parse($this->locale);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY && $this->path !== null;
    }
}
