<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramClient
{
    private string $base;
    private string $token;
    private string $userId;

    public function __construct()
    {
        $this->base   = 'https://graph.facebook.com/v19.0';

        // Load latest token from DB
        $record = DB::table('social_tokens')
            ->where('provider','facebook')
            ->where('type','user_long_lived')
            ->latest('updated_at')
            ->first();

        $this->token  = $record?->access_token ?? config('services.instagram.token');
        $this->userId = config('services.instagram.user_id'); // must be set to IG_USER_ID
    }

    public function fetchMedia(?string $after = null, int $limit = 25): array
    {
        $fields = implode(',', [
            'id',
            'caption',
            'media_type',
            'media_url',
            'permalink',
            'thumbnail_url',
            'timestamp',
            'children{media_type,media_url,thumbnail_url,id}',
        ]);

        $url = "{$this->base}/{$this->userId}/media";
        $query = [
            'fields'       => $fields,
            'access_token' => $this->token,
            'limit'        => $limit,
        ];
        if ($after) $query['after'] = $after;

        $resp = Http::retry(3, 1000, throw: false)
            ->timeout(20)
            ->acceptJson()
            ->get($url, $query);

        $body = mb_convert_encoding($resp->body(), 'UTF-8', 'UTF-8');
        $json = json_decode($body, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);

        if (!$resp->ok()) {
            $message = Arr::get($body, 'error.message') ?? $resp->body();
            throw new \RuntimeException("Instagram fetch failed: {$message}");
        }

        return $json;
    }

    /**
     * Returns [items, nextCursor]
     */
    public function page(?string $after = null, int $limit = 25): array
    {
        $data = $this->fetchMedia($after, $limit);
        $items = Arr::get($data, 'data', []);
        $next  = Arr::get($data, 'paging.cursors.after');

        foreach ($items as &$item) {
            $item['children'] = Arr::get($item, 'children.data', []);
        }

        return [$items, $next];
    }

    public function hydrateMediaByIds(array $ids): array {
        if (empty($ids)) return [];
        $idsCsv = implode(',', array_unique($ids));

        $fields = 'id,media_type,media_url,thumbnail_url';
        $url = "{$this->base}/";
        $resp = Http::get($url, [
            'ids'          => $idsCsv,
            'fields'       => $fields,
            'access_token' => $this->token,
        ])->throw();

        return $resp->json(); // keyed by id
    }
}
