<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class FbAuthController extends Controller
{
    // 1) Send user to FB Login
    public function redirect()
    {
        $params = http_build_query([
            'client_id'     => config('services.facebook.client_id'),
            'redirect_uri'  => route('fb.callback'),
            'response_type' => 'code',
            'scope'         => 'public_profile,email,pages_show_list,instagram_basic,instagram_manage_insights',
        ]);

        return redirect("https://www.facebook.com/v19.0/dialog/oauth?{$params}");
    }

    // 2) Handle callback: code -> short token -> long-lived token
    public function callback(Request $r)
    {
        $code = $r->query('code');
        abort_unless($code, 400, 'Missing code');

        // Exchange code -> short-lived user token
        $short = Http::asForm()->post('https://graph.facebook.com/v19.0/oauth/access_token', [
            'client_id'     => config('services.facebook.client_id'),
            'client_secret' => config('services.facebook.client_secret'),
            'redirect_uri'  => route('fb.callback'),
            'code'          => $code,
        ])->throw()->json();

        // Short -> long-lived user token (~60 days)
        $long = Http::get('https://graph.facebook.com/v19.0/oauth/access_token', [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => config('services.facebook.client_id'),
            'client_secret'     => config('services.facebook.client_secret'),
            'fb_exchange_token' => $short['access_token'],
        ])->throw()->json();

        DB::table('social_tokens')->updateOrInsert(
            ['provider' => 'facebook', 'type' => 'user_long_lived'],
            [
              'access_token' => $long['access_token'],
              'expires_at'   => now()->addSeconds($long['expires_in'] ?? 60*60*24*60),
              'updated_at'   => now(),
              'created_at'   => now(),
            ]
        );

        // Store securely (DB/Secrets). For demo we cache it.
        Cache::put('fb_long_user_token', $long['access_token'], now()->addDays(55));

        return response()->json([
            'message' => 'Long-lived token saved',
            'expires_in' => $long['expires_in'] ?? null,
        ]);
    }

    // 3) List Pages user manages
    public function listPages()
    {
        $token = Cache::get('fb_long_user_token');
        abort_unless($token, 400, 'No token. Hit /auth/facebook/redirect first.');

        $pages = Http::get('https://graph.facebook.com/v19.0/me/accounts', [
            'access_token' => $token,
        ])->throw()->json();

        return response()->json($pages);
    }

    // 4) Get IG Business account id from a PAGE_ID (pass ?page_id=123)
    public function getIgAccount(Request $r)
    {
        $token = Cache::get('fb_long_user_token');
        $pageId = $r->query('page_id');
        abort_unless($token && $pageId, 400);

        $page = Http::get("https://graph.facebook.com/v19.0/{$pageId}", [
            'fields'        => 'instagram_business_account',
            'access_token'  => $token,
        ])->throw()->json();

        return response()->json($page);
    }

    // 5) Fetch media for IG_USER_ID (pass ?ig_user_id=1784...)
    public function getIgMedia(Request $r)
    {
        $token = Cache::get('fb_long_user_token');
        $igUserId = $r->query('ig_user_id');
        abort_unless($token && $igUserId, 400);

        $media = Http::get("https://graph.facebook.com/v19.0/{$igUserId}/media", [
            'fields'       => 'id,caption,media_type,media_url,permalink,thumbnail_url,timestamp',
            'access_token' => $token,
            'limit'        => 25,
        ])->throw()->json();

        return response()->json($media);
    }
}
