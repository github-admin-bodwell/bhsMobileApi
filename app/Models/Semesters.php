<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Semesters extends Model
{
    protected $table = 'tblBHSSemester';
    public $timestamps = false;

    public static function getCurrentSemester($select = null) {
        return self::select($select ?? '*')->where('CurrentSemester', 'Y')->first();
    }
}
