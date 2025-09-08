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

        $today = date_create('2025-02-13')->format('Y-m-d'); //now(); // always the current date

        $dailyAnnouncements = Announcements::where('ADate', $today)->orderBy('DAID', 'DESC')->get();

        return $this->successResponse(
            'Annoucements retrieved successfull!',
            [
                'announcements' => $dailyAnnouncements
            ]
        );
    }
}
