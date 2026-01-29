<?php

namespace App\Http\Controllers;

use App\Models\Semesters;
use App\Models\Students;
use App\Traits\HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StudentController extends Controller
{
    use HttpResponse;

    public function getStudentInfo() {
        $user = Auth::user();
        $currentSemester = Semesters::getCurrentSemester();
        $currentTermId = $currentSemester->SemesterID;
        $studentId = $user->StudentID ?? $user->UserID;

        $studentInfo = Students::query()
            ->from('tblBHSStudent as student') // ensure the alias matches below
            ->leftJoin('tblBHSHomestay as homestay', 'student.StudentID', '=', 'homestay.StudentID')
            ->leftJoin('tblBHSStudentSubject as b', 'student.StudentID', '=', 'b.StudNum')
            ->leftJoin('tblBHSSubject as c', 'b.SubjectID', '=', 'c.SubjectID')
            // keep rows even if there’s no matching semester; constrain the joined rows to <= currentTermId
            ->leftJoin('tblBHSSemester as d', function ($join) use ($currentTermId) {
                $join->on('c.SemesterID', '=', 'd.SemesterID')
                    ->where('d.SemesterID', '<=', $currentTermId);
            })
            ->selectRaw('COUNT(DISTINCT c.SemesterID) as numTerms')
            ->selectRaw("COUNT(DISTINCT CASE WHEN c.SubjectName LIKE 'AEP%' THEN c.SemesterID END) as numOfAepTerm")
            ->addSelect([
                'student.StudentID as studentId',
                'student.PEN as pen',
                'student.FirstName as firstName',
                'student.LastName as lastName',
                'student.EnglishName as englishName',
                'student.SchoolEmail as schoolEmail',
                'student.CurrentGrade as currentGrade',
                'student.Counselor as counsellor',
                'student.Mentor as mentor',
                'student.Houses as houses',
                'homestay.Homestay as homestay',
                'homestay.Residence as residence',
                'homestay.Halls as halls',
                'homestay.RoomNo as roomNo',
                'homestay.Hadvisor as youthAdvisor',
                'homestay.Hadvisor2 as youthAdvisor2',
                'homestay.Tutor as tutor',
                'student.EnrolmentDate as EnrollmentDate',
            ])
            ->where('student.StudentID', $studentId)
            ->where('student.CurrentStudent', 'Y')
            ->groupBy([
                'student.StudentID',
                'student.PEN',
                'student.FirstName',
                'student.LastName',
                'student.EnglishName',
                'student.SchoolEmail',
                'student.CurrentGrade',
                'student.Counselor',
                'student.Mentor',
                'student.Houses',
                'homestay.Homestay',
                'homestay.Residence',
                'homestay.Halls',
                'homestay.RoomNo',
                'homestay.Hadvisor',
                'homestay.Hadvisor2',
                'homestay.Tutor',
                'student.EnrolmentDate',
            ])
            ->first();

        return $this->successResponse(
            'Success',
            [ 'info' => $studentInfo ?? null ]
        );
    }


    public function getClassSchedule(Request $request) {
        $user = $request->user() ?? Auth::user();
        $studentId = $user->StudentID ?? $user->UserID ?? $request->input('studentId');

        if (!$studentId) {
            return $this->errorResponse('Missing studentId', [], null, 422);
        }

        $semesterId = $request->input('semesterId') ?? $request->input('SemesterID');
        if (!$semesterId) {
            $semesterId = Semesters::getCurrentSemester()?->SemesterID;
        }

        if (!$semesterId) {
            return $this->errorResponse('Missing semesterId', [], null, 422);
        }

        $semester = DB::selectOne(
            'select SemesterID as semesterId, SemesterName as semesterName, FExam1 as fexam1, FExam2 as fexam2, CurrentSemester as currentSemester from tblBHSSemester where SemesterID = ?',
            [$semesterId]
        );

        $student = DB::selectOne(
            'select StudentID as studentId, FirstName as firstName, LastName as lastName from tblBHSStudent where StudentID = ?',
            [$studentId]
        );

        $baseSql = 'select tblbhssubject.subjectname, tblbhssubject.block, tblbhssubject.sday, tblbhssubject.roomno, tblstaff.sex, tblstaff.lastname as lname '
            . 'from tblbhsstudentsubject, tblbhssubject, tblbhsstudent, tblstaff where '
            . 'tblbhsstudentsubject.subjectid = tblbhssubject.subjectid and tblbhsstudentsubject.studnum '
            . '= tblbhsstudent.studentid and tblbhssubject.teacherid = tblstaff.staffid '
            . 'and tblbhssubject.semesterid = ? and tblbhsstudent.studentid = ? and tblbhssubject.block = ?';

        $get1 = DB::select($baseSql, [$semesterId, $studentId, 1]);
        $get2 = DB::select($baseSql, [$semesterId, $studentId, 2]);
        $get3 = DB::select($baseSql, [$semesterId, $studentId, 3]);
        $get4 = DB::select($baseSql, [$semesterId, $studentId, 4]);
        $get5 = DB::select($baseSql, [$semesterId, $studentId, 5]);

        $get8 = DB::select(
            'select tblbhssubject.subjectname, tblbhssubject.block, tblbhssubject.sday, tblbhssubject.roomno, tblstaff.sex, tblstaff.lastname as lname '
            . 'from tblbhsstudentsubject, tblbhssubject, tblbhsstudent, tblstaff where '
            . 'tblbhsstudentsubject.subjectid = tblbhssubject.subjectid and tblbhsstudentsubject.studnum = tblbhsstudent.studentid and tblbhssubject.teacherid = tblstaff.staffid '
            . 'and tblbhssubject.semesterid = ? and tblbhsstudent.studentid = ? and tblbhssubject.block in (6, 9)',
            [$semesterId, $studentId]
        );

        $get9 = DB::select(
            'select tblbhssubject.subjectname, tblbhssubject.block, tblbhssubject.sday, tblbhssubject.roomno, tblstaff.sex, tblstaff.lastname as lname '
            . 'from tblbhsstudentsubject, tblbhssubject, tblbhsstudent, tblstaff where '
            . 'tblbhsstudentsubject.subjectid = tblbhssubject.subjectid and tblbhsstudentsubject.studnum = tblbhsstudent.studentid and tblbhssubject.teacherid = tblstaff.staffid '
            . 'and tblbhssubject.semesterid = ? and tblbhsstudent.studentid = ? and tblbhssubject.block in (8, 9)',
            [$semesterId, $studentId]
        );

        $g1Sql = 'select tblbhssubject.subjectname, tblbhssubject.block, tblbhssubject.sday, tblbhssubject.roomno, tblstaff.sex, tblstaff.lastname as lname '
            . 'from tblbhsstudentsubject, tblbhssubject, tblbhsstudent, tblstaff where '
            . 'tblbhsstudentsubject.subjectid = tblbhssubject.subjectid and tblbhsstudentsubject.studnum = tblbhsstudent.studentid and tblbhssubject.teacherid = tblstaff.staffid '
            . 'and tblbhssubject.semesterid = ? and tblbhsstudent.studentid = ? and tblbhssubject.block = 10 and tblbhssubject.sday like ?';

        $getg11 = DB::select($g1Sql, [$semesterId, $studentId, '%1%']);
        $getg12 = DB::select($g1Sql, [$semesterId, $studentId, '%2%']);
        $getg13 = DB::select($g1Sql, [$semesterId, $studentId, '%3%']);
        $getg14 = DB::select($g1Sql, [$semesterId, $studentId, '%4%']);
        $getg15 = DB::select($g1Sql, [$semesterId, $studentId, '%5%']);

        $g2Sql = 'select tblbhssubject.subjectname, tblbhssubject.block, tblbhssubject.sday, tblbhssubject.roomno, tblstaff.sex, tblstaff.lastname as lname '
            . 'from tblbhsstudentsubject, tblbhssubject, tblbhsstudent, tblstaff where '
            . 'tblbhsstudentsubject.subjectid = tblbhssubject.subjectid and tblbhsstudentsubject.studnum = tblbhsstudent.studentid and tblbhssubject.teacherid = tblstaff.staffid '
            . 'and tblbhssubject.semesterid = ? and tblbhsstudent.studentid = ? and tblbhssubject.block = 11 and tblbhssubject.sday like ?';

        $getg21 = DB::select($g2Sql, [$semesterId, $studentId, '%1%']);
        $getg22 = DB::select($g2Sql, [$semesterId, $studentId, '%2%']);
        $getg23 = DB::select($g2Sql, [$semesterId, $studentId, '%3%']);
        $getg24 = DB::select($g2Sql, [$semesterId, $studentId, '%4%']);
        $getg25 = DB::select($g2Sql, [$semesterId, $studentId, '%5%']);

        $normalize = function(array $rows, ?string $day = null) {
            return array_values(array_map(function($row) use ($day) {
                $subjectName = trim(str_replace('ZZZ ', '', (string)($row->subjectname ?? '')));
                $sex = (string)($row->sex ?? '');
                $lname = (string)($row->lname ?? '');
                $prefix = $sex === 'M' ? 'Mr.' : ($sex === 'F' ? 'Ms.' : '');
                $teacherName = trim(($prefix ? $prefix . ' ' : '') . $lname);

                return [
                    'subjectName' => $subjectName,
                    'block' => isset($row->block) ? (int)$row->block : null,
                    'sday' => $row->sday ?? null,
                    'day' => $day,
                    'roomNo' => $row->roomno ?? '',
                    'teacherSex' => $sex,
                    'teacherLastName' => $lname,
                    'teacherName' => $teacherName,
                ];
            }, $rows));
        };

        $blocks = [
            'block1' => $normalize($get1),
            'block2' => $normalize($get2),
            'block3' => $normalize($get3),
            'block4' => $normalize($get4),
            'block5' => $normalize($get5),
            's1' => $normalize($get8, 'sat'),
            's2' => $normalize($get9, 'sat'),
            'g1' => [
                '1' => $normalize($getg11, '1'),
                '2' => $normalize($getg12, '2'),
                '3' => $normalize($getg13, '3'),
                '4' => $normalize($getg14, '4'),
                '5' => $normalize($getg15, '5'),
            ],
            'g2' => [
                '1' => $normalize($getg21, '1'),
                '2' => $normalize($getg22, '2'),
                '3' => $normalize($getg23, '3'),
                '4' => $normalize($getg24, '4'),
                '5' => $normalize($getg25, '5'),
            ],
        ];

        return $this->successResponse('Success', [
            'semester' => $semester,
            'student' => $student,
            'blocks' => $blocks,
        ]);
    }

}