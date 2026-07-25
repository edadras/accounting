<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;
use Modules\Core\Models\Workspace;
use Modules\Payroll\Support\TaxRuleSet;

/**
 * One country's payroll schedule as a workspace stored it.
 *
 * The rules live in a JSON column rather than in code because payroll tax is
 * country law: a workspace in a country we have never heard of configures its
 * own brackets and is paid correctly without a release.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $country
 * @property string $name
 * @property string|null $currency
 * @property CarbonImmutable $effective_from
 * @property bool $is_active
 * @property array<string, mixed> $rules
 * @property int $version
 */
final class PayrollTaxRule extends Model
{
    use BelongsToWorkspace;
    use HasUlidKey;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'country', 'name', 'currency', 'effective_from', 'is_active', 'rules',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date',
            'is_active' => 'boolean',
            'rules' => 'array',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function toRuleSet(): TaxRuleSet
    {
        // The columns win over anything the JSON repeats: the row is the record.
        return TaxRuleSet::fromArray(
            array_merge($this->rules, ['name' => $this->name, 'currency' => $this->currency]),
            $this->country,
            TaxRuleSet::SOURCE_WORKSPACE,
        );
    }

    /**
     * @param  Builder<PayrollTaxRule>  $query
     * @return Builder<PayrollTaxRule>
     */
    public function scopeInForceFor(Builder $query, string $country, string $on): Builder
    {
        return $query
            ->where('country', strtoupper($country))
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $on)
            ->orderByDesc('effective_from');
    }
}
