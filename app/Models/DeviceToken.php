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

    public function isValid(): bool {
        if ($this->expires_at && now()->greaterThan($this->expires_at)) return false;
        return in_array('device.issue', $this->abilities ?? [], true);
    }
}
