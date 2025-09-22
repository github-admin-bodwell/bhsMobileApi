<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CommunityController extends Controller
{
    public function getInstagramPosts()
    {
        $token = config('services.instagram.token'); // store in config/services.php or .env
        $url = "https://graph.instagram.com/me/media";

        $response = Http::get($url, [
            'fields' => 'id,caption,media_type,media_url,permalink,thumbnail_url,timestamp',
            'access_token' => $token,
        ]);

        return response()->json([
            'data' => $response->json()['data'] ?? []
        ]);
    }
}
