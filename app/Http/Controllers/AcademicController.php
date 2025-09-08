<?php

namespace App\Http\Controllers;

use App\Models\Semesters;
use App\Traits\HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AcademicController extends Controller
{
    use HttpResponse;

    /*  
        GET Academics
            SELECT
            course.SemesterID termId,
            studentCourse.StudSubjID studentCourseId,
            studentCourse.StudNum studentId,
            studentCourse.SubjectID courseId,
            course.SubjectName courseName,
            staff.StaffID teacherId,
            CONCAT(staff.FirstName, ' ', staff.LastName) teacherName,
            staff.FirstName teacherFirstName,
            staff.LastName teacherLastName,
            course.PName provincialName,
            course.CourseCd courseCode,
            course.RoomNo roomNo,
            course.Cap cap,
            course.Spa spa,
            course.Credit credit,
            course.Type courseType
            FROM tblBHSStudentSubject studentCourse
            JOIN tblBHSSubject course ON studentCourse.SubjectID = course.SubjectID
            JOIN tblStaff staff ON course.TeacherID = staff.StaffID
            WHERE course.SemesterID=:termId AND studentCourse.StudNum=:studentId AND course.SubjectName NOT LIKE 'YYY%'
            ORDER BY
            course.Credit DESC,
            course.SubjectName ASC

        absent something
        SELECT t.SemesterID, t.SubjectID, t.SubjectName, a.ADate, a.AbsencePeriod, a.LatePeriod, a.Excuse, a.Excusetxt
        FROM tblBHSAttendance a
        LEFT JOIN tblBHSStudentSubject s on a.StudSubjID = s.StudSubjID
        LEFT JOIN tblBHSSubject t on t.SubjectID = s.SubjectID
        WHERE s.StudNum = :studentId AND t.SemesterID = :SemesterID AND (a.AbsencePeriod != 0 OR a.LatePeriod != 0)
        order by a.ADate desc",

        // Past Term List
        SELECT d.SemesterID, d.SemesterName
            FROM tblBHSStudentSubject b
            LEFT JOIN tblBHSSubject c ON b.SubjectID = c.SubjectID
            LEFT JOIN tblBHSSemester d ON c.SemesterID = d.SemesterID
            where StudNum = :studentId AND d.SemesterID<=:SemesterID
            group by d.SemesterID, d.SemesterName
            order by d.SemesterID desc",

        // Absert Report List
        SELECT t.SemesterID, t.SubjectID, t.SubjectName, a.ADate, a.AbsencePeriod, a.LatePeriod, a.Excuse, a.Excusetxt
        FROM tblBHSAttendance a
        LEFT JOIN tblBHSStudentSubject s on a.StudSubjID = s.StudSubjID
        LEFT JOIN tblBHSSubject t on t.SubjectID = s.SubjectID
        WHERE s.StudNum = :studentId AND t.SemesterID = :SemesterID AND (a.AbsencePeriod != 0 OR a.LatePeriod != 0)
        order by a.ADate desc",
    */
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

        return $this->successResponse(
            'Success',
            [ 
                'academics' => $getAcademics
            ]
        );
    }
}
