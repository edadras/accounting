<?php

declare(strict_types=1);

return [

    'exports' => [

        /*
        |----------------------------------------------------------------------
        | Storage disk
        |----------------------------------------------------------------------
        |
        | Object storage in production; the local disk in tests and development
        | so Storage::fake() can stand in for it without touching configuration.
        |
        */

        'disk' => env('REPORT_EXPORTS_DISK', 'local'),

        'queue' => env('REPORT_EXPORTS_QUEUE', 'exports'),

        /*
        |----------------------------------------------------------------------
        | When an export stops being a request and becomes a job
        |----------------------------------------------------------------------
        |
        | The row count is known before any query runs — it is the number of
        | buckets in the range, or the requested limit — so the decision costs
        | nothing and is made before the expensive part rather than after it.
        |
        | The limits differ per format because the cost does, by two orders of
        | magnitude. Measured on this codebase, one row of three columns:
        |
        |     csv    5,000 rows →   ~20 ms,  18 MB
        |     xlsx   2,000 rows →  ~990 ms,  23 MB   (a cell object per cell)
        |     pdf      300 rows → ~1000 ms,  42 MB   (full table layout)
        |
        | A CSV is a stream of text and is essentially free. A PDF is a laid-out
        | document — at 1,000 rows it takes seven seconds and 240 MB, which is a
        | request that times out. It is also not a document anyone reads: past a
        | few hundred rows a PDF is an archive, and an archive can be collected
        | a moment later from its id.
        |
        */

        'inline_row_limit' => [
            'csv' => (int) env('REPORT_EXPORTS_INLINE_ROWS_CSV', 5000),
            'xlsx' => (int) env('REPORT_EXPORTS_INLINE_ROWS_XLSX', 2000),
            'pdf' => (int) env('REPORT_EXPORTS_INLINE_ROWS_PDF', 300),
        ],

        /*
        |----------------------------------------------------------------------
        | Font cache for the PDF writer
        |----------------------------------------------------------------------
        |
        | dompdf converts a TTF into its own metrics format once and caches the
        | result. The directory must be writable; the fonts themselves ship in
        | the module (Resources/fonts) and are never read from anywhere else.
        |
        */

        'font_cache' => storage_path('framework/cache/report-fonts'),
    ],

];
