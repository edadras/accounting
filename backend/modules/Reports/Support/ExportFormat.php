<?php

declare(strict_types=1);

namespace Modules\Reports\Support;

/**
 * The file formats an export may be asked for.
 *
 * A closed enum is the whitelist: the request supplies a string, `Rule::in`
 * rejects anything not listed, and only then does `from()` turn it into a case.
 * No part of a request ever reaches a class name.
 */
enum ExportFormat: string
{
    case Csv = 'csv';

    case Xlsx = 'xlsx';

    case Pdf = 'pdf';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function extension(): string
    {
        return $this->value;
    }

    public function contentType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv; charset=UTF-8',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Pdf => 'application/pdf',
        };
    }
}
