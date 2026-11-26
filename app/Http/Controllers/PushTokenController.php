<?php

namespace App\Http\Controllers;

use App\Models\PushToken;
use App\Traits\HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PushTokenController extends Controller
{
    use HttpResponse;

    // GET /push/config
    // The app reads this before asking the phone for permission, so the prompt
    // never appears while the feature is switched off.
    public function config() {
        return $this->successResponse('Success', [
            'enabled' => (bool) config('push.enabled'),
            'androidChannel' => (string) config('push.android_channel'),
        ]);
    }

    // POST /push/register
    // body: { token: string, platform: "ios"|"android", deviceId?: string, appVersion?: string }
    // Called after login and whenever the phone reports a new token.
    public function register(Request $request) {
        $user = $request->user();

        if (!config('push.enabled')) {
            return $this->successResponse('Push notifications are off', ['enabled' => false, 'registered' => false]);
        }

        $validate = Validator::make($request->all(), [
            'token' => ['required', 'string', 'max:255', 'regex:' . PushToken::PATTERN],
            'platform' => 'required|in:ios,android',
            'deviceId' => 'nullable|string|max:255',
            'appVersion' => 'nullable|string|max:20',
        ]);

        if ($validate->fails()) {
            return $this->errorResponse('Validation Error', $validate->errors());
        }

        $record = PushToken::register(
            $user,
            $request->string('token')->toString(),
            $request->string('platform')->toString(),
            $request->string('deviceId')->toString() ?: null,
            $request->string('appVersion')->toString() ?: null,
        );

        Log::info('[Push] token registered', [
            'role' => $user instanceof \App\Models\UserAuth ? 'parent' : 'student',
            'userId' => $user->getKey(),
            'platform' => $record->platform,
            'pushTokenId' => $record->id,
        ]);

        return $this->successResponse('Success', ['enabled' => true, 'registered' => true]);
    }

    // POST /push/unregister
    // body: { token?: string }
    // Called before logout. Works with the feature off too, so switching the flag
    // off never strands a token. With no token in the body, all of the caller's
    // tokens go.
    public function unregister(Request $request) {
        $user = $request->user();
        $token = $request->string('token')->toString();

        $q = PushToken::forUser($user);
        if ($token !== '') $q->where('token', $token);
        $removed = $q->delete();

        return $this->successResponse('Success', ['removed' => $removed]);
    }
}
