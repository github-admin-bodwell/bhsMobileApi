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
    | Leave request maximum duration (hours, same day, by hall)
    |--------------------------------------------------------------------------
    |
    | Residence rule as of 2026-09-09: a campus leave request from the app
    | starts and ends on the same day and lasts at most 6 hours for a Junior
    | student or 8 hours for a Senior one. Longer same-day leave goes through
    | Boarding Admin; overnight leave goes through the AWP system. The first
    | version of this rule (2026-09-03) was a flat 12 hours.
    |
    | Junior is decided by hall: tblBHSHomestay.Halls names three halls with a
    | "(Jr.)" suffix (Courage West, Harmony East, Unity East). A student with
    | no hall on record is treated as Junior, the stricter limit, and logged.
    |
    | POST /add-leave-request answers 422 with code "leave_not_same_day" or
    | "leave_duration_exceeded" plus maxHours, tier, juniorHours and
    | seniorHours. GET /get-leave-rule returns the same for the signed-in
    | student so the app can check locally with the right number.
    |
    */

    'leave_max_hours_junior' => (int) env('LEAVE_MAX_HOURS_JUNIOR', 6),
    'leave_max_hours_senior' => (int) env('LEAVE_MAX_HOURS_SENIOR', 8),
    'leave_junior_hall_marker' => (string) env('LEAVE_JUNIOR_HALL_MARKER', '(Jr.)'),

    /*
    |--------------------------------------------------------------------------
    | Campus time zone
    |--------------------------------------------------------------------------
    |
    | The application clock is UTC (config/app.php). Anything that asks "is
    | this ban / rule in force right now" must use campus wall-clock time
    | instead: tblBHSStudentLeaveBan.FromDate/ToDate are entered in Vancouver
    | time by residence staff. Added 2026-09-28 after a Friday leave ban was
    | shown to students on Thursday from 5pm.
    |
    */

    'campus_timezone' => (string) env('CAMPUS_TIMEZONE', 'America/Vancouver'),

];
