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

];
