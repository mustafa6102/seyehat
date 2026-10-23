<?php

/*
|--------------------------------------------------------------------------
| Flight search web client (ADR-011 async job + polling)
|--------------------------------------------------------------------------
|
| The polling interval is deployment configuration per contracts/v3.3/CHANGELOG.md and is not
| defined in the SSOT. Without a configured value the UI does not poll automatically; the user
| refreshes the job status manually.
|
*/

$pollInterval = env('FLIGHT_SEARCH_POLL_INTERVAL_MS');

return [
    'poll_interval_ms' => is_numeric($pollInterval) && (int) $pollInterval > 0 ? (int) $pollInterval : null,
];
