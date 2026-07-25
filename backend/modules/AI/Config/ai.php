<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | The product must work with the AI layer switched off (docs/07-security.md
    | §5.6). A workspace can also opt out on its own through the
    | `ai.enabled` key in its settings, which wins over this default.
    |
    */

    'enabled' => (bool) env('AI_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Gateway credentials
    |--------------------------------------------------------------------------
    |
    | With no key the container binds the deterministic provider, so every
    | feature below still answers — from rules instead of from a model. That is
    | also what the test suite runs against: no network, no key, no surprises.
    |
    */

    'key' => env('AI_API_KEY', env('OPENAI_API_KEY')),

    'base_url' => rtrim((string) env('AI_BASE_URL', 'https://api.openai.com/v1'), '/'),

    'timeout' => (int) env('AI_TIMEOUT', 30),

    'retries' => (int) env('AI_RETRIES', 2),

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | A small model classifies, a large one converses (docs/08-ai-layer.md §7):
    | categorising a coffee does not need the model that answers "where did my
    | money go this month".
    |
    */

    'models' => [
        'chat' => env('AI_MODEL_CHAT', 'gpt-4o'),
        'classify' => env('AI_MODEL_CLASSIFY', 'gpt-4o-mini'),
        'vision' => env('AI_MODEL_VISION', 'gpt-4o-mini'),
        'transcribe' => env('AI_MODEL_TRANSCRIBE', 'whisper-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Drafts
    |--------------------------------------------------------------------------
    |
    | Below the threshold the client shows a partially filled form instead of a
    | one-tap confirmation. Nothing is ever written without confirmation either
    | way — the threshold only decides how much the user has to check.
    |
    */

    'confidence_threshold' => (float) env('AI_CONFIDENCE_THRESHOLD', 0.7),

    /*
    |--------------------------------------------------------------------------
    | Toman / rial
    |--------------------------------------------------------------------------
    |
    | Prices in Iran are quoted in toman while the books are kept in rial, and
    | getting this factor wrong is off by a whole order of magnitude. So it is
    | never inferred: it is a named, per-workspace setting with an explicit
    | default, and every parse reports which factor it applied.
    |
    */

    'toman' => [
        'rial_factor' => (int) env('AI_TOMAN_RIAL_FACTOR', 10),
        'workspace_setting_key' => 'ai.toman_rial_factor',
    ],

    /*
    |--------------------------------------------------------------------------
    | Receipts
    |--------------------------------------------------------------------------
    |
    | How far `sum(items) + tax` may drift from the stated total before the
    | draft is flagged. Zero: a receipt that does not add up is reported, not
    | rounded into agreement.
    |
    */

    'receipt' => [
        'tolerance_minor_units' => (int) env('AI_RECEIPT_TOLERANCE', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat
    |--------------------------------------------------------------------------
    */

    'chat' => [
        'max_tool_rounds' => (int) env('AI_MAX_TOOL_ROUNDS', 3),
        'max_rows' => (int) env('AI_MAX_ROWS', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Insights
    |--------------------------------------------------------------------------
    |
    | Statistics, not a model. The z-score baseline deliberately excludes the
    | candidate transaction: a single large outlier otherwise inflates its own
    | standard deviation until it stops looking like an outlier.
    |
    */

    'insights' => [
        'lookback_days' => (int) env('AI_INSIGHT_LOOKBACK_DAYS', 90),
        'anomaly_z_threshold' => (float) env('AI_ANOMALY_Z', 3.0),
        'anomaly_min_samples' => (int) env('AI_ANOMALY_MIN_SAMPLES', 5),
        'composition_min_share' => (float) env('AI_COMPOSITION_MIN_SHARE', 0.15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cash-flow forecast
    |--------------------------------------------------------------------------
    */

    'forecast' => [
        'lookback_days' => (int) env('AI_FORECAST_LOOKBACK_DAYS', 30),
        'horizon_days' => (int) env('AI_FORECAST_HORIZON_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Audio
    |--------------------------------------------------------------------------
    |
    | ffmpeg is an optimisation, not a dependency: when the binary is missing
    | the original recording is handed to the transcriber unchanged.
    |
    */

    'audio' => [
        'ffmpeg' => env('AI_FFMPEG_PATH', 'ffmpeg'),
        'sample_rate' => (int) env('AI_AUDIO_SAMPLE_RATE', 16000),
        'max_seconds' => (int) env('AI_AUDIO_MAX_SECONDS', 600),
    ],

];
