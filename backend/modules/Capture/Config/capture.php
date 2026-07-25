<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Duplicate suppression
    |--------------------------------------------------------------------------
    |
    | Gateways retry. A bank SMS forwarded twice, or a mail provider replaying a
    | webhook it never got a 200 for, must not become two drafts of the same
    | payment — a duplicate the user confirms is a real transaction that never
    | happened.
    |
    | Only the machine channels are deduped. `text` is a person typing, and two
    | identical sentences are usually two identical coffees.
    |
    */

    'dedupe' => [
        'channels' => ['sms', 'qr', 'email'],
        'window_hours' => (int) env('CAPTURE_DEDUPE_WINDOW_HOURS', 72),
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound email
    |--------------------------------------------------------------------------
    |
    | The webhook is the one capture endpoint with no user session behind it, so
    | the shared secret is the whole of its authentication. With no secret
    | configured the endpoint refuses every delivery: an open inbox route is a
    | way for anyone to write into a stranger's books, and failing closed is the
    | only safe default.
    |
    | Callers may present either `X-Capture-Signature: sha256=<hmac of the raw
    | body>` or, for providers that cannot sign, `X-Capture-Secret`.
    |
    */

    'webhook' => [
        'secret' => env('CAPTURE_WEBHOOK_SECRET'),
        'signature_header' => 'X-Capture-Signature',
        'secret_header' => 'X-Capture-Secret',
    ],

    'email' => [

        // Addresses are `<token>@<domain>`; `inbox+<token>@<domain>` also works
        // for providers that only forward to one mailbox.
        'domain' => env('CAPTURE_EMAIL_DOMAIN', 'inbox.finora.app'),

        // Bytes of randomness behind an alias token. This is a bearer
        // credential in an address bar — it needs to be unguessable, not short.
        'token_bytes' => 16,

        'max_attachments' => (int) env('CAPTURE_EMAIL_MAX_ATTACHMENTS', 10),

        'max_attachment_bytes' => (int) env('CAPTURE_EMAIL_MAX_ATTACHMENT_BYTES', 10 * 1024 * 1024),

        // What gets handed to the OCR pipeline instead of merely being filed.
        'receipt_mimetypes' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/heic',
            'image/heif',
            'text/plain',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | QR payloads
    |--------------------------------------------------------------------------
    |
    | Decoding happens on the device; what arrives here is the string the code
    | contained. Two shapes are understood and anything else is refused, because
    | a payment QR that is read as something it is not produces a confident
    | draft with the wrong number in it.
    |
    */

    'qr' => [

        // ISO 4217 numeric codes, as EMV tag 53 carries them.
        'currency_numeric' => [
            '364' => 'IRR',
            '949' => 'TRY',
            '840' => 'USD',
            '978' => 'EUR',
            '784' => 'AED',
        ],

        // EMV payloads carry a CRC16 of everything before it. When one is
        // present it is checked: a payload that fails its own checksum was
        // misread, and a misread amount is the failure this module exists to
        // avoid.
        'verify_crc' => true,

    ],

];
