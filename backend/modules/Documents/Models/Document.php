<?php

declare(strict_types=1);

namespace Modules\Documents\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

/**
 * A stored file — receipt, invoice, contract, photo, voice note, archive —
 * together with whatever text has been extracted from it.
 *
 * The blob lives on a disk; this row is the only thing the rest of the product
 * talks to, and it may be linked to any number of records (docs/02-modules.md §11).
 */
final class Document extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasUlidKey;
    use SoftDeletes;

    public const KIND_RECEIPT = 'receipt';

    public const KIND_INVOICE = 'invoice';

    public const KIND_CONTRACT = 'contract';

    public const KIND_PHOTO = 'photo';

    public const KIND_VOICE = 'voice';

    public const KIND_VIDEO = 'video';

    public const KIND_ARCHIVE = 'archive';

    public const KIND_OTHER = 'other';

    public const KINDS = [
        self::KIND_RECEIPT, self::KIND_INVOICE, self::KIND_CONTRACT, self::KIND_PHOTO,
        self::KIND_VOICE, self::KIND_VIDEO, self::KIND_ARCHIVE, self::KIND_OTHER,
    ];

    public const OCR_PENDING = 'pending';

    public const OCR_PROCESSING = 'processing';

    public const OCR_DONE = 'done';

    public const OCR_FAILED = 'failed';

    public const OCR_SKIPPED = 'skipped';

    public const OCR_STATUSES = [
        self::OCR_PENDING, self::OCR_PROCESSING, self::OCR_DONE,
        self::OCR_FAILED, self::OCR_SKIPPED,
    ];

    private const ARCHIVE_MIMES = [
        'application/zip',
        'application/x-zip-compressed',
        'application/x-rar-compressed',
        'application/x-7z-compressed',
        'application/gzip',
    ];

    protected $fillable = [
        'workspace_id', 'disk', 'path', 'original_name', 'mime', 'size',
        'kind', 'ocr_status', 'ocr_text', 'ocr_data', 'uploaded_by', 'checksum',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'ocr_data' => 'array',
        ];
    }

    /**
     * @return HasMany<Documentable, $this>
     */
    public function documentables(): HasMany
    {
        return $this->hasMany(Documentable::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Links this document to $record, or returns the link that already exists. */
    public function attachTo(Model $record): Documentable
    {
        return Documentable::query()->firstOrCreate([
            'document_id' => $this->id,
            'documentable_type' => $record->getMorphClass(),
            'documentable_id' => (string) $record->getKey(),
        ]);
    }

    public function detachFrom(Model $record): void
    {
        Documentable::query()
            ->where('document_id', $this->id)
            ->where('documentable_type', $record->getMorphClass())
            ->where('documentable_id', (string) $record->getKey())
            ->delete();
    }

    /**
     * @param  class-string<Model>  $type
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAttachedTo(Builder $query, string $type, string $id): Builder
    {
        return $query->whereHas(
            'documentables',
            fn (Builder $link) => $link
                ->where('documentable_type', $type)
                ->where('documentable_id', $id),
        );
    }

    /**
     * A best-effort kind from the media type. receipt/invoice/contract cannot be
     * derived from bytes — the user or the OCR pass says which it is.
     */
    public static function kindForMime(?string $mime): string
    {
        return match (true) {
            $mime === null => self::KIND_OTHER,
            str_starts_with($mime, 'image/') => self::KIND_PHOTO,
            str_starts_with($mime, 'video/') => self::KIND_VIDEO,
            str_starts_with($mime, 'audio/') => self::KIND_VOICE,
            in_array($mime, self::ARCHIVE_MIMES, true) => self::KIND_ARCHIVE,
            default => self::KIND_OTHER,
        };
    }
}
