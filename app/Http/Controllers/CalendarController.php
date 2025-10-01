<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CalendarEvent;
use App\Traits\HttpResponse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class CalendarController extends Controller {

    use HttpResponse;

    public function index(Request $req) {
        $tz = 'America/Vancouver';
        $start = $req->query('start') ? CarbonImmutable::parse($req->query('start')) : now()->subMonths(1);
        $end   = $req->query('end')   ? CarbonImmutable::parse($req->query('end'))   : now()->addMonths(12);
        $q = CalendarEvent::query()
            ->where('status','!=','CANCELLED')
            ->whereBetween('start_at', [$start->utc(), $end->utc()])
            ->orderBy('start_at');

        if ($s = $req->query('q')) {
            $q->where(function($w) use ($s){
                $w->where('summary','like',"%$s%")
                  ->orWhere('location','like',"%$s%")
                  ->orWhere('description','like',"%$s%");
            });
        }

        $rows = $q->limit(5000)->get();
        $events = $rows->map(fn($e) => [
            'id'     => (string)$e->id,
            'title'  => $e->summary,
            'start'  => $e->start_at->tz($tz)->toIso8601String(),
            'end'    => optional($e->end_at)->tz($tz)?->toIso8601String(),
            'allDay' => (bool)$e->all_day,
            'location' => $e->location,
            'description' => $e->description,
            'status'  => $e->status,
        ]);
        Log::info('response', ['events'=>$events]);
        return $this->successResponse(
            'Sucecss',
            [ 'events' => $events ]
        );

    }

}
