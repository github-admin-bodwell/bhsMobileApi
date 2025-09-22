<?php

namespace App\Console\Commands;

use App\Models\CommunityMedia;
use App\Models\CommunityPost;
use App\Services\InstagramClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SyncInstagram extends Command
{
    protected $signature = 'community:sync-instagram
        {--limit=100 : Max total items to process}
        {--page-size=25 : Page size per IG request}
        {--since= : ISO date/time; stop when IG timestamp < since}
        {--dry : Dry-run (no DB writes)}';

    protected $description = 'Sync Instagram Graph media into community_posts/community_media';

    public function handle(InstagramClient $ig): int
    {
        $limit      = (int)$this->option('limit');
        $pageSize   = (int)$this->option('page-size');
        $dry        = (bool)$this->option('dry');
        $sinceInput = $this->option('since');
        $since      = $sinceInput ? CarbonImmutable::parse($sinceInput) : null;

        $processed = 0;
        $after     = null;

        $this->info("Starting Instagram sync (limit={$limit}, pageSize={$pageSize}, dry=" . ($dry ? 'yes' : 'no') . ')');

        try {
            while ($processed < $limit) {
                [$items, $next] = $ig->page($after, min($pageSize, $limit - $processed));
                if (empty($items)) {
                    $this->line('No more items from Instagram.');
                    break;
                }

                foreach ($items as $item) {
                    // Stop early if older than --since
                    $ts = Arr::get($item, 'timestamp');
                    $postedAt = $ts ? CarbonImmutable::parse($ts) : null;
                    if ($since && $postedAt && $postedAt->lt($since)) {
                        $this->line("Reached item older than --since ({$since->toIso8601String()}); stopping.");
                        return self::SUCCESS;
                    }

                    $processed++;

                    // Map to DB rows
                    $igId         = Arr::get($item, 'id');
                    $caption      = Arr::get($item, 'caption');
                    $mediaType    = Arr::get($item, 'media_type'); // IMAGE|VIDEO|CAROUSEL_ALBUM
                    $mediaUrl     = Arr::get($item, 'media_url');
                    $permalink    = Arr::get($item, 'permalink');
                    $thumb        = Arr::get($item, 'thumbnail_url');
                    $children     = Arr::get($item, 'children', []);
                    $posted       = $postedAt?->toDateTimeString();

                    $this->line("• Upserting IG {$igId} ({$mediaType})" . ($dry ? ' [DRY]' : ''));

                    if ($dry) {
                        continue;
                    }

                    DB::transaction(function () use ($igId, $caption, $permalink, $posted, $mediaType, $mediaUrl, $thumb, $children) {
                        /** @var CommunityPost $post */
                        $post = CommunityPost::query()->updateOrCreate(
                            ['source' => 'instagram', 'source_post_id' => $igId],
                            [
                                'caption'       => $caption,
                                'permalink'     => $permalink,
                                'posted_at'     => $posted,
                                'visibility'    => 'public',
                                'extra'         => [
                                    'media_type' => $mediaType,
                                ],
                            ]
                        );

                        // Clear & re-seed media for idempotency (simpler than diffing)
                        $post->media()->delete();

                        if ($mediaType === 'CAROUSEL_ALBUM') {
                            foreach ($children as $idx => $child) {
                                $childType = Arr::get($child, 'media_type', 'IMAGE');
                                CommunityMedia::create([
                                    'post_id'       => $post->id,
                                    'type'          => strtolower($childType) === 'video' ? 'video' : 'image',
                                    'url'           => Arr::get($child, 'media_url'),
                                    'thumbnail_url' => Arr::get($child, 'thumbnail_url'),
                                    'sort_order'    => $idx,
                                    'meta'          => [
                                        'ig_child_id' => Arr::get($child, 'id'),
                                    ],
                                ]);
                            }
                        } else {
                            // IMAGE or VIDEO
                            CommunityMedia::create([
                                'post_id'       => $post->id,
                                'type'          => strtolower($mediaType) === 'video' ? 'video' : 'image',
                                'url'           => $mediaUrl,
                                'thumbnail_url' => $thumb,
                                'sort_order'    => 0,
                                'meta'          => null,
                            ]);
                        }
                    });

                    if ($processed >= $limit) {
                        break;
                    }
                }

                if (!$next) {
                    $this->line('No next page cursor from Instagram.');
                    break;
                }

                $after = $next;
            }
        } catch (\Throwable $e) {
            $this->error('Sync failed: ' . $e->getMessage());
            report($e);
            return self::FAILURE;
        }

        $this->info("Done. Processed {$processed} items.");
        return self::SUCCESS;
    }
}
