<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CommunityPostResource extends JsonResource
{
    public function toArray($request) {
        return [
            'id'            => (string) $this->id,
            'source'        => $this->source,
            'permalink'     => $this->permalink,
            'caption'       => $this->caption,
            'posted_at'     => optional($this->posted_at)->toIso8601String(),
            'visibility'    => $this->visibility,
            'is_pinned'     => (bool) $this->is_pinned,
            'like_count'    => (int) $this->like_count,
            'comment_count' => (int) $this->comment_count,
            'media'         => $this->whenLoaded('media', fn() => $this->media->map(fn($m) => [
                'id'            => (string) $m->id,
                'type'          => $m->type,
                'url'           => $m->url,
                'thumbnail_url' => $m->thumbnail_url,
                'width'         => $m->width,
                'height'        => $m->height,
                'sort_order'    => $m->sort_order,
            ])),
        ];
    }
}
