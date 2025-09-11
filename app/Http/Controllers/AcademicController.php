<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Semesters;
use App\Traits\HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AcademicController extends Controller
{
    use HttpResponse;

    public function getAcademics(Request $request) {
        $user = $request->user();
        $currentSemester = Semesters::getCurrentSemester();

        $getAcademics = DB::query()
                        ->from('tblBHSStudentSubject AS studentCourse')
                        ->join('tblBHSSubject AS course', 'studentCourse.SubjectID', 'course.SubjectID')
                        ->join('tblStaff AS staff', 'course.TeacherID', 'staff.StaffID')
                        ->join('tblBHSSemester AS semester', 'course.SemesterID', 'semester.SemesterID')
                        ->where('course.SemesterID', '<=', $currentSemester->SemesterID)
                        ->where('studentCourse.StudNum', $user->StudentID ?? $user->UserID)
                        ->whereNotLike('course.SubjectName', 'YYY%')
                        ->orderByDesc('course.Credit')
                        ->get([
                            'course.SemesterID AS semesterId',
                            'semester.SemesterName AS semesterName',
                            'studentCourse.StudSubjID AS studentCourseId',
                            'studentCourse.StudNum AS studentId',
                            'studentCourse.SubjectID AS courseId',
                            'course.SubjectName AS courseName',
                            'staff.StaffID AS teacherId',
                            'staff.Photo AS staffPhoto',
                            'staff.FirstName AS teacherFirstName',
                            'staff.LastName AS teacherLastName',
                        ])
                        ->groupBy('semesterId')
                        ->map(function($items, $semsterId) {
                            return [
                                'title' => $items->first()->semesterName,
                                'data' => $items->values(),
                            ];
                        })
                        ->values();

        $studentCourseList = $getAcademics
            ->flatMap(fn ($g) => $g['data'])
            ->pluck('studentCourseId')
            ->unique()
            ->values();

        $attendance = DB::table('tblBHSAttendance')
            ->selectRaw('StudSubjID AS studentCourseId, SUM(AbsencePeriod) AS absenceCount, SUM(LatePeriod) AS lateCount')
            ->whereIn('StudSubjID', $studentCourseList)
            ->groupBy('StudSubjID')
            ->get()
            ->keyBy('studentCourseId');

        $groups = $getAcademics->map(function ($group) use ($attendance) {
            $group['data'] = $group['data']->map(function ($row) use ($attendance) {
                $totals = $attendance->get($row->studentCourseId);
                $row->absenceCount = (int)($totals->absenceCount ?? 0);
                $row->lateCount    = (int)($totals->lateCount ?? 0);
                return $row;
            });
            return $group;
        });

        return $this->successResponse(
            'Success',
            [
                'academics' => $groups,
            ]
        );
    }
}
