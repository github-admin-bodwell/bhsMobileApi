<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CommunityReaction extends Model
{
    use HasUlids;

    protected $fillable = ['post_id','user_type','user_id','type'];

    public function post(): BelongsTo { return $this->belongsTo(CommunityPost::class, 'post_id'); }
    public function user(): MorphTo { return $this->morphTo(); }
}
