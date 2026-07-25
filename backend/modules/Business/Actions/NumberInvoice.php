<?php

declare(strict_types=1);

namespace Modules\Business\Actions;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Modules\Business\Exceptions\BusinessException;
use Modules\Business\Models\Invoice;
use Modules\Business\Models\InvoiceSequence;
use Modules\Core\Support\WorkspaceContext;

/**
 * Allocates the next invoice number for the active workspace.
 *
 * Numbering is per workspace, so two companies on the same installation both
 * legitimately hold INV-2026-0001. Within one workspace a repeat is a legal
 * problem, not a cosmetic one, so the counter lives in its own row and is read
 * under a lock inside a transaction: `max(number) + 1` would hand the same
 * number to two requests that happen to arrive together.
 */
final readonly class NumberInvoice
{
    /** How many taken numbers to skip past before giving up. */
    private const MAX_ATTEMPTS = 100;

    public function __construct(private WorkspaceContext $context) {}

    public function handle(
        string $direction = Invoice::DIRECTION_SALE,
        ?DateTimeInterface $issuedOn = null,
    ): string {
        $settings = $this->settings();
        $issuedOn ??= new DateTimeImmutable;

        $prefix = $settings['prefix'][$direction] ?? reset($settings['prefix']);
        $year = $issuedOn->format('Y');
        $month = $issuedOn->format('m');

        $scope = match ($settings['reset']) {
            'never' => $prefix,
            'month' => $prefix.':'.$year.$month,
            default => $prefix.':'.$year,
        };

        return DB::transaction(function () use ($settings, $scope, $prefix, $year, $month): string {
            $sequence = $this->lockSequence($scope);

            for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
                $number = $this->format($settings, $prefix, $year, $month, $sequence->next_value);

                $sequence->next_value++;
                $sequence->save();

                // A number typed in by hand can sit where the counter is about
                // to land; step over it rather than colliding with the unique
                // index at insert time.
                $taken = Invoice::query()
                    ->withTrashed()
                    ->where('number', $number)
                    ->exists();

                if (! $taken) {
                    return $number;
                }
            }

            throw BusinessException::couldNotAllocateInvoiceNumber($scope);
        });
    }

    private function lockSequence(string $scope): InvoiceSequence
    {
        $sequence = InvoiceSequence::query()->where('scope', $scope)->lockForUpdate()->first();

        if ($sequence !== null) {
            return $sequence;
        }

        // First invoice of this scope. firstOrCreate leans on the unique index
        // so a concurrent creator loses the race rather than making a second
        // counter; then re-read it under the lock like everyone else.
        $created = InvoiceSequence::query()->firstOrCreate(['scope' => $scope], ['next_value' => 1]);

        return InvoiceSequence::query()->whereKey($created->id)->lockForUpdate()->firstOrFail();
    }

    /** @param  array<string, mixed>  $settings */
    private function format(array $settings, string $prefix, string $year, string $month, int $value): string
    {
        return str_replace(
            ['{prefix}', '{year}', '{month}', '{sequence}'],
            [$prefix, $year, $month, str_pad((string) $value, (int) $settings['padding'], '0', STR_PAD_LEFT)],
            (string) $settings['format'],
        );
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        $defaults = config('business.invoice_number');
        $settings = $this->context->require()->settings ?? [];
        $overrides = $settings['invoice_number'] ?? [];

        $settings = array_replace($defaults, is_array($overrides) ? $overrides : []);
        $settings['prefix'] = array_replace($defaults['prefix'], $settings['prefix'] ?? []);

        return $settings;
    }
}
