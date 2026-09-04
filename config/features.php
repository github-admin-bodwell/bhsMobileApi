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
    | Leave request maximum duration (hours, same day)
    |--------------------------------------------------------------------------
    |
    | Residence rule as of 2026-09-03: a campus leave request from the app
    | starts and ends on the same day and lasts at most this many hours.
    | Anything longer goes through a separate process outside the app.
    |
    | POST /add-leave-request answers 422 with code "leave_not_same_day" or
    | "leave_duration_exceeded" (plus maxHours). The first version of this
    | guard was a 5-day cap, added after a student submitted a one-year leave
    | by flicking the year wheel on the iOS picker; once approved it blocked
    | every later request through the overlap check. The mobile client
    | enforces the same rule, so keep the two in step.
    |
    */

    'leave_max_hours' => (int) env('LEAVE_MAX_HOURS', 12),

];
