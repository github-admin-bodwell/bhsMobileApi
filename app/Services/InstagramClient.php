<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class InstagramClient
{
    private string $base;
    private string $token;
    private string $userId;

    public function __construct()
    {
        $this->base   = 'https://graph.instagram.com';
        $this->token  = config('services.instagram.token');
        $this->userId = config('services.instagram.user_id', 'me');
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
            // For carousel children:
            'children{media_type,media_url,thumbnail_url,id}'
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
            ->get($url, $query);

        if (!$resp->ok()) {
            $body = $resp->json();
            $message = Arr::get($body, 'error.message') ?? $resp->body();
            throw new \RuntimeException("Instagram fetch failed: {$message}");
        }

        return $resp->json();
    }

    /**
     * Returns [items, nextCursor]
     * Each item normalized:
     *  - id, caption, media_type (IMAGE|VIDEO|CAROUSEL_ALBUM)
     *  - media_url/permalink/thumbnail_url/timestamp
     *  - children: array of [{id, media_type, media_url, thumbnail_url}]
     */
    public function page(?string $after = null, int $limit = 25): array
    {
        $data = $this->fetchMedia($after, $limit);
        $items = Arr::get($data, 'data', []);
        $next  = Arr::get($data, 'paging.cursors.after');

        // Normalize children to arrays
        foreach ($items as &$item) {
            $children = Arr::get($item, 'children.data', []);
            $item['children'] = $children;
            unset($item['children']['data']);
        }

        return [$items, $next];
    }
}
