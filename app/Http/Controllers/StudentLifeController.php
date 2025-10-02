<?php

namespace App\Http\Controllers;

use App\Models\Semesters;
use App\Models\StudentActivties;
use App\Traits\HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StudentLifeController extends Controller
{
    use HttpResponse;

    public function getStudentLife(Request $request, $semesterId = null) {
        $user = $request->user();
        $currentSemester = Semesters::getCurrentSemester();

        if( $semesterId === null ) {
            $semesterId = $currentSemester->SemesterID;
        } 

        $studentId = $user->StudentID ?? $user->UserID;

        $activityHours = DB::table('tblBHSSPStudentActivities as A')
            ->leftJoin('tblBHSSPActivityConfig as C', 'C.ActivityCategory', '=', 'A.ActivityCategory')
            ->where('A.StudentID', $studentId)
            ->where('A.SemesterID', '<=', $semesterId)
            ->where('A.ActivityStatus', 80)
            ->groupBy('A.ActivityCategory', 'C.Title', 'C.Body') 
            ->orderBy('A.ActivityCategory', 'ASC')
            ->selectRaw(
                'A.ActivityCategory,
                COALESCE(C.Title, \'\') AS CategoryTitle,
                C.Body AS Body,
                MIN(A.SemesterID) as FirstSemesterID,
                SUM(CASE WHEN A.SemesterID = ? THEN A.Hours ELSE 0 END) AS CurrentHours,
                SUM(A.Hours) AS AccumHours,
                SUM(CASE WHEN A.SemesterID = ? AND A.VLWE = 1 THEN A.Hours ELSE 0 END) AS VLWEHours',
                [$semesterId, $semesterId]
            )
            ->get();

        $activities = DB::query()
                        ->from('tblBHSSPStudentActivities', 'A')
                        ->leftJoin('tblBHSSPActivity AS P', 'A.ActivityID', 'P.ActivityID')
                        ->leftJoin('tblStaff AS D', 'A.ApproverStaffID', 'D.StaffID')
                        ->leftJoin('tblBHSSPActivityConfig AS C', 'C.ActivityCategory', 'A.ActivityCategory')
                        ->where('A.StudentID', $studentId)
                        ->where('A.SemesterID', '=', $semesterId)
                        ->orderByDesc('A.SDate')
                        ->get([
                            'A.StudentActivityID AS activityId',
                            'A.Title AS Title',
                            'C.Title AS CategoryTitle',
                            'C.Body',
                            'A.ActivityCategory AS category',
                            'P.Location',
                            DB::raw('CONVERT(CHAR(10), A.SDate, 126) AS activityDate'),
                            'A.SDate AS startDate',
                            'A.EDate AS endDate',
                            'A.Body AS description',
                            'A.ApproverStaffID AS staffId',
                            'D.FirstName AS staffFirstName',
                            'D.LastName AS staffLastName',
                            'A.ActivityStatus',
                            'A.Hours AS hours',
                            'A.VLWE AS qvwh',
                            'A.StudentID AS studentId',
                            'A.StudentID AS studentId',
                            'A.SemesterID AS termId',
                            'A.VLWE AS VLWE'
                        ]);


        $byCategory = $activities->groupBy('category');

        $merged = $activityHours->map(function ($cat) use ($byCategory) {
            $cat->activities = ($byCategory->get($cat->ActivityCategory) ?? collect())->values();
            return $cat;
        });

        $lowestSemesterId = $merged->min('FirstSemesterID');
        $since = Semesters::where('SemesterID', $lowestSemesterId)->first();

        $totalCurrentHours = (float) $activityHours->sum('CurrentHours');
        $totalVLWEHours    = (float) $activityHours->sum('VLWEHours');

        $payload = [
            'activities'        => $merged,
            'since' => $since->SemesterName,
            'totalCurrentHours' => number_format($totalCurrentHours, 1, '.'),
            'totalVLWEHours'    => number_format($totalVLWEHours, 1, '.'),
        ];

        return $this->successResponse('Success', $payload);
    }
}
