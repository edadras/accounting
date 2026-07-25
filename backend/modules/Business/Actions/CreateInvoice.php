<?php

declare(strict_types=1);

namespace Modules\Business\Actions;

use App\Core\Money\Currency;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Modules\Business\Exceptions\BusinessException;
use Modules\Business\Models\Contact;
use Modules\Business\Models\Invoice;
use Modules\Business\Models\Project;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Actions\ExchangeRateResolver;

/**
 * Issues an invoice: numbers it, prices it, and writes it with its lines.
 *
 * The number is allocated inside the same transaction as the insert, so a
 * failure later on does not burn a number that then shows up as a gap in the
 * books.
 *
 * @phpstan-type InvoicePayload array{
 *   id?: string,
 *   number?: string|null,
 *   direction?: string,
 *   contact_id?: string|null,
 *   project_id?: string|null,
 *   issue_date?: DateTimeInterface|string|null,
 *   due_date?: DateTimeInterface|string|null,
 *   currency: string,
 *   fx_rate?: float|string|null,
 *   discount?: int|null,
 *   status?: string|null,
 *   notes?: string|null,
 *   items: list<array<string, mixed>>,
 * }
 */
final readonly class CreateInvoice
{
    public function __construct(
        private WorkspaceContext $context,
        private BuildInvoice $build,
        private NumberInvoice $number,
        private ExchangeRateResolver $rates,
    ) {}

    /**
     * @param  InvoicePayload  $data
     */
    public function handle(array $data): Invoice
    {
        $workspace = $this->context->require();
        $direction = $data['direction'] ?? Invoice::DIRECTION_SALE;

        if (! in_array($direction, Invoice::DIRECTIONS, true)) {
            throw BusinessException::unknownInvoiceDirection($direction);
        }

        $contactId = $this->resolveContactId($data['contact_id'] ?? null);
        $projectId = $this->resolveProjectId($data['project_id'] ?? null);

        $totals = $this->build->handle($data);
        $issueDate = $this->toDate($data['issue_date'] ?? null) ?? new DateTimeImmutable;
        $dueDate = $this->toDate($data['due_date'] ?? null);

        $baseCurrency = Currency::of($workspace->base_currency);
        $rate = $data['fx_rate'] ?? null;
        $rate = $rate !== null ? (string) $rate : $this->rates->rate($totals->currency, $baseCurrency);
        $baseTotal = $totals->total->convertTo($baseCurrency, $rate);

        return DB::transaction(function () use (
            $data, $direction, $contactId, $projectId, $totals,
            $issueDate, $dueDate, $baseCurrency, $baseTotal, $rate
        ): Invoice {
            $number = $data['number'] ?? null;

            if ($number === null) {
                $number = $this->number->handle($direction, $issueDate);
            } elseif (Invoice::query()->withTrashed()->where('number', $number)->exists()) {
                throw BusinessException::duplicateInvoiceNumber($number);
            }

            $invoice = new Invoice;

            if (! empty($data['id'])) {
                // Client-generated ULID: an invoice drafted offline keeps the
                // identity it was created with.
                $invoice->id = $data['id'];
            }

            $invoice->fill([
                'number' => $number,
                'contact_id' => $contactId,
                'project_id' => $projectId,
                'direction' => $direction,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'currency' => $totals->currency->code,
                'fx_rate' => $rate,
                'base_total' => $baseTotal->minorUnits,
                'base_currency' => $baseCurrency->code,
                'status' => $this->resolveStatus($data['status'] ?? null),
                'notes' => $data['notes'] ?? null,
            ] + $totals->toAttributes());

            $invoice->save();

            foreach ($totals->lines as $line) {
                $invoice->items()->create($line->toAttributes() + [
                    'workspace_id' => $invoice->workspace_id,
                ]);
            }

            $saved = $invoice->fresh(['items', 'contact', 'project']);

            if ($saved === null) {
                // The invoice we just wrote is gone; the in-memory copy would
                // report line items that are no longer on the books.
                throw BusinessException::invoiceNotFound($invoice->id);
            }

            return $saved;
        });
    }

    private function resolveStatus(?string $status): string
    {
        // Only the two statuses that mean "nothing has been paid yet" may be
        // chosen; every later status is derived from the payments themselves.
        return in_array($status, [Invoice::STATUS_DRAFT, Invoice::STATUS_SENT], true)
            ? $status
            : Invoice::STATUS_DRAFT;
    }

    private function resolveContactId(?string $contactId): ?string
    {
        if ($contactId === null) {
            return null;
        }

        Contact::query()->findOr($contactId, callback: fn () => throw BusinessException::contactNotFound($contactId));

        return $contactId;
    }

    private function resolveProjectId(?string $projectId): ?string
    {
        if ($projectId === null) {
            return null;
        }

        Project::query()->findOr($projectId, callback: fn () => throw BusinessException::projectNotFound($projectId));

        return $projectId;
    }

    private function toDate(mixed $value): ?DateTimeInterface
    {
        return match (true) {
            $value === null, $value === '' => null,
            $value instanceof DateTimeInterface => $value,
            default => new DateTimeImmutable((string) $value),
        };
    }
}
