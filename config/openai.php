<?php

return [
    'api_key'     => env('OPENAI_API_KEY', ''),
    'model'       => env('OPENAI_MODEL', 'gpt-4o-mini'),
    'max_tokens'  => env('OPENAI_MAX_TOKENS', 2000),
    'temperature' => env('OPENAI_TEMPERATURE', 0.1),
    'timeout'     => env('OPENAI_TIMEOUT', 30),
    'base_url'    => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
];
