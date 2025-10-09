<?php

namespace App\Http\Controllers;

use App\Models\Semesters;
use App\Models\StudentActivties;
use App\Traits\HttpResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StudentLifeController extends Controller
{
    use HttpResponse;

    public function getStudentLife(Request $request, $semesterId = null) {
        $user = $request->user();
        $currentSemester = Semesters::getCurrentSemester();

        if ($semesterId === null) {
            $semesterId = $currentSemester->SemesterID;
        }

        $studentId = $user->StudentID ?? $user->UserID;

        // --- HOURS SUMMARY PER CATEGORY (unchanged query)
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

        // --- STUDENT ACTIVITIES (unchanged query)
        $studentActivitiesRaw = DB::query()
            ->from('tblBHSSPStudentActivities', 'A')
            ->leftJoin('tblBHSSPActivity AS P', 'A.ActivityID', 'P.ActivityID')
            ->leftJoin('tblStaff AS D', 'A.ApproverStaffID', 'D.StaffID')
            ->leftJoin('tblBHSSPActivityConfig AS C', 'C.ActivityCategory', 'A.ActivityCategory')
            ->where('A.StudentID', $studentId)
            ->where('A.SemesterID', '<=', $semesterId)
            ->orderByDesc('A.SDate')
            ->get([
                'A.StudentActivityID AS activityId',
                'A.Title AS title',
                'C.Title AS categoryTitle',
                'C.body',
                'A.ActivityCategory AS category',
                'P.location',
                DB::raw('CONVERT(CHAR(10), A.SDate, 126) AS activityDate'),
                'A.SDate AS startDate',
                'A.EDate AS endDate',
                'A.Body AS description',
                'A.ApproverStaffID AS staffId',
                'D.FirstName AS staffFirstName',
                'D.LastName AS staffLastName',
                'A.activityStatus',
                'A.Hours AS hours',
                'A.VLWE AS qvwh',
                'A.StudentID AS studentId',
                'A.SemesterID AS termId',
                'A.VLWE AS VLWE'
            ]);

        // ---- NORMALIZER: student activity -> unified shape
        $normalizeStudent = function ($a) {
            $staffFirst = $a->staffFirstName ?? '';
            $staffLast  = $a->staffLastName ?? '';
            return (object) [
                'activityId'        => (string) ($a->activityId ?? ''),
                'title'             => (string) ($a->title ?? ''),
                'category'          => (string) ($a->category ?? ''), // code
                'categoryTitle'     => (string) ($a->categoryTitle ?? ''),
                'categoryDescription'=> $a->body ?? null,

                'startDate'         => (string) ($a->startDate ?? ''),
                'endDate'           => (string) ($a->endDate ?? ''),
                'activityDate'      => (string) ($a->activityDate ?? ''),
                'location'          => $a->location ?? null,
                'latitude'          => null,
                'longitude'         => null,

                'staffId'           => $a->staffId ?? null,
                'staffFirstName'    => $staffFirst,
                'staffLastName'     => $staffLast,
                'staffName'         => trim(($a->staffName ?? '') ?: trim($staffFirst.' '.$staffLast)),

                'description'       => $a->description ?? null,
                'baseHours'         => (string) ($a->hours ?? '0'),
                'hours'             => (string) ($a->hours ?? '0'),
                'VLWE'              => (string) ($a->VLWE ?? $a->qvwh ?? '0'),
                'activityStatus'    => $a->activityStatus ?? null,
                'studentId'         => $a->studentId ?? null,
                'termId'            => $a->termId ?? null,
            ];
        };

        // Group student activities by category code for merging into hours blocks
        $byCategory = $studentActivitiesRaw->groupBy('category');

        // Build category blocks with unified student activities
        $merged = $activityHours->map(function ($cat) use ($byCategory, $normalizeStudent) {
            $items = ($byCategory->get($cat->ActivityCategory) ?? collect())->map($normalizeStudent)->values();
            return (object) [
                'category'            => (string) $cat->ActivityCategory,
                'categoryTitle'       => (string) $cat->CategoryTitle,
                'categoryDescription' => (string) ($cat->Body ?? ''),
                'firstSemesterId'     => (string) ($cat->FirstSemesterID ?? ''),
                'currentHours'        => number_format((float) $cat->CurrentHours, 1, '.'),
                'accumHours'          => number_format((float) $cat->AccumHours, 1, '.'),
                'vlweHours'           => number_format((float) $cat->VLWEHours, 1, '.'),
                'activities'          => $items,
            ];
        });

        $lowestSemesterId = $merged->min('firstSemesterId');
        $since = $lowestSemesterId
            ? Semesters::where('SemesterID', $lowestSemesterId)->value('SemesterName')
            : '';

        $totalCurrentHours = (float) $activityHours->sum('CurrentHours');
        $totalVLWEHours    = (float) $activityHours->sum('VLWEHours');

        // --- SCHOOL ACTIVITIES (unchanged query)
        $schoolActivitiesRaw = DB::table('tblBHSSPActivity as activity')
            ->leftJoin('tblBHSSPActivityConfig as category', 'activity.ActivityCategory', '=', 'category.ActivityCategory')
            ->leftJoin('tblStaff as staff', 'activity.StaffID', '=', 'staff.StaffID')
            ->leftJoin('tblStaff as staff2', 'activity.StaffID2', '=', 'staff2.StaffID')
            ->where('activity.SemesterID', $currentSemester->SemesterID)
            ->where('activity.StartDate', '>=', DB::raw('DATEADD(DAY, -300, GETDATE())'))
            ->orderByDesc('activity.StartDate')
            ->select([
                'category.ActivityCategory as categoryCode',
                'category.Title as categoryTitle',
                'category.Body as categoryDescription',
                'activity.SemesterID as termId',
                'activity.ActivityID as activityId',
                'activity.Title as title',
                'activity.Body as description',
                'activity.ActivityType as activityType',
                'activity.MeetingPlace as meetingPlace',
                'activity.StaffID as staffId',
                'staff.FirstName as staffFirstName',
                'staff.LastName as staffLastName',
                DB::raw("CONCAT(staff.FirstName, ' ', staff.LastName) as staffName"),
                'activity.StaffID2 as staffId2',
                DB::raw("CONCAT(staff2.FirstName, ' ', staff2.LastName) as staff2Name"),
                'activity.Location as location',
                'activity.Latitude as latitude',
                'activity.Longitude as longitude',
                'activity.BaseHours as baseHours',
                'activity.StartDate as startDate',
                'activity.EndDate as endDate',
                'activity.AllDay as allDay',
                'activity.DPA as dpa',
                'activity.VLWE as VLWE',
                DB::raw('ISNULL(activity.CurrentEnrolment, 0) as curEnroll'),
                DB::raw('ISNULL(activity.PendingEnrolment, 0) as penEnroll'),
                DB::raw('ISNULL(activity.MaxEnrolment, 0) as maxEnroll'),
                DB::raw('ISNULL(activity.MaxEnrolment, 0) - ISNULL(activity.CurrentEnrolment, 0) as SubstractNum'),
                DB::raw('CASE WHEN activity.StartDate <= GETDATE() THEN 1 ELSE 0 END as overdue'),
            ])
            ->get();

        // ---- NORMALIZER: school activity -> unified shape
        $normalizeSchool = function ($a) {
            $staffFirst = $a->staffFirstName ?? '';
            $staffLast  = $a->staffLastName ?? '';
            return (object) [
                'activityId'        => (string) ($a->activityId ?? ''),
                'title'             => (string) ($a->title ?? ''),
                'category'          => (string) ($a->categoryCode ?? $a->category ?? ''),
                'categoryTitle'     => (string) ($a->categoryTitle ?? ''),
                'categoryDescription'=> $a->categoryDescription ?? $a->body ?? null,

                'startDate'         => (string) ($a->startDate ?? ''),
                'endDate'           => (string) ($a->endDate ?? ''),
                'activityDate'      => '', // not used for public activities
                'location'          => $a->location ?? null,
                'latitude'          => isset($a->latitude) ? (string) $a->latitude : null,
                'longitude'         => isset($a->longitude) ? (string) $a->longitude : null,

                'staffId'           => $a->staffId ?? null,
                'staffFirstName'    => $staffFirst,
                'staffLastName'     => $staffLast,
                'staffName'         => trim(($a->staffName ?? '') ?: trim($staffFirst.' '.$staffLast)),

                'description'       => $a->description ?? null,
                'baseHours'         => (string) ($a->baseHours ?? '0'),
                'hours'             => (string) ($a->baseHours ?? '0'),
                'VLWE'              => (string) ($a->VLWE ?? '0'),
                'activityStatus'    => null,
                'studentId'         => null,
                'termId'            => $a->termId ?? null,
            ];
        };

        $schoolActivities = $schoolActivitiesRaw->map($normalizeSchool)->values();

        // Upcoming: strictly future vs now, pick earliest 2
        $now = '2025-09-01'; // Carbon::now();
        $upcomingActivities = $schoolActivities
            ->filter(fn ($a) => $a->startDate && Carbon::parse($a->startDate)->greaterThan($now))
            ->sortBy('startDate')      // soonest first
            ->take(2)
            ->values();

        $payload = [
            'studentId'         => $studentId,
            'activities'        => $merged, // category blocks with unified student activities
            'since'             => $since ?? "",
            'totalCurrentHours' => number_format($totalCurrentHours, 1, '.'),
            'totalVLWEHours'    => number_format($totalVLWEHours, 1, '.'),
            'schoolActivities'  => $schoolActivities,
            'upcomingActivities'=> $upcomingActivities,
        ];

        return $this->successResponse(
            'Success',
            [
                'studentId'         => $studentId,
                'activities'        => $merged, // category blocks with unified student activities
                'since'             => $since ?? "",
                'totalCurrentHours' => number_format($totalCurrentHours, 1, '.'),
                'totalVLWEHours'    => number_format($totalVLWEHours, 1, '.'),
                'schoolActivities'  => $schoolActivities,
                'upcomingActivities'=> $upcomingActivities,
            ]
        );
    }
}
