<?php

namespace App\Http\Controllers;

use App\Models\Semesters;
use App\Models\StudentActivties;
use App\Traits\HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentLifeController extends Controller
{
    use HttpResponse;

    public function getStudentLife(Request $request, $semesterId = null) {
        $user = $request->user();
        $currentSemester = Semesters::getCurrentSemester();
        if( $semesterId === null ) {
            $semesterId = $currentSemester->SemesterId;
        }

        $activityHours = DB::query()
                        ->from('tblBHSSPStudentActivities', 'A')
                        ->leftJoin('tblBHSSPActivityConfig AS C', 'C.ActivityCategory', 'A.ActivityCategory')
                        ->where('A.StudentID', $user->StudentID ?? $user->UserID)
                        ->where('A.SemesterID', 92)
                        ->where('A.ActivityStatus', 80)
                        ->groupBy(['A.ActivityCategory', 'C.Title', 'C.Body'])
                        ->orderBy('A.ActivityCategory', 'ASC')
                        ->get([
                            'A.ActivityCategory',
                            'C.Title AS CategoryTitle',
                            'C.Body',
                            DB::raw('SUM(CASE WHEN A.SemesterID=92 THEN A.Hours Else 0 End) AS CurrentHours'),
                            DB::raw('SUM(A.Hours) AS AccumHours'),
                            DB::raw('SUM(CASE WHEN A.SemesterID=92 AND A.VLWE = 1 THEN A.Hours Else 0 End) AS VLWEHours')
                        ]);

        $activities = DB::query()
                        ->from('tblBHSSPStudentActivities', 'A')
                        ->leftJoin('tblBHSSPActivity AS P', 'A.ActivityID', 'P.ActivityID')
                        ->leftJoin('tblStaff AS D', 'A.ApproverStaffID', 'D.StaffID')
                        ->leftJoin('tblBHSSPActivityConfig AS C', 'C.ActivityCategory', 'A.ActivityCategory')
                        ->where('A.StudentID', $user->StudentID ?? $user->UserID)
                        ->where('A.SemesterID', 92)
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

        
        $byTitle = $activities->groupBy('CategoryTitle');
        $merged = $activityHours->map(function ($cat) use ($byTitle) {
            $cat->activities = ($byTitle->get($cat->CategoryTitle) ?? collect())->values();
            return $cat;
        });


        return $this->successResponse(
            'Success',
            [ 
                'semesterId' => $semesterId, 
                'activities' => $merged
            ]
        );
    }
}
