<?php

namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\CalendarSource;

class CalendarSourceSeeder extends Seeder {
    public function run(): void {
        CalendarSource::firstOrCreate(
            ['name' => 'Finalsite – Public Events'],
            [
                'url' => 'https://www.bodwell.edu/cf_calendar/feed.cfm?type=ical&feedID=466CB34270944B21A264D8F8B3052347',
                'tz'  => 'America/Vancouver',
                'default_span_days' => 400
            ]
        );
    }
}
