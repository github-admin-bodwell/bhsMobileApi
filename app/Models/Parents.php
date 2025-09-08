<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Parents extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'tblBHSUserAuth';
    protected $primaryKey = 'LoginIDParent';
    public $timestamps = false;

    protected $hidden = ['PW1'];

    public function getAuthPassword()
    {
        return $this->PW1;
    }
}
