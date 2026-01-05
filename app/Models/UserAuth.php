<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class UserAuth extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'tblBHSUserAuth';
    protected $primaryKey = 'LoginIDParent';
    public $timestamps = false;

    protected $hidden = ['HashPassword', 'PW1', 'PW2', 'PW3'];

    public function getAuthPassword()
    {
        return $this->HashPassword;
    }
}
