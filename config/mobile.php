<?php

return [
    'enabled' => (bool) env('MOBILE_PUSH_ENABLED', false),
    'source' => 'tickets',
    'owner_ids' => array_filter(array_map('trim', explode(',', env('MOBILE_OWNER_IDS', '')))),
    'expo_access_token' => env('EXPO_ACCESS_TOKEN'),
    'notify_external_tickets' => (bool) env('MOBILE_NOTIFY_EXTERNAL_TICKETS', false),
];
