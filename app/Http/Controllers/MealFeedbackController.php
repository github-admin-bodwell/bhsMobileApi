<?php

namespace App\Http\Controllers;

use App\Traits\HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MealFeedbackController extends Controller
{
    use HttpResponse;

    public function getMeal(Request $request)
    {
        $dateInput = $request->query('date') ?? $request->input('date');
        $date = $this->normalizeDate($dateInput);
        if (!$date) {
            return $this->errorResponse('Invalid date', [], null, 422);
        }

        $rows = DB::select(
            "SELECT * FROM tblBHSMeal WHERE Date = :Date",
            ['Date' => $date]
        );

        return $this->successResponse('Success', $rows);
    }

    public function getFeedback(Request $request)
    {
        $user = $request->user();
        $studentId = $user->StudentID ?? $user->UserID ?? $request->input('studentId');
        if (!$studentId) {
            return $this->errorResponse('Missing studentId', [], null, 422);
        }

        $dateInput = $request->query('date') ?? $request->input('date');
        $date = $this->normalizeDate($dateInput);
        if (!$date) {
            return $this->errorResponse('Invalid date', [], null, 422);
        }

        $row = DB::selectOne(
            "SELECT TOP 1 * FROM tblBHSStudentMealFeedback WHERE CONVERT(char(10), CreatedDate, 126) = :CreatedDate AND StudentID = :StudentID ORDER BY CreatedDate DESC",
            ['CreatedDate' => $date, 'StudentID' => $studentId]
        );

        if (!$row) {
            return $this->successResponse('No feedback', ['result' => 0]);
        }

        return $this->successResponse('Success', ['result' => 1, 'feedback' => $row]);
    }

    public function addFeedback(Request $request)
    {
        $user = $request->user();
        $studentId = $user->StudentID ?? $user->UserID ?? $request->input('studentId');
        if (!$studentId) {
            return $this->errorResponse('Missing studentId', [], null, 422);
        }

        $data = $request->validate([
            'lunchchoices' => ['nullable'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'reason' => ['nullable', 'string'],
            'generalcomment' => ['nullable', 'string'],
            'taste' => ['nullable', 'string'],
            'ingredients' => ['nullable', 'string'],
            'portion' => ['nullable', 'string'],
            'texture' => ['nullable', 'string'],
            'presentation' => ['nullable', 'string'],
            'suggestion' => ['nullable', 'string'],
        ]);

        $date = $this->normalizeDate($request->input('date')) ?? Carbon::now()->toDateString();

        $existing = DB::selectOne(
            "SELECT TOP 1 StudentID FROM tblBHSStudentMealFeedback WHERE CONVERT(char(10), CreatedDate, 126) = :CreatedDate AND StudentID = :StudentID",
            ['CreatedDate' => $date, 'StudentID' => $studentId]
        );

        if ($existing) {
            return $this->successResponse('Feedback already submitted', ['result' => 0]);
        }

        $inserted = DB::insert(
            "INSERT INTO tblBHSStudentMealFeedback (
                MealID, StudentID, Rate, Reason, Comments, Taste, Ingredients, Portion,
                Texture, Presentation, Suggestion
            ) VALUES (
                :MealID, :StudentID, :Rate, :Reason, :Comments, :Taste, :Ingredients, :Portion,
                :Texture, :Presentation, :Suggestion
            )",
            [
                'MealID' => empty($data['lunchchoices']) ? '' : $data['lunchchoices'],
                'StudentID' => $studentId,
                'Rate' => $data['rating'],
                'Reason' => $data['reason'] ?? '',
                'Comments' => $data['generalcomment'] ?? '',
                'Taste' => $data['taste'] ?? '',
                'Ingredients' => $data['ingredients'] ?? '',
                'Portion' => $data['portion'] ?? '',
                'Texture' => $data['texture'] ?? '',
                'Presentation' => $data['presentation'] ?? '',
                'Suggestion' => $data['suggestion'] ?? '',
            ]
        );

        if (!$inserted) {
            return $this->errorResponse('Unable to submit feedback', ['result' => 0], null, 500);
        }

        return $this->successResponse('Feedback submitted', ['result' => 1]);
    }

    private function normalizeDate($value): ?string
    {
        if (!$value) {
            return Carbon::now()->toDateString();
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
