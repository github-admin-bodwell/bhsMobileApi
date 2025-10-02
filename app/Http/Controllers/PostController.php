<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\CommunityPostResource;
use App\Models\CommunityMedia;
use App\Models\CommunityPost;
use App\Traits\HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PostController extends Controller
{
    use HttpResponse;

    public function show(CommunityPost $post) {
        $post->load(['media' => fn($q)=>$q->orderBy('sort_order')]);

        // return $this->successResponse(
        //     'Success',
        //     new CommunityPostResource($post)
        // );

        return response()->json(['data' => new CommunityPostResource($post)]);
    }

    public function store(Request $request) {
        $data = $request->validate([
            'caption'     => ['nullable','string'],
            'visibility'  => ['required','in:public,school,private'],
            'media'       => ['required','array','min:1'],
            'media.*.url' => ['required','url'],
            'media.*.type'=> ['required','in:image,video,carousel'],
        ]);

        return DB::transaction(function () use ($request, $data) {
            $post = CommunityPost::create([
                'source'        => 'internal',
                'caption'       => $data['caption'] ?? null,
                'posted_at'     => now(),
                'visibility'    => $data['visibility'],
                'author_type'   => get_class($request->user()), // optional: adjust to your auth model
                'author_id'     => $request->user()->getKey(),
            ]);

            foreach ($data['media'] as $i => $m) {
                CommunityMedia::create([
                    'post_id'       => $post->id,
                    'type'          => $m['type'],
                    'url'           => $m['url'],
                    'thumbnail_url' => $m['thumbnail_url'] ?? null,
                    'width'         => $m['width'] ?? null,
                    'height'        => $m['height'] ?? null,
                    'sort_order'    => $i,
                ]);
            }

            $post->load('media');
            return response()->json(['data' => new CommunityPostResource($post)], 201);
        });
    }
}
