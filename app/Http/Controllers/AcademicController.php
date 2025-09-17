<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Semesters;
use App\Traits\HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AcademicController extends Controller
{
    use HttpResponse;

    public function getAcademics(Request $request) {
        $user = $request->user();
        $currentSemester = Semesters::getCurrentSemester();
        $studentId = $user->StudentID ?? $user->UserID;

        Log::debug('request data', ['request' => $request]);

        $categoryRatePerCat = DB::table('tblBHSOGSGrades as grade')
            ->join('tblBHSOGSCategoryItems as item', 'grade.CategoryItemID', '=', 'item.CategoryItemID')
            ->join('tblBHSOGSCourseCategory as category', 'item.CategoryID', '=', 'category.CategoryID')
            ->join('tblBHSSubject as course', 'category.SubjectID', '=', 'course.SubjectID')
            ->join('tblBHSStudentSubject as studentSubject', 'grade.StudSubjID', '=', 'studentSubject.StudSubjID')
            ->join('tblBHSStudent as student', 'studentSubject.StudNum', '=', 'student.StudentID')
            ->where('student.StudentID', $studentId)
            ->whereNotNull('grade.ScorePoint')
            ->where('grade.Exempted', '<>', 1)
            ->groupBy([
                'student.StudentID',
                'grade.SemesterID',
                'course.SubjectID',
                'category.CategoryID',
                'category.CategoryWeight',
                'studentSubject.LtrGradeFinal',
                'course.Credit',
            ])
            ->selectRaw("
                student.StudentID as studentId,
                grade.SemesterID   as semesterId,
                course.SubjectID   as courseId,
                category.CategoryID as categoryId,
                category.CategoryWeight as categoryWeight,
                studentSubject.LtrGradeFinal as LtrGradeFinal,
                course.Credit as credit,
                SUM((grade.ScorePoint / item.MaxValue) * item.ItemWeight) * (1 / SUM(item.ItemWeight)) as categoryRateScaled
            ");

        $courseRates = DB::query()
            ->fromSub($categoryRatePerCat, 'categoryGrade')
            ->selectRaw("
                studentId,
                semesterId,
                courseId,
                LtrGradeFinal,
                credit,
                COUNT(categoryId)                                           as categoryCount,
                SUM(categoryWeight)                                         as categoryWeightTotal,
                SUM(categoryRateScaled * categoryWeight)                    as courseRateOrigin,
                SUM(categoryRateScaled * categoryWeight) * (1 / SUM(categoryWeight)) as courseRateScaled
            ")
            ->groupBy([
                'studentId',
                'semesterId',
                'courseId',
                'LtrGradeFinal',
                'credit',
            ]);

        // course-list
        $getAcademics = DB::query()
                        ->from('tblBHSStudentSubject AS studentCourse')
                        ->join('tblBHSSubject AS course', 'studentCourse.SubjectID', 'course.SubjectID')
                        ->join('tblStaff AS staff', 'course.TeacherID', 'staff.StaffID')
                        ->join('tblBHSSemester AS semester', 'course.SemesterID', 'semester.SemesterID')
                        ->leftJoinSub($courseRates, 'cr', function ($join) use ($studentId) {
                            $join->on('cr.courseId', '=', 'studentCourse.SubjectID')
                                ->on('cr.semesterId', '=', 'course.SemesterID')
                                ->where('cr.studentId', '=', $studentId);
                        })
                        ->where('course.SemesterID', '<=', $currentSemester->SemesterID)
                        ->where('studentCourse.StudNum', $studentId)
                        ->whereNotLike('course.SubjectName', 'YYY%')
                        ->orderByDesc('course.Credit')
                        ->get([
                            'course.SemesterID AS semesterId',
                            'course.Type AS courseType',
                            'semester.SemesterName AS semesterName',
                            'studentCourse.StudSubjID AS studentCourseId',
                            'studentCourse.StudNum AS studentId',
                            'studentCourse.SubjectID AS courseId',
                            'course.SubjectName AS courseName',
                            'staff.StaffID AS teacherId',
                            'staff.Photo AS staffPhoto',
                            'staff.FirstName AS teacherFirstName',
                            'staff.LastName AS teacherLastName',

                            DB::raw('COALESCE(cr.categoryCount, 0)        AS categoryCount'),
                            DB::raw('COALESCE(cr.categoryWeightTotal, 0)  AS categoryWeightTotal'),
                            DB::raw('COALESCE(cr.courseRateOrigin, 0)     AS courseRateOrigin'),
                            DB::raw('COALESCE(cr.courseRateScaled, 0)     AS courseRateScaled'),
                            DB::raw('cr.LtrGradeFinal                      AS ltrGradeFinal'),
                            DB::raw('COALESCE(cr.credit, course.Credit)    AS creditComputed'),
                        ])
                        ->groupBy('semesterId')
                        ->sortByDesc(function ($items, $semesterId) { 
                            return $semesterId;
                        })
                        ->map(function($items, $semsterId) {
                            return [
                                'title' => $items->first()->semesterName,
                                'courses' => $items->values(),
                            ];
                        })
                        ->values();

        $studentCourseList = $getAcademics
            ->flatMap(fn ($g) => $g['courses'])
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
            $group['courses'] = $group['courses']->map(function ($row) use ($attendance) {
                $totals = $attendance->get($row->studentCourseId);
                $row->absenceCount = (int)($totals->absenceCount ?? 0);
                $row->lateCount    = (int)($totals->lateCount ?? 0);

                // (Optional) normalize/round the computed rates for the client
                if (isset($row->courseRateScaled)) {
                    $rate = round((float)$row->courseRateScaled, 4) * 100;
                    $row->courseRateScaled =  number_format($rate, 0, '.');
                }
                if (isset($row->courseRateOrigin)) {
                    $row->courseRateOrigin = round((float)$row->courseRateOrigin, 4);
                }

                if (str_starts_with($row->courseName, 'ZZZ')) {
                    $row->courseName = preg_replace('/^ZZZ\s*/', '', $row->courseName);
                }

                return $row;
            });

            [$zeroCredits, $withCredits] = $group['courses']->partition(function ($row) {
                return $row->courseType !== "P" && $row->courseType !== 'N';
            });

            $group['courses'] = [
                'withCredits'  => $withCredits->values(),
                'zeroCredits'  => $zeroCredits->values(),
            ];

            return $group;
        });

        return $this->successResponse(
            'Success',
            [
                'academics' => $groups
            ]
        );
    }

    public function getDetails(Request $request) {        
        $user = $request->user();


        $validate = Validator::make($request->all(), [
            'courseId' => "required",
            'semesterId' => "required"
        ]);
        
        if( $validate->fails() ) {
            return $this->errorResponse('Validation Error', $validate->errors());
        }

        $studentId = $user->StudentID ?? $user->UserID;
        $semesterId = $request->semesterId;
        $courseId = $request->courseId;

        $rows = DB::table('tblBHSStudentSubject as studentCourse')
            ->join('tblBHSSubject as course', 'studentCourse.SubjectID', '=', 'course.SubjectID')
            ->join('tblBHSOGSCategoryItems as item', function ($j) {
                $j->on('item.SemesterID', '=', 'course.SemesterID')
                ->on('item.SubjectID',  '=', 'course.SubjectID');
            })
            ->join('tblStaff as staff', 'course.TeacherID', '=', 'staff.StaffID')
            ->leftJoin('tblBHSSemester as semester', 'semester.SemesterID', '=', 'course.SemesterID')
            ->leftJoin('tblBHSOGSCourseCategory as category', function ($j) {
                $j->on('category.SemesterID', '=', 'course.SemesterID')
                ->on('category.CategoryID', '=', 'item.CategoryID')
                ->on('category.SubjectID',  '=', 'item.SubjectID');
            })
            ->leftJoin('tblBHSOGSGrades as grade', function ($j) {
                $j->on('studentCourse.StudSubjID',  '=', 'grade.StudSubjID')
                ->on('item.CategoryItemID',       '=', 'grade.CategoryItemID');
            })
            ->where('studentCourse.StudNum', $studentId)
            ->where('course.SemesterID', $semesterId)
            ->where('studentCourse.SubjectID', $courseId)
            ->where('item.AssignDate', '>', '1900-01-01')
            ->whereNotNull('grade.ScorePoint')
            ->where('grade.Exempted', '<>', 1)
            ->select([
                DB::raw('course.SemesterID as termId'),
                DB::raw('semester.SemesterName'),
                DB::raw('studentCourse.StudSubjID as studentCourseId'),
                DB::raw('course.SubjectName as SubjectName'),
                DB::raw('studentCourse.StudNum as studentId'),
                DB::raw('studentCourse.SubjectID as courseId'),
                DB::raw('item.CategoryID as categoryId'),
                DB::raw('item.CategoryItemID as itemId'),
                DB::raw('grade.GradeID as gradeId'),
                DB::raw('item.Title as itemTitle'),
                DB::raw('item.ItemWeight as itemWeight'),
                DB::raw('grade.ScorePoint as scorePoint'),
                DB::raw('(grade.ScorePoint / item.MaxValue) as scoreRate'),
                DB::raw('item.ScoreType as scoreType'),
                DB::raw('grade.Comment as comment'),
                DB::raw('grade.Exempted as exempted'),
                DB::raw('item.MaxValue as maxScore'),
                DB::raw('course.RoomNo as roomNo'),
                DB::raw("CONCAT(staff.FirstName, ' ', staff.LastName) as teacherName"),
                DB::raw("CONVERT(CHAR(10), item.AssignDate, 126) as assignDate"),
                DB::raw("CONVERT(CHAR(10), item.DueDate, 126) as dueDate"),
                DB::raw("CASE WHEN item.AssignDate <= GETDATE()
                            AND ABS(DATEDIFF(DAY, item.AssignDate, GETDATE())) > 3
                            AND grade.ScorePoint IS NULL THEN 1 ELSE 0 END as overdue"),
                DB::raw("CASE
                            WHEN grade.Exempted = 1 THEN 'exempted'
                            WHEN grade.ScorePoint IS NOT NULL THEN 'normal'
                            WHEN item.AssignDate <= GETDATE()
                                AND ABS(DATEDIFF(DAY, item.AssignDate, GETDATE())) > 3 THEN 'overdue'
                            ELSE 'pending'
                        END as gradeStatus"),
                DB::raw('category.CategoryCode as categoryCode'),
                DB::raw('DENSE_RANK() OVER (ORDER BY category.Text) as row_number'),
                DB::raw('category.Text as categoryTitle'),
                DB::raw("CONCAT(category.Text, ' (', ROUND(category.CategoryWeight * 100, 2), '%)') as categoryLabel"),
                DB::raw('category.CategoryWeight as categoryWeight'),
                DB::raw("CONCAT(ROUND(category.CategoryWeight * 100, 2), '%') as categoryWeightLabel"),
            ])
            ->orderByDesc('item.AssignDate')
            ->get();
        
        //  SELECT Text, (CategoryWeight*100) weight, CategoryID FROM tblBHSOGSCourseCategory WHERE SubjectID=:SubjectID
        $weight = DB::table('tblBHSOGSCourseCategory')
                    ->where('SubjectID', $courseId)
                    ->get([
                        'Text',
                        DB::raw('(CategoryWeight*100) AS weight'),
                        'CategoryID'
                    ]);

        return $this->successResponse(
            'Success',
            [ 'details' => $rows, 'weight' => $weight ]
        );
    } 
}
