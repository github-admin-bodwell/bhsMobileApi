<?php

namespace Database\Seeders;

use App\Models\CommunityMedia;
use App\Models\CommunityPost;
use Illuminate\Database\Seeder;

class CommunitySeeder extends Seeder
{
    public function run(): void
    {
        $post = CommunityPost::create([
            'source'     => 'internal',
            'caption'    => 'Welcome to the new Community feed! 🎉',
            'posted_at'  => now()->subMinutes(5),
            'visibility' => 'public',
        ]);

        CommunityMedia::create([
            'post_id' => $post->id,
            'type'    => 'image',
            'url'     => 'https://picsum.photos/seed/bhs/900/600',
            'sort_order' => 0,
        ]);
    }
}
