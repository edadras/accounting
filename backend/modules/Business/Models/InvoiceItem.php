<?php

declare(strict_types=1);

namespace Modules\Business\Models;

use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Business\Exceptions\BusinessException;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

/**
 * A priced line of an invoice.
 *
 * `line_total` is quantity × unit_price before anything is taken off, so that
 * the invoice's subtotal is exactly the sum of its lines; `discount` is what
 * was taken off this line and `tax` is what that line was taxed, at its own
 * rate, on the difference.
 */
final class InvoiceItem extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasUlidKey;

    protected $fillable = [
        'workspace_id', 'invoice_id', 'description', 'quantity', 'unit_price',
        'discount', 'tax_rate', 'tax', 'line_total', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'string',
            'unit_price' => 'integer',
            'discount' => 'integer',
            'tax_rate' => 'string',
            'tax' => 'integer',
            'line_total' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function money(): Money
    {
        $invoice = $this->invoice;

        if ($invoice === null) {
            // A line carries no currency of its own, so an orphaned row cannot
            // be valued at all — better to say so than to invent a currency.
            throw BusinessException::invoiceNotFound((string) $this->invoice_id);
        }

        return Money::of((int) $this->line_total, $invoice->currency);
    }
}
