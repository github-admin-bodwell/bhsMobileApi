<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\CommunityPostResource;
use App\Models\CommunityPost;
use App\Services\InstagramClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Traits\HttpResponse;

class FeedController extends Controller
{
    use HttpResponse;

    public function index(Request $request) {
        $visibility = $request->boolean('school_only') ? ['school'] : ['public','school'];

        $query = CommunityPost::with(['media' => fn($q)=>$q->orderBy('sort_order')])
            ->whereIn('visibility', $visibility)
            ->orderByDesc('is_pinned')
            ->orderByDesc('posted_at')
            ->orderByDesc('id');

        if ($tag = $request->string('tag')->toString()) {
            $query->whereHas('tags', fn($q)=>$q->where('name', $tag));
        }

        $posts = $query->paginate(20);

        return response()->json([
            'data' => CommunityPostResource::collection($posts),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'has_more'     => $posts->hasMorePages(),
            ],
        ]);
    }

    public function listPosts(Request $r, InstagramClient $ig)
    {
        $limit  = min((int)$r->query('limit', 12), 50);
        $cursor = $r->query('cursor'); // base64 of {"posted_at":"...","id":123}
        $preview = (bool)$r->boolean('preview'); // if true, skip hydration (use stored urls)

        // Base query (stable ordering matches cursor fields)
        $q = CommunityPost::with(['media' => fn ($m) => $m->orderBy('sort_order')])
            ->where('visibility', 'public')
            ->orderByDesc('posted_at')
            ->orderByDesc('id');

        // Apply cursor if present
        if ($cursor) {
            $c = json_decode(base64_decode($cursor), true);
            $lastAt = $c['posted_at'] ?? null;
            $lastId = $c['id'] ?? null;

            if ($lastAt && $lastId) {
                $q->where(function ($w) use ($lastAt, $lastId) {
                    $w->where('posted_at', '<', $lastAt)
                      ->orWhere(function ($w2) use ($lastAt, $lastId) {
                          $w2->where('posted_at', $lastAt)
                             ->where('id', '<', $lastId);
                      });
                });
            }
        }

        // Fetch one extra to detect "has more"
        $rows = $q->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $items = ($hasMore ? $rows->slice(0, $limit) : $rows)->values();

        // Build next cursor
        $nextCursor = null;
        if ($hasMore && $items->isNotEmpty()) {
            $last = $items->last();
            $nextCursor = base64_encode(json_encode([
                'posted_at' => (string)$last->posted_at,
                'id'        => (int)$last->id,
            ]));
        }

        // Convert to array for safe manipulation
        $payload = $items->map(function ($p) {
            return [
                'id'             => $p->id,
                'source'         => $p->source,
                'source_post_id' => $p->source_post_id, // IG parent id
                'caption'        => $p->caption,
                'permalink'      => $p->permalink,
                'posted_at'      => (string)$p->posted_at,
                'visibility'     => $p->visibility,
                'extra'          => $p->extra,
                'media'          => $p->media->map(fn ($m) => [
                    'id'            => $m->id,
                    'post_id'       => $m->post_id,
                    'type'          => $m->type,               // 'image' | 'video'
                    'url'           => $m->url,                // may be stale; hydrated below
                    'thumbnail_url' => $m->thumbnail_url,
                    'sort_order'    => $m->sort_order,
                    'meta'          => $m->meta,               // may contain ['ig_child_id'=>...]
                ])->toArray(),
            ];
        })->toArray();

        // Optionally hydrate fresh Instagram CDN URLs (recommended)
        if (!$preview && !empty($payload)) {
            try {
                // Collect all IG ids to hydrate: parent + children
                $igIds = [];
                foreach ($payload as $p) {
                    if (!empty($p['source_post_id'])) $igIds[] = $p['source_post_id']; // parent
                    foreach ($p['media'] as $m) {
                        $childId = $m['meta']['ig_child_id'] ?? null;
                        if ($childId) $igIds[] = $childId; // child for carousel
                    }
                }
                $igIds = array_values(array_unique(array_filter($igIds)));

                if ($igIds) {
                    // Returns map keyed by IG id: ['1784...'=> ['media_url'=>..., 'thumbnail_url'=>..., 'media_type'=>...], ...]
                    $igMap = $ig->hydrateMediaByIds($igIds);

                    // Replace stale urls with fresh ones
                    foreach ($payload as &$p) {
                        $isCarousel = ($p['extra']['media_type'] ?? null) === 'CAROUSEL_ALBUM';
                        if ($isCarousel) {
                            foreach ($p['media'] as &$m) {
                                $childId = $m['meta']['ig_child_id'] ?? null;
                                if ($childId && isset($igMap[$childId])) {
                                    $fresh = $igMap[$childId];
                                    $m['url']           = $fresh['media_url']     ?? $m['url'];
                                    $m['thumbnail_url'] = $fresh['thumbnail_url'] ?? $m['thumbnail_url'];
                                    // ensure type alignment (video vs image) if needed
                                    if (!empty($fresh['media_type'])) {
                                        $m['type'] = strtolower($fresh['media_type']) === 'video' ? 'video' : 'image';
                                    }
                                }
                            }
                        } else {
                            // Single IMAGE/VIDEO post: hydrate from parent
                            $parent = $igMap[$p['source_post_id']] ?? null;
                            if ($parent) {
                                foreach ($p['media'] as &$m) {
                                    $m['url']           = $parent['media_url']     ?? $m['url'];
                                    $m['thumbnail_url'] = $parent['thumbnail_url'] ?? $m['thumbnail_url'];
                                    if (!empty($parent['media_type'])) {
                                        $m['type'] = strtolower($parent['media_type']) === 'video' ? 'video' : 'image';
                                    }
                                }
                            }
                        }
                    }
                    unset($p, $m); // break references
                }
            } catch (\Throwable $e) {
                // Don’t fail the feed if hydration hiccups; log and continue with stored URLs
                Log::warning('IG hydration failed; falling back to stored urls', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->successResponse(
            'Success',
            [
                'items'      => $payload,
                'nextCursor' => $nextCursor,
            ]
        );
    }

}
