<?php

declare(strict_types=1);

namespace Modules\Business\Models;

use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

/**
 * A job the business runs at a profit or a loss: a build, a contract, a
 * campaign. Invoices point at it, and so do ledger transactions carrying its
 * tag, which is what makes ProjectProfitability answerable.
 */
final class Project extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasUlidKey;
    use SoftDeletes;

    public const STATUS_PLANNED = 'planned';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_ACTIVE,
        self::STATUS_PAUSED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Prefix of the transaction tag that attributes a posting to a project.
     *
     * A tag rather than a foreign key on `transactions`: the Ledger module owns
     * that table and must not grow a column for every module that wants to
     * point at a transaction.
     */
    public const TAG_PREFIX = 'project:';

    protected $fillable = [
        'workspace_id', 'name', 'contact_id', 'status',
        'budget_amount', 'currency', 'starts_at', 'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'budget_amount' => 'integer',
            'starts_at' => 'date',
            'ends_at' => 'date',
            'version' => 'integer',
        ];
    }

    public static function tagFor(string $projectId): string
    {
        return self::TAG_PREFIX.$projectId;
    }

    public function tag(): string
    {
        return self::tagFor($this->id);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function budget(): Money
    {
        return Money::of((int) $this->budget_amount, $this->currency);
    }
}
