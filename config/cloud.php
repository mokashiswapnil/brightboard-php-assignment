<?php

// config/cloud.php

return [
    'base_url' => env('CLOUD_BASE_URL', 'https://api.cloud-storage.example'),
    // Fixed: Never hardcode production API secrets in source code files
    'api_key'  => env('CLOUD_API_KEY'),
];
