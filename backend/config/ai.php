<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Features (SRS §22)
    |--------------------------------------------------------------------------
    |
    | The forecasting / prediction / suggestion engines are fully statistical
    | and run locally — no third-party account is required, and every AI screen
    | works out of the box. The only piece that can call out to a hosted model
    | is the *narrative* layer (AI Reports narration + the chat/voice assistant's
    | conversational polish); that uses a provider seam mirroring the SMS
    | (log -> msg91) and GST (offline -> gsp) modules.
    |
    | Default provider `local` produces deterministic, template-driven text from
    | the same computed metrics, so the assistant and reports read sensibly with
    | zero configuration. Switch `ai_provider` (Settings) to `openai` or
    | `anthropic` and supply an API key to turn on hosted-model narration.
    |
    */

    'provider' => env('AI_PROVIDER', 'local'), // local | openai | anthropic

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
        'model' => env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),
        'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
    ],

    // How many trailing months of history the statistical engines consume.
    'history_months' => (int) env('AI_HISTORY_MONTHS', 12),

    // Default number of future periods the forecast projects forward.
    'forecast_periods' => (int) env('AI_FORECAST_PERIODS', 6),

    // Trailing window (days) used to derive consumption velocity for the
    // demand / low-stock / purchase engines.
    'velocity_window_days' => (int) env('AI_VELOCITY_WINDOW_DAYS', 90),

    // Default supplier lead time (days) assumed when computing the reorder
    // point and safety stock for the auto purchase suggestions.
    'lead_time_days' => (int) env('AI_LEAD_TIME_DAYS', 7),

    // Extra days of demand kept as a buffer (safety stock) on top of lead time.
    'safety_days' => (int) env('AI_SAFETY_DAYS', 7),
];
