<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{MorphTo, HasMany, BelongsToMany};
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Support\Facades\DB;

class CommunityPost extends Model
{
    use HasUlids;

    protected $fillable = [
        'source','source_post_id','caption','permalink','posted_at',
        'visibility','is_pinned','like_count','comment_count','extra'
    ];

    protected $casts = [
        'posted_at'   => 'datetime',
        'is_pinned'   => 'boolean',
        'extra'       => 'array',
        'like_count'  => 'integer',
        'comment_count' => 'integer',
    ];

    public function setCaptionAttribute($value)
    {
        // Let normal flow handle NULL / empty
        if ($value === null) {
            $this->attributes['caption'] = null;
            return;
        }

        // Wrap in raw expression: N'...' forces NVARCHAR
        $this->attributes['caption'] = DB::raw("N'" . str_replace("'", "''", $value) . "'");
    }

    public function author(): MorphTo { return $this->morphTo(); }
    public function media(): HasMany { return $this->hasMany(CommunityMedia::class, 'post_id'); }
    public function comments(): HasMany { return $this->hasMany(CommunityComment::class, 'post_id'); }
    public function tags(): BelongsToMany { return $this->belongsToMany(CommunityTag::class, 'community_post_tag', 'post_id', 'tag_id'); }
}

