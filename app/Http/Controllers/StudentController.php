<?php

namespace App\Http\Controllers;

use App\Models\Semesters;
use App\Models\Students;
use App\Traits\HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
}
