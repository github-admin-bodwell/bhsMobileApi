<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CommunityComment;
use App\Models\CommunityPost;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function store(Request $request, CommunityPost $post) {
        $data = $request->validate([
            'body'      => ['required','string','max:3000'],
            'parent_id' => ['nullable','ulid'],
        ]);

        $comment = CommunityComment::create([
            'post_id'    => $post->id,
            'author_type'=> get_class($request->user()),
            'author_id'  => $request->user()->getKey(),
            'body'       => $data['body'],
            'parent_id'  => $data['parent_id'] ?? null,
        ]);

        $post->increment('comment_count');

        return response()->json(['data' => [
            'id'        => $comment->id,
            'body'      => $comment->body,
            'created_at'=> $comment->created_at,
        ]], 201);
    }
}
