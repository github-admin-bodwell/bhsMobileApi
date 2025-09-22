<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunityMedia extends Model
{
    use HasUlids;

    protected $fillable = ['post_id','type','url','thumbnail_url','width','height','sort_order','meta'];
    protected $casts = ['meta' => 'array'];

    public function post(): BelongsTo { return $this->belongsTo(CommunityPost::class, 'post_id'); }
}
