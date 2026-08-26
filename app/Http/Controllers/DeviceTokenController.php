<?php
// app/Http/Controllers/Auth/DeviceTokenController.php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\StudentAuth;
use App\Models\UserAuth;
use App\Traits\HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DeviceTokenController extends Controller
{
    use HttpResponse;

    /**
     * Device-token lifetime, rolling from last use: every successful exchange pushes
     * expires_at out again, so a phone in regular use never expires, and one that goes
     * quiet for a year stops working. Deliberately not a school-year boundary — that
     * would expire every returning student at once in September, when support load is
     * already at its highest.
     */
    private const DEVICE_TOKEN_MONTHS = 12;

    // POST /auth/device/enable  (requires user logged in with normal AT)
    // body: { deviceName?: string, deviceId?: string }
    public function enable(Request $request) {
        $user = $request->user(); // Sanctum AT-protected
        if (!$user) return $this->errorResponse('Unauthorized', [], null, 401);

        $deviceId = $request->string('deviceId')->toString() ?: null;

        // Required since v2. An unbound token can never be exchanged — issueV2 refuses
        // records with no device_id — so minting one would hand back a dead credential.
        if (!$deviceId) {
            return $this->errorResponse('Missing deviceId', [], null, 422);
        }

        $plain = 'dt_' . Str::random(64);                  // plaintext
        $hashed = hash('sha256', $plain);                  // store only hash

        // One live token per user per device: re-enabling replaces, never stacks.
        // Backs the unique index added in the same change, and means a user who
        // toggles biometrics off and on does not leave a usable orphan behind.
        DeviceToken::where('tokenable_id', $user->getKey())
            ->where('tokenable_type', get_class($user))
            ->where('device_id', $deviceId)
            ->delete();

        $dt = DeviceToken::create([
            'tokenable_id' => $user->getKey(),
            'tokenable_type' => get_class($user),         // UserAuth or StudentAuth
            'name' => $request->string('deviceName')->toString() ?: null,
            'device_id' => $deviceId,
            'token' => $hashed,
            'abilities' => ['device.issue'],
            'expires_at' => now()->addMonths(self::DEVICE_TOKEN_MONTHS),
            'ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);

        return response()->json([
            'deviceToken' => $plain,     // return plaintext once
            'deviceId' => $dt->device_id,
            'expiresAt' => $dt->expires_at?->toIso8601String(),
        ]);
    }

    // POST /auth/device/issue  — v1, PERMANENTLY DORMANT. Do not re-arm.
    //
    // This route answers 410 unconditionally, and is deliberately NOT behind
    // features.biometric_login. Turning it back on would revive the original launch
    // loop for every device still running the February 2026 bundle: that client treats
    // a 401 from here as session expiry, emits `unauthorized`, the root layout does
    // router.replace("/login"), the login screen remounts, its attempt guard resets,
    // and the auto-unlock fires again — an infinite biometric prompt. Those users are
    // precisely the ones who cannot receive a fix, because their bundle predates every
    // fix. 410 leaves them one dismissible alert instead.
    //
    // Biometrics v2 lives at POST /auth/device/v2/issue, which the February bundle does
    // not know about and can therefore never reach. That separation is the whole point:
    // the feature flag guards v2 only, so re-enabling biometrics cannot resurrect this.
    //
    // See 2026-08-18-pre-semester-remediation.md (P0-1, task A1) and
    // 2026-08-26-biometrics-v2.md (§4, task V3).
    public function issue(Request $request) {
        // Stale-bundle counter (task A3): every hit here is a device still running the
        // February bundle. Read this before deciding v1 can ever be retired.
        Log::info('[Device Token] issue called while biometric login is disabled', [
            'ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);

        return $this->errorResponse(
            'Biometric sign-in is not available in this version. Please sign in with your password.',
            [],
            null,
            410
        );
    }

    // POST /auth/device/v2/issue  (public; called with a device token)
    // body: { deviceToken: string, deviceId: string }
    //
    // Exchanges a biometric-gated device token for a short-lived access token. The
    // client follows this immediately with GET /auth/me, so the response carries only
    // the token — duplicating the user and semester payload here would let the two
    // drift, and /auth/me already returns fields (boarding, progressText) that this
    // endpoint never did.
    public function issueV2(Request $request) {
        if (!config('features.biometric_login')) {
            return $this->errorResponse(
                'Biometric sign-in is not available yet.',
                [],
                null,
                410
            );
        }

        $plain = (string) $request->input('deviceToken');
        $deviceId = $request->string('deviceId')->toString() ?: null;

        // Both are mandatory in v2. v1 treated deviceId as optional and only compared
        // it when the client happened to send one, so omitting it skipped the binding
        // check entirely — a stolen token replayed without a deviceId was accepted.
        if (!$plain || !$deviceId) {
            return $this->errorResponse('Missing Authorization', [], null, 422);
        }

        $hashed = hash('sha256', $plain);

        $record = DeviceToken::where('token', $hashed)->first();

        // One generic failure for every rejection below. Distinguishing "unknown token"
        // from "wrong device" would confirm to a caller holding a stolen token that the
        // token itself is live.
        $reject = function (string $why, array $context = []) use ($request) {
            Log::warning('[DeviceToken] v2 issue failed: ' . $why, $context + [
                'ip' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);

            return $this->errorResponse('Device Unauthorized', [], null, 401);
        };

        if (!$record || !$record->isValid()) {
            return $reject('unknown or expired token');
        }

        // Binding is mandatory: the record must carry a device_id, and it must match.
        if (!$record->device_id || !hash_equals($record->device_id, $deviceId)) {
            return $reject('device mismatch', [
                'token_id' => $record->getKey(),
                'bound' => (bool) $record->device_id,
            ]);
        }

        $user = $record->tokenable;

        // Resolve the role once, and refuse to mint anything for a user we cannot
        // positively identify. This runs before tokens()->delete() on purpose: a
        // request we are about to reject must not revoke the user's live sessions.
        // It also covers an orphaned morph, where tokenable is null.
        $role = $this->roleFor($user);
        if ($role === null) {
            return $reject('unrecognised tokenable', [
                'token_id' => $record->getKey(),
                'tokenable_type' => $record->tokenable_type,
            ]);
        }

        // Roll the 12-month window forward on every successful exchange.
        $record->last_used_at = now();
        $record->expires_at = now()->addMonths(self::DEVICE_TOKEN_MONTHS);
        $record->ip = $request->ip();
        $record->user_agent = (string) $request->userAgent();
        $record->save();

        // Single session per user. This is the app's existing policy, not a quirk of
        // this endpoint: password login (AuthController:75) and Azure login
        // (AzureAuthController:94) both revoke everything before minting. Biometric
        // unlock is a login, so it behaves the same way — diverging here would make
        // this the one login path that leaves old sessions alive.
        //
        // It is also the only thing that prunes the table: no sanctum:prune-expired is
        // scheduled (bootstrap/app.php), so expired tokens are cleaned up on login or
        // not at all. Revisit both together if multi-device sessions are ever wanted.
        $user->tokens()->delete();

        $access = $user->createToken('mobile', [$role . ':*'], now()->addDay());

        Log::info('[DeviceToken] v2 issued', [
            'token_id' => $record->getKey(),
            'role' => $role,
            'device_id' => $record->device_id,
            'ip' => $request->ip(),
        ]);

        return $this->successResponse('Success', [
            '__t' => $access->plainTextToken,
            'expiresAt' => $access->accessToken->expires_at?->toIso8601String(),
        ]);
    }

    // POST /auth/device/disable  (requires AT, removes this deviceId or all)
    // body: { deviceId?: string }  If omitted, disable all device tokens for this user
    public function disable(Request $request) {
        $user = $request->user();
        if (!$user) return $this->errorResponse('Unauthorized', [], null, 401);

        $deviceId = $request->string('deviceId')->toString() ?: null;

        $q = DeviceToken::where('tokenable_id', $user->getKey())
                        ->where('tokenable_type', get_class($user));

        if ($deviceId) $q->where('device_id', $deviceId);

        $count = $q->delete();

        return $this->successResponse(
            'Revoked Authorization',
            ['revoked' => $count > 0]
        );
    }

    /**
     * Identify the user behind a device token. Returns null when we cannot.
     *
     * This app serves **students and parents only**. Staff authenticate through a
     * separate app against tblStaff (App\Models\User); when that ships it gets its
     * own positive branch here — never the fallback.
     *
     * Matches on class, the way AuthController::me() does, because UserAuth and
     * StudentAuth share one table (tblBHSUserAuth) and differ only by primary key.
     * The previous version sniffed properties instead: it tested SchoolEmail, which
     * lives on tblBHSStudent and so is never present on the tokenable, and
     * VerifiedEmail, which exists for both classes on the shared table. Both branches
     * missed for a real student and execution fell through to a 'staff' default —
     * minting staff abilities for a student. Identify positively, and fail closed.
     */
    private function roleFor($user): ?string {
        if ($user instanceof UserAuth)    return 'parent';
        if ($user instanceof StudentAuth) return 'student';

        return null;
    }
}
