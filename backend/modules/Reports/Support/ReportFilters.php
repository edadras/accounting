<?php

declare(strict_types=1);

namespace Modules\Reports\Support;

use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Modules\Ledger\Models\Transaction;

/**
 * The filters every report endpoint accepts, in one place.
 *
 * An export is the same question as the GET endpoint with a file for an answer,
 * so it must be the same filters — parsed by the same code. A second parser
 * would eventually disagree with the first, and the file would then show
 * different numbers from the screen it was exported from.
 *
 * The array form is what a queued job carries: it is plain scalars, so it
 * survives serialisation and can be stored on the export row as a record of
 * exactly what was asked for.
 */
final readonly class ReportFilters
{
    private function __construct(
        public DateRange $range,
        public Bucket $bucket,
        public int $limit,
        public int $depth,
        public string $flow,
        public string $from,
        public string $to,
    ) {}

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'bucket' => ['nullable', Rule::in(Bucket::values())],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'depth' => ['nullable', 'integer', 'min:1', 'max:10'],
            'flow' => ['nullable', Rule::in([Transaction::TYPE_INCOME, Transaction::TYPE_EXPENSE])],
        ];
    }

    /** @param  array<string, mixed>  $input */
    public static function fromArray(array $input): self
    {
        $today = CarbonImmutable::now();

        // The month-to-date default is resolved here, once, rather than left as
        // null. A queued export runs minutes or hours after it was asked for,
        // and "this month" must still mean the month the user was looking at.
        $from = isset($input['from']) ? (string) $input['from'] : $today->startOfMonth()->format('Y-m-d');
        $to = isset($input['to']) ? (string) $input['to'] : $today->format('Y-m-d');

        return new self(
            range: DateRange::between($from, $to),
            bucket: Bucket::parse(isset($input['bucket']) ? (string) $input['bucket'] : null),
            limit: (int) ($input['limit'] ?? 10),
            depth: (int) ($input['depth'] ?? 1),
            flow: (string) ($input['flow'] ?? Transaction::TYPE_EXPENSE),
            from: $from,
            to: $to,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'bucket' => $this->bucket->value,
            'limit' => $this->limit,
            'depth' => $this->depth,
            'flow' => $this->flow,
        ];
    }
}
