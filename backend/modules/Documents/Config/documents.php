<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Storage disk
    |--------------------------------------------------------------------------
    |
    | Object storage in production; the local disk in tests and development so
    | Storage::fake() can stand in for it without touching configuration.
    |
    */

    'disk' => env('DOCUMENTS_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Upload limits
    |--------------------------------------------------------------------------
    */

    'max_upload_kilobytes' => (int) env('DOCUMENTS_MAX_UPLOAD_KB', 25600),

    /*
    |--------------------------------------------------------------------------
    | Accepted media types
    |--------------------------------------------------------------------------
    |
    | Matched against the MIME type derived from the file's own bytes, never
    | against the Content-Type the client claims.
    |
    */

    'allowed_mimetypes' => [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
        'text/csv',

        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/heic',
        'image/heif',

        'audio/mpeg',
        'audio/mp4',
        'audio/aac',
        'audio/ogg',
        'audio/wav',
        'audio/x-wav',
        'audio/webm',

        'video/mp4',
        'video/quicktime',
        'video/webm',

        'application/zip',
        'application/x-zip-compressed',
        'application/x-rar-compressed',
        'application/x-7z-compressed',
    ],

];
