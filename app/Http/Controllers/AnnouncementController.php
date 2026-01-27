<?php

namespace App\Http\Controllers;

use App\Models\Announcements;
use App\Models\Semesters;
use App\Traits\HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnnouncementController extends Controller
{
    use HttpResponse;

    public function getAnnouncements(Request $request) {

        $todayStart = now()->startOfDay();
        $todayEnd = now()->endOfDay();

        $dailyAnnouncements = Announcements::whereBetween('ADate', [$todayStart, $todayEnd])
            ->orderBy('DAID', 'DESC')
            ->get();

        return $this->successResponse(
            'Annoucements retrieved successfull!',
            [
                'announcements' => $dailyAnnouncements
            ]
        );
    }
}
