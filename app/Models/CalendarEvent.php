<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarEvent extends Model {

    protected $fillable = [
        'source_id','uid','summary','description','location','all_day',
        'start_at','end_at','status','hash','last_seen_at'
    ];

    protected $casts = [
        'all_day'=>'bool',
        'start_at'=>'datetime',
        'end_at'=>'datetime',
        'last_seen_at'=>'datetime',
    ];
}
