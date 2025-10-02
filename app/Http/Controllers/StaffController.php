<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Semesters;
use App\Traits\HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class StaffController extends Controller
{
    use HttpResponse;

    public function getStaffInvolved(Request $request)
    {
        $user = $request->user();
        $currentSemester = Semesters::getCurrentSemester();

        // Student & semester (allow override via query/body if you want)
        $studentId  = $user->StudentID ?? $user->UserID ?? $request->input('studentId');
        $SemesterID = $request->input('SemesterID', $currentSemester->SemesterID ?? null);

        try {
            Log::debug('getStaffInvolved request', [
                'studentId' => $studentId,
                'SemesterID' => $SemesterID,
            ]);

            // --- Involve Staff (counselor / advisors)
            $involve = DB::selectOne("
                SELECT
                    s.Counselor,
                    CASE
                        WHEN s.Counselor <> ''
                            THEN (SELECT TOP 1 StaffID FROM tblStaff WHERE CONCAT(FirstName, ' ', LastName) = s.Counselor)
                        ELSE ''
                    END AS CounselorID,
                    h.Hadvisor,
                    CASE
                        WHEN h.Hadvisor <> ''
                            THEN (SELECT TOP 1 StaffID FROM tblStaff WHERE CONCAT(FirstName, ' ', LastName) = h.Hadvisor)
                        ELSE ''
                    END AS HadvisorID,
                    h.Hadvisor2,
                    CASE
                        WHEN h.Hadvisor2 <> ''
                            THEN (SELECT TOP 1 StaffID FROM tblStaff WHERE CONCAT(FirstName, ' ', LastName) = h.Hadvisor2)
                        ELSE ''
                    END AS HadvisorID2
                FROM tblBHSStudent s
                LEFT JOIN tblBHSHomestay h ON h.StudentID = s.StudentID
                WHERE s.StudentID = :studentId
            ", ['studentId' => $studentId]);

            // Default payload structure
            $staff = [
                'Teachers'           => [],
                'noncreditTeachers'  => [],
                'Counselor'          => ['staffId' => '', 'fullName' => '', 'positionTitle' => 'Counselor'],
                'Hadvisor'           => ['staffId' => '', 'fullName' => '', 'positionTitle' => 'Youth Advisor'],
                'Hadvisor2'          => ['staffId' => '', 'fullName' => '', 'positionTitle' => 'Youth Advisor2'],
                'Principal1'         => ['staffId' => 'F0627', 'fullName' => 'Stephen Goobie', 'positionTitle' => 'Head of School'],
                'Principal2'         => ['staffId' => 'F0123', 'fullName' => 'Housam Hallis', 'positionTitle' => 'Principal'],
                'today'              => now()->format('Y-m-d'),
            ];

            if ($involve) {
                $staff['Counselor'] = [
                    'staffId'        => $involve->CounselorID ?? '',
                    'fullName'       => $involve->Counselor ?? '',
                    'positionTitle'  => 'Counselor',
                ];
                $staff['Hadvisor'] = [
                    'staffId'        => $involve->HadvisorID ?? '',
                    'fullName'       => $involve->Hadvisor ?? '',
                    'positionTitle'  => 'Youth Advisor',
                ];
                $staff['Hadvisor2'] = [
                    'staffId'        => $involve->HadvisorID2 ?? '',
                    'fullName'       => $involve->Hadvisor2 ?? '',
                    'positionTitle'  => 'Youth Advisor2',
                ];
            }

            // --- Teacher involved list
            if ($SemesterID) {
                $teachers = DB::select("
                    SELECT
                        course.SubjectName AS courseName,
                        staff.StaffID      AS teacherId,
                        CONCAT(staff.FirstName, ' ', staff.LastName) AS teacherName,
                        staff.FirstName    AS teacherFirstName,
                        staff.LastName     AS teacherLastName,
                        course.Credit      AS credit,
                        course.Type        AS courseType
                    FROM tblBHSStudentSubject AS studentCourse
                    JOIN tblBHSSubject       AS course ON studentCourse.SubjectID = course.SubjectID
                    JOIN tblStaff            AS staff  ON course.TeacherID = staff.StaffID
                    WHERE course.SemesterID = :SemesterID
                    AND studentCourse.StudNum = :studentId
                    AND course.SubjectName NOT LIKE 'YYY%'
                    ORDER BY course.Credit DESC, course.SubjectName ASC
                ", ['SemesterID' => $SemesterID, 'studentId' => $studentId]);

                foreach ($teachers as $t) {
                    $entry = [
                        'courseName'        => $t->courseName,
                        'teacherId'         => $t->teacherId,
                        'teacherName'       => $t->teacherName,
                        'teacherFirstName'  => $t->teacherFirstName,
                        'teacherLastName'   => $t->teacherLastName,
                        'credit'            => (int)$t->credit,
                        'courseType'        => $t->courseType,
                    ];
                    if ((int)$t->credit === 1) {
                        $staff['Teachers'][] = $entry;
                    } else {
                        $staff['noncreditTeachers'][] = $entry;
                    }
                }
            }

            // Match your legacy behavior: ensure noncreditTeachers has at least one empty string
            if (count($staff['noncreditTeachers']) === 0) {
                $staff['noncreditTeachers'][] = '';
            }

            return $this->successResponse('Success', ['stafflist' => $staff]);

        } catch (\Throwable $e) {
            Log::error('getStaffInvolved error', ['err' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            // If you don’t have errorResponse(), just return a normal JSON error.
            return $this->errorResponse('Server Error', 500);
            // or: return response()->json(['message' => 'Server Error'], 500);
        }
    }

    
}
