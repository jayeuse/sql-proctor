<?php

return [
    'sandbox' => [
        'prefix' => 'sbx_',
        'statement_timeout_seconds' => (float) env('SQL_SANDBOX_TIMEOUT_SECONDS', 2),
        'connect_timeout_seconds' => (int) env('SQL_SANDBOX_CONNECT_TIMEOUT_SECONDS', 5),
        'max_payload_bytes' => (int) env('SQL_SANDBOX_MAX_PAYLOAD_BYTES', 16384),
        'max_statements' => (int) env('SQL_SANDBOX_MAX_STATEMENTS', 20),
        'max_rows' => (int) env('SQL_SANDBOX_MAX_ROWS', 500),
        'max_cell_bytes' => (int) env('SQL_SANDBOX_MAX_CELL_BYTES', 4096),
        'max_result_bytes' => (int) env('SQL_SANDBOX_MAX_RESULT_BYTES', 1048576),
        'student_run_rate_limit_per_minute' => (int) env('SQL_SANDBOX_RUN_RATE_LIMIT', 20),
        'orphan_max_age_minutes' => (int) env('SQL_SANDBOX_ORPHAN_AGE_MINUTES', 5),
        'runner_host' => env('SQL_SANDBOX_RUNNER_HOST', '%'),
    ],

    'grading' => [
        'default_float_tolerance' => 0.001,
    ],
];
