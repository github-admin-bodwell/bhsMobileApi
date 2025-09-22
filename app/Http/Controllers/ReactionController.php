<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CommunityPost;
use App\Models\CommunityReaction;
use Illuminate\Http\Request;

class ReactionController extends Controller
{
    public function toggle(Request $request, CommunityPost $post) {
        $data = $request->validate([
            'type' => ['nullable','in:like,heart,clap,wow']
        ]);
        $type = $data['type'] ?? 'like';

        $reaction = CommunityReaction::where('post_id', $post->id)
            ->where('user_type', get_class($request->user()))
            ->where('user_id', $request->user()->getKey())
            ->first();

        if ($reaction) {
            $reaction->delete();
            $post->decrement('like_count');
            $status = 'removed';
        } else {
            CommunityReaction::create([
                'post_id'   => $post->id,
                'user_type' => get_class($request->user()),
                'user_id'   => $request->user()->getKey(),
                'type'      => $type,
            ]);
            $post->increment('like_count');
            $status = 'added';
        }

        return response()->json(['data' => ['status' => $status, 'like_count' => $post->like_count]]);
    }
}
