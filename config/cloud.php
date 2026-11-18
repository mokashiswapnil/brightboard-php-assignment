<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cloud Storage Service Configuration
    |--------------------------------------------------------------------------
    |
    | Credentials must strictly be supplied via environment variables.
    | Never hardcode production API secrets in source repository files.
    |
    */
    'base_url' => env('CLOUD_BASE_URL', 'https://api.cloud-storage.example'),
    'api_key'  => env('CLOUD_API_KEY'),
    'timeout'  => (int) env('CLOUD_TIMEOUT_SECONDS', 15),
];
