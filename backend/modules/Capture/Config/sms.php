<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Direction vocabulary
    |--------------------------------------------------------------------------
    |
    | The word a bank uses for "money arrived" and "money left". This is the
    | single most consequential thing a bank SMS says: read it backwards and an
    | expense is booked as income, which does not merely lose the amount — it
    | moves it to the wrong side and doubles the error in every total.
    |
    | So direction is never inferred from the shape of the message. Each pattern
    | captures a `direction` group and the captured word is looked up here; a
    | word that is not listed leaves the message `unparsed` rather than guessed.
    |
    */

    'directions' => [

        'deposit' => [
            'واریز', 'دریافت', 'بازگشت وجه', 'شارژ کارت', 'افزایش',
            'credited', 'credit', 'deposit', 'deposited',
            'yatırılmıştır', 'yatırıldı',
        ],

        'withdrawal' => [
            'برداشت', 'کسر', 'خرید', 'پرداخت', 'انتقال', 'کاهش',
            'debited', 'debit', 'withdrawal', 'withdrawn', 'purchase',
            'çekilmiştir', 'çekildi',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Per-bank pattern registry
    |--------------------------------------------------------------------------
    |
    | Supporting another bank is an entry here, not a change to SmsParser.
    |
    | Patterns are matched against text that has already been through
    | Modules\AI\Support\TextNormalizerBridge::forParsing — Persian and Arabic
    | digits are Latin by then, letters are folded to their Persian forms, the
    | text is lowercase, every run of whitespace (newlines included) is one
    | space, and the Arabic thousands separator is gone. Write patterns against
    | that, not against what arrives on the wire.
    |
    | Recognised named groups:
    |
    |   direction  required — the word looked up in `directions` above
    |   amount     required — a number token that starts and ends with a digit
    |   currency   optional — a word CurrencyLexicon knows («ریال», «تومان», tl…)
    |   account    optional — the masked card or account fragment
    |   balance    optional — the balance the message reports afterwards
    |
    | Keys on each entry:
    |
    |   bank              a stable identifier, carried into the draft's meta
    |   label             human-readable, for support and logs
    |   senders           sender ids this shape is restricted to; [] means any
    |   default_currency  used when the message names no currency
    |   number_format     `plain` (1,200,000.50) or `european` (1.200.000,50)
    |
    | Order matters: the first entry whose sender gate passes and whose pattern
    | matches wins, so put specific shapes above general ones.
    |
    */

    'patterns' => [

        // «مبلغ ۱٬۲۰۰٬۰۰۰ ریال از حساب ۱۲۳۴***۵۶۷۸ برداشت شد. مانده ۵٬۰۰۰٬۰۰۰ ریال»
        'ir_account_movement' => [
            'bank' => 'ir_generic',
            'label' => 'Iranian bank — amount first, account movement',
            'senders' => [],
            'default_currency' => 'IRR',
            'number_format' => 'plain',
            'pattern' => '/مبلغ\s*(?P<amount>\d(?:[\d.,]*\d)?)\s*(?P<currency>ریال|ریالی|تومان|تومن)?\s*'
                .'(?:از|به)\s*حساب\s*(?P<account>[\d*x×\-]{4,})\s*'
                .'(?P<direction>برداشت|واریز|کسر|دریافت)(?:.*?مانده\s*:?\s*(?P<balance>\d(?:[\d.,]*\d)?))?/u',
        ],

        // «خرید اینترنتی مبلغ ۲۵۰٬۰۰۰ ریال کارت ۶۰۳۷***۱۲۳۴ مانده ۳٬۰۰۰٬۰۰۰»
        'ir_card_purchase' => [
            'bank' => 'ir_generic',
            'label' => 'Iranian bank — card purchase or refund',
            'senders' => [],
            'default_currency' => 'IRR',
            'number_format' => 'plain',
            'pattern' => '/(?P<direction>خرید|بازگشت\s+وجه|شارژ\s+کارت)\s*(?:اینترنتی|حضوری|شاپرکی)?\s*'
                .'مبلغ\s*(?P<amount>\d(?:[\d.,]*\d)?)\s*(?P<currency>ریال|ریالی|تومان|تومن)?\s*'
                .'کارت\s*(?P<account>[\d*x×\-]{4,})(?:.*?مانده\s*:?\s*(?P<balance>\d(?:[\d.,]*\d)?))?/u',
        ],

        // «حساب ۱۲۳۴***۵۶۷۸ واریز ۵۰۰٬۰۰۰ ریال مانده ۲٬۵۰۰٬۰۰۰ ریال»
        'ir_account_first' => [
            'bank' => 'ir_generic',
            'label' => 'Iranian bank — account first, direction then amount',
            'senders' => [],
            'default_currency' => 'IRR',
            'number_format' => 'plain',
            'pattern' => '/حساب\s*(?P<account>[\d*x×\-]{4,})\s*(?P<direction>واریز|برداشت|کسر|دریافت)\s*'
                .'(?P<amount>\d(?:[\d.,]*\d)?)\s*(?P<currency>ریال|ریالی|تومان|تومن)?'
                .'(?:.*?مانده\s*:?\s*(?P<balance>\d(?:[\d.,]*\d)?))?/u',
        ],

        // "Your account ****1234 has been debited USD 45.20. Available balance USD 300.00"
        'en_account_debit_credit' => [
            'bank' => 'intl_generic',
            'label' => 'English — account debited / credited',
            'senders' => [],
            'default_currency' => 'USD',
            'number_format' => 'plain',
            'pattern' => '/account\s*(?P<account>[\d*x\-]{4,})\s*(?:has\s+been|was|is)\s*'
                .'(?P<direction>debited|credited)\s*(?:with\s*)?(?P<currency>usd|eur|try|aed|irr|tl)?\s*'
                .'(?P<amount>\d(?:[\d.,]*\d)?)(?:.*?balance\s*(?:usd|eur|try|aed|irr|tl)?\s*'
                .'(?P<balance>\d(?:[\d.,]*\d)?))?/u',
        ],

        // "1234 nolu hesabınızdan 250,00 TL çekilmiştir. Bakiye: 1.000,00 TL"
        'tr_account_movement' => [
            'bank' => 'tr_generic',
            'label' => 'Turkish — account debited / credited',
            'senders' => [],
            'default_currency' => 'TRY',
            'number_format' => 'european',
            'pattern' => '/(?P<account>[\d*x\-]{4,})\s*nolu\s*hesab\S*\s*(?P<amount>\d(?:[\d.,]*\d)?)\s*'
                .'(?P<currency>tl|try|usd|eur)\s*'
                .'(?P<direction>çekilmiştir|çekildi|yatırılmıştır|yatırıldı)'
                .'(?:.*?bakiye\s*:?\s*(?P<balance>\d(?:[\d.,]*\d)?))?/u',
        ],

    ],

];
