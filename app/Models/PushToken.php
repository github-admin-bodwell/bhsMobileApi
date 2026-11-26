<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PushToken extends Model
{
    protected $table = 'push_tokens';
    protected $guarded = [];
    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    /** Expo's token format. Anything else is refused before it reaches the table. */
    public const PATTERN = '/^Expo(nent)?PushToken\[[A-Za-z0-9_\-:]+\]$/';

    public function tokenable(): MorphTo {
        return $this->morphTo();
    }

    /**
     * Tokens belonging to a user. Matches on class as well as id, because UserAuth
     * and StudentAuth share tblBHSUserAuth and the same id can belong to both.
     */
    public static function forUser($user) {
        return static::where('tokenable_type', get_class($user))
            ->where('tokenable_id', $user->getKey());
    }

    /**
     * Make this phone the one that receives the user's notifications.
     *
     * Login keeps one session per user, so a user has one signed-in phone at a
     * time: their other tokens are dropped here, or a phone they were signed out
     * of would keep showing their notifications. The token itself is unique, so a
     * phone handed to another account moves with whoever signed in last.
     */
    public static function register($user, string $token, string $platform, ?string $deviceId = null, ?string $appVersion = null): self {
        static::forUser($user)->where('token', '!=', $token)->delete();

        return static::updateOrCreate(
            ['token' => $token],
            [
                'tokenable_id' => $user->getKey(),
                'tokenable_type' => get_class($user),
                'platform' => $platform,
                'device_id' => $deviceId,
                'app_version' => $appVersion,
                'last_seen_at' => now(),
            ]
        );
    }
}
