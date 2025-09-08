<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Students extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'tblBHSStudent';
    protected $primaryKey = 'StudentID';
    public $timestamps = false;

    protected $hidden = ['HashPassword'];

    public function getAuthPassword()
    {
        return $this->HashPassword;
    }
}
