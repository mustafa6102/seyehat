<?php

return [
    'enabled' => (bool) env('DIAGNOSTICS_ENABLED', false),
    'token' => env('DIAGNOSTICS_TOKEN'),
    'record_path' => storage_path('logs/planla-diagnostic.json'),
];
