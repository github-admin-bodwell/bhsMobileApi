<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Biometric (device token) login
    |--------------------------------------------------------------------------
    |
    | Dormant since app 4.2.0. The mobile client removed biometric login in
    | February 2026, but the server side (DeviceToken model, migration, and the
    | authenticated enable/disable routes) is kept for the planned v2.
    |
    | While this is false, POST /auth/device/issue answers 410 Gone instead of
    | minting an access token. Flip it back on with BIOMETRIC_LOGIN_ENABLED=true
    | when the feature ships again.
    |
    */

    'biometric_login' => (bool) env('BIOMETRIC_LOGIN_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Leave request maximum duration (days)
    |--------------------------------------------------------------------------
    |
    | POST /add-leave-request rejects an end date more than this many days
    | after the start date with 422 and code "leave_duration_exceeded". Added
    | after a student submitted a one-year campus leave by flicking the year
    | wheel on the iOS picker; once approved, it blocked every later request
    | through the overlap check. The mobile client enforces the same limit on
    | its date pickers, so keep the two in step.
    |
    */

    'leave_max_days' => (int) env('LEAVE_MAX_DAYS', 5),

];
