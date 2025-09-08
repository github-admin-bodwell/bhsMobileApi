<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Semesters extends Model
{
    protected $table = 'tblBHSSemester';
    public $timestamps = false;

    public static function getCurrentSemester() {
        return self::where('CurrentSemester', 'Y')->first();
    }
}
