<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, MorphTo, HasMany};

class CommunityComment extends Model
{
    use HasUlids;

    protected $fillable = ['post_id','author_type','author_id','body','parent_id'];

    public function post(): BelongsTo { return $this->belongsTo(CommunityPost::class, 'post_id'); }
    public function author(): MorphTo { return $this->morphTo(); }
    public function replies(): HasMany { return $this->hasMany(self::class, 'parent_id'); }
}
