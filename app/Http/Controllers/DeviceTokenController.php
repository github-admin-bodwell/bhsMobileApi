<?php
// app/Http/Controllers/Auth/DeviceTokenController.php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\Semesters;
use App\Traits\HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DeviceTokenController extends Controller
{
    use HttpResponse;

    // POST /auth/device/enable  (requires user logged in with normal AT)
    // body: { deviceName?: string, deviceId?: string }
    public function enable(Request $request) {
        $user = $request->user(); // Sanctum AT-protected
        if (!$user) return $this->errorResponse('Unauthorized', [], null, 401);

        $plain = 'dt_' . Str::random(64);                  // plaintext
        $hashed = hash('sha256', $plain);                  // store only hash

        $dt = DeviceToken::create([
            'tokenable_id' => $user->getKey(),
            'tokenable_type' => get_class($user),         // Parents or Student
            'name' => $request->string('deviceName')->toString() ?: null,
            'device_id' => $request->string('deviceId')->toString() ?: null,
            'token' => $hashed,
            'abilities' => ['device.issue'],
            'expires_at' => null,                         // or now()->addMonths(12)
            'ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);

        return response()->json([
            'deviceToken' => $plain,     // return plaintext once
            'deviceId' => $dt->device_id,
        ]);
    }

    // POST /auth/device/issue  (public; called with device token)
    // body: { deviceToken: string, deviceId?: string, rotate?: bool }
    public function issue(Request $request) {
        $plain = (string) $request->input('deviceToken');
        if (!$plain) return $this->errorResponse('Missing Authorization', [], null, 422);

        $hashed = hash('sha256', $plain);
        $deviceId = $request->string('deviceId')->toString() ?: null;

        $record = DeviceToken::where('token', $hashed)->first();
        if (!$record || !$record->isValid()) {
            return $this->errorResponse('Device Unauthorized', [], null, 401);
        }
        // Optional binding: require same device_id if present
        if ($record->device_id) {
            if (!$deviceId || !hash_equals($record->device_id, $deviceId)) {
                return $this->errorResponse('Device Mismatch', [], null, 401);
            }
        }

        $record->last_used_at = now();
        $record->ip = $request->ip();
        $record->user_agent = (string) $request->userAgent();
        $record->save();

        // Mint a new *short-lived* access token with your normal abilities
        $user = $record->tokenable;

        // kill older ATs (optional)
        $user->tokens()->delete();

        $abilities = $this->abilitiesFor($record); // ["parent:*"] or ["student:*"]
        $access = $user->createToken('mobile', $abilities, now()->addDay());

        $currentSemester = Semesters::getCurrentSemester([
            'SemesterID','SemesterName','FExam1','FExam2','MidCutOffDate'
        ]);

        // Optional rotation of device token
        $rotate = (bool) $request->boolean('rotate', false);
        $newDeviceToken = null;
        if ($rotate) {
            $newPlain = 'dt_' . Str::random(64);
            $record->token = hash('sha256', $newPlain);
            $record->save();
            $newDeviceToken = $newPlain;
        }

        return $this->successResponse(
            'Success',
            [
                '__t' => $access->plainTextToken,
                'user' => [
                    'studentId' => isset($user->StudentID) ? $user->StudentID : $user->UserID,
                    'firstname' => $user->FirstName,
                    'lastname'  => $user->LastName,
                    'role'      => $this->roleFor($user),
                ],
                'semester' => $currentSemester,
                'rotatedDeviceToken' => $newDeviceToken,  // null if not rotated
            ]
        );
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

    private function roleFor($user): string {
        // Mirror your login() logic
        if (isset($user->VerifiedEmail)) return 'parent';
        if (isset($user->SchoolEmail)) return 'student';
        return 'staff';
    }

    private function abilitiesFor(DeviceToken $record): array {
        // Return abilities matching the user type associated with this device token
        $user = $record->tokenable;
        if (isset($user->VerifiedEmail)) return ['parent:*'];
        if (isset($user->SchoolEmail))   return ['student:*'];
        return ['staff:*'];
    }
}
