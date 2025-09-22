<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\CommunityPostResource;
use App\Models\CommunityPost;
use Illuminate\Http\Request;

class FeedController extends Controller
{
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
}
