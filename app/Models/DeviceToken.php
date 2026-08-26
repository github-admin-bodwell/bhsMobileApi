<?php

// app/Models/DeviceToken.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class DeviceToken extends Model
{
    protected $table = 'device_tokens';
    protected $guarded = [];
    protected $casts = [
        'abilities' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function tokenable(): MorphTo {
        return $this->morphTo();
    }

    /**
     * Revoke every device token belonging to a user.
     *
     * A device token is an independent credential: it is exchanged for an access
     * token without the password ever being presented. So it must not outlive the
     * password it was enrolled under — otherwise a reset done in response to a
     * compromise leaves the attacker's enrolled device working.
     *
     * Matches on tokenable_type as well as id, because UserAuth and StudentAuth
     * share tblBHSUserAuth and the same id can belong to both.
     */
    public static function revokeAllFor($user): int {
        if (!$user) return 0;

        return static::where('tokenable_type', get_class($user))
            ->where('tokenable_id', $user->getKey())
            ->delete();
    }

    public function isValid(): bool {
        if ($this->expires_at && now()->greaterThan($this->expires_at)) return false;
        return in_array('device.issue', $this->abilities ?? [], true);
    }
}
