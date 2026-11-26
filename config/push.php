<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Push notifications
    |--------------------------------------------------------------------------
    |
    | Off by default. While this is false, GET /push/config tells the app not to
    | ask for permission, POST /push/register stores nothing, and nothing is sent.
    | Turn it on with PUSH_NOTIFICATIONS_ENABLED=true once the push_tokens table
    | exists and the Apple and Google credentials are in EAS.
    |
    */

    'enabled' => (bool) env('PUSH_NOTIFICATIONS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Expo push service
    |--------------------------------------------------------------------------
    |
    | Messages go to Expo, which passes them to Apple and Google. The access
    | token is only needed once "enhanced push security" is switched on for the
    | Expo account; after that Expo refuses requests without it.
    |
    */

    'expo' => [
        'send_url' => env('EXPO_PUSH_SEND_URL', 'https://exp.host/--/api/v2/push/send'),
        'receipts_url' => env('EXPO_PUSH_RECEIPTS_URL', 'https://exp.host/--/api/v2/push/getReceipts'),
        'access_token' => env('EXPO_ACCESS_TOKEN'),
        'timeout' => (int) env('EXPO_PUSH_TIMEOUT', 15),
    ],

    // Android notification channel the app creates at start-up. A message sent to a
    // channel the phone does not have is silently dropped, so keep the two in step.
    'android_channel' => env('PUSH_ANDROID_CHANNEL', 'default'),

];
