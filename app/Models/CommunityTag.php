<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CommunityTag extends Model
{
    use HasUlids;

    protected $fillable = ['name'];

    public function posts(): BelongsToMany {
        return $this->belongsToMany(CommunityPost::class, 'community_post_tag', 'tag_id', 'post_id');
    }
}
