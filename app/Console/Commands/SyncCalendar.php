<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\{CalendarSource, CalendarEvent};
use Carbon\CarbonImmutable;
use ICal\ICal;
use Illuminate\Support\Str;

class SyncCalendar extends Command {
    protected $signature = 'calendar:sync {--source=}';
    protected $description = 'Sync iCal feeds into calendar_events';

    public function handle(): int {
        $q = CalendarSource::query();
        if ($n = $this->option('source')) $q->where('name', $n);
        $sources = $q->get();

        foreach ($sources as $source) {
            $this->info("Syncing: {$source->name}");
            $res = Http::withHeaders([
                'If-None-Match'     => $source->etag ?? '',
                'If-Modified-Since' => optional($source->last_modified)->toRfc7231String() ?? '',
            ])->timeout(30)->get($source->url);

            if ($res->status() === 304) { $this->line('  Not modified.'); continue; }
            $res->throw();

            $etag = $res->header('ETag');
            $lm   = $res->header('Last-Modified') ? CarbonImmutable::parse($res->header('Last-Modified')) : null;

            $ical = new ICal(false, [
                'defaultSpan'    => (int) $source->default_span_days,
                'defaultTimeZone'=> $source->tz,
                'skipRecurrence' => false,
            ]);
            $ical->initString($res->body());

            $now = now();
            foreach ($ical->events() as $e) {
                $start = CarbonImmutable::parse($e->dtstart_array[3], $source->tz)->utc();
                $end   = isset($e->dtend_array[3]) ? CarbonImmutable::parse($e->dtend_array[3], $source->tz)->utc() : null;

                $payload = [
                    'summary'     => $e->summary ?? '(No title)',
                    'description' => $e->description ?? null,
                    'location'    => $e->location ?? null,
                    'all_day'     => ($e->dtstart_array[0]['VALUE'] ?? null) === 'DATE',
                    'start_at'    => $start,
                    'end_at'      => $end,
                    'status'      => strtoupper($e->status ?? 'CONFIRMED'),
                ];
                $payload['hash'] = sha1(json_encode($payload));
                $payload['last_seen_at'] = $now;

                $event = CalendarEvent::firstOrNew([
                    'source_id' => $source->id,
                    'uid'       => $e->uid ?? Str::uuid()->toString(),
                    'start_at'  => $start,
                ]);

                if (!$event->exists || $event->hash !== $payload['hash']) {
                    $event->fill($payload)->save();
                } else {
                    $event->last_seen_at = $now; $event->save();
                }
            }

            // mark vanishing instances as cancelled (keeps history consistent)
            CalendarEvent::where('source_id',$source->id)
                ->where('last_seen_at','<', $now->subDays(2))
                ->update(['status'=>'CANCELLED','last_seen_at'=>$now]);

            $source->update(['etag'=>$etag, 'last_modified'=>$lm]);
            $this->line('  Done.');
        }
        return self::SUCCESS;
    }
}
