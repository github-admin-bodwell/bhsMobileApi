<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarSource extends Model {

    protected $fillable = ['name','url','etag','last_modified','tz','default_span_days'];

    protected $casts = ['last_modified'=>'datetime'];

    public function events() { return $this->hasMany(CalendarEvent::class, 'source_id'); }

}
