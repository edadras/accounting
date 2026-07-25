<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Workspace data exports
    |--------------------------------------------------------------------------
    |
    | Where the ZIP a user asks for is written, and how long it stays there.
    | The local disk in tests and development so Storage::fake() can stand in
    | for it without touching configuration.
    |
    */

    'exports' => [
        'disk' => env('DATAOPS_EXPORT_DISK', 'local'),
        'directory' => env('DATAOPS_EXPORT_DIRECTORY', 'exports'),

        // A finished export is a complete copy of the workspace sitting on a
        // disk, so it is short-lived by design.
        'expires_after_days' => (int) env('DATAOPS_EXPORT_TTL_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Account deletion
    |--------------------------------------------------------------------------
    |
    | docs/07-security.md §8 promises real erasure "after the stated retention
    | period". This is that period: the window in which the user can still
    | change their mind, and after which `accounts:purge` makes it permanent.
    |
    */

    'deletion' => [
        'grace_days' => (int) env('DATAOPS_DELETION_GRACE_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup verification
    |--------------------------------------------------------------------------
    |
    | What `backup:verify` demands of the newest archive. The default age
    | allows a daily backup plus a couple of hours of slack, so a job that ran
    | late does not raise a false alarm while a job that never ran does.
    |
    */

    'backup' => [
        'max_age_hours' => (int) env('DATAOPS_BACKUP_MAX_AGE_HOURS', 26),
        'min_bytes' => (int) env('DATAOPS_BACKUP_MIN_BYTES', 1),
    ],

];
