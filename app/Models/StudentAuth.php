<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class StudentAuth extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'tblBHSUserAuth';
    protected $primaryKey = 'UserID';
    public $timestamps = false;

    protected $hidden = ['PW1', 'PW2', 'PW3'];

    public function getAuthPassword()
    {
        return $this->PW1;
    }
}
