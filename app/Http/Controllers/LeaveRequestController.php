<?php

namespace App\Http\Controllers;

use App\Models\Parents;
use App\Models\LeaveRequest;
use App\Models\StudentLeaveBan;
use App\Traits\HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class LeaveRequestController extends Controller
{
    use HttpResponse;

    public function index(Request $request)
    {
        $user = $request->user();

        $studentId = $user->StudentID ?? $user->UserID ?? $request->input('studentId');
        if (!$studentId) {
            return $this->errorResponse('Missing studentId', [], null, 422);
        }

        $dateFilter = $request->query('date');
        if ($dateFilter) {
            try {
                $dateFilter = Carbon::parse($dateFilter)->toDateString();
            } catch (\Exception $e) {
                return $this->errorResponse('Invalid date filter', [], null, 422);
            }
        }

        $limit = (int) $request->query('limit', 0);
        $offset = (int) $request->query('offset', 0);
        $limit = $limit > 0 ? min($limit, 50) : 0;
        $offset = max($offset, 0);

        $sql = "
            SELECT
                L.LeaveID,
                L.LeaveType,
                L.LeaveStatus,
                L.Reason,
                L.ToDo,
                L.Comment,
                L.ApprovalStaff,
                CONVERT(varchar, L.SDate, 120) AS StartDate,
                CONVERT(varchar, L.EDate, 120) AS EndDate,
                CONVERT(varchar, NULLIF(L.OutDate, '1900-01-01'), 100) AS OutDate,
                CONVERT(varchar, NULLIF(L.InDate, '1900-01-01'), 100) AS InDate,
                CONVERT(varchar, NULLIF(L.OutDate, '1900-01-01'), 120) AS OutDateISO,
                CONVERT(varchar, NULLIF(L.InDate, '1900-01-01'), 120) AS InDateISO,
                DATEDIFF(minute, NULLIF(L.OutDate, '1900-01-01'), NULLIF(L.InDate, '1900-01-01')) AS OutDurationMinutes,
                CONVERT(varchar, L.SDate, 100) AS SDate,
                CONVERT(varchar, L.EDate, 100) AS EDate,
                CONCAT(S.FirstName, ' ', S.LastName) AS StaffFullName
            FROM tblBHSStudentLeaveRequest L
            LEFT JOIN tblStaff S ON L.ApprovalStaff = S.StaffID
            WHERE L.StudentID = :studentId
            ORDER BY L.CreateDate DESC";

        $params = ['studentId' => $studentId];

        if ($dateFilter) {
            $sql = str_replace(
                'WHERE L.StudentID = :studentId',
                'WHERE L.StudentID = :studentId AND CONVERT(date, L.OutDate) = :dateFilter',
                $sql
            );
            $params['dateFilter'] = $dateFilter;
        }
        $usePaging = $limit > 0;
        $limitPlusOne = $limit + 1;

        if ($usePaging) {
            $sql .= " OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY";
            $params['offset'] = $offset;
            $params['limit'] = $limitPlusOne;
        }

        $rows = DB::select($sql, $params);

        $hasMore = false;
        $nextOffset = null;
        if ($usePaging) {
            $hasMore = count($rows) > $limit;
            if ($hasMore) {
                $rows = array_slice($rows, 0, $limit);
                $nextOffset = $offset + $limit;
            }
        }

        $payload = [
            'leaveRequests' => $rows,
        ];

        if ($usePaging) {
            $payload['meta'] = [
                'offset' => $offset,
                'limit' => $limit,
                'hasMore' => $hasMore,
                'nextOffset' => $nextOffset,
            ];
        }

        return $this->successResponse('Success', $payload);
    }

    public function currentBan(Request $request)
    {
        $user = $request->user();

        $studentId = $user->StudentID ?? $user->UserID ?? $request->input('studentId');
        if (!$studentId) {
            return $this->errorResponse('Missing studentId', [], null, 422);
        }

        $today = Carbon::now()->toDateString();

        $sql = "
            SELECT TOP 1
                Comment,
                CONVERT(varchar, FromDate, 100) AS FromDate,
                CONVERT(varchar, ToDate, 100) AS ToDate
            FROM tblBHSStudentLeaveBan
            WHERE StudentID = :studentId
              AND Status = 'A'
              AND :today BETWEEN FromDate AND ToDate
            ORDER BY FromDate DESC";

        $rows = DB::select($sql, ['studentId' => $studentId, 'today' => $today]);
        $ban = $rows[0] ?? null;

        return $this->successResponse('Success', [
            'hasBan' => (bool) $ban,
            'ban' => $ban,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $studentId = $user->StudentID ?? $user->UserID ?? $request->input('studentId');
        if (!$studentId) {
            return $this->errorResponse('Missing studentId', [], null, 422);
        }

        $data = $request->validate([
            'leavetype' => ['required', 'string'],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date'],
            'hours' => ['nullable', 'numeric'],
            'leaveReason' => ['nullable', 'string'],
            'comment' => ['nullable', 'string'],
            'doing' => ['nullable', 'string'],
        ]);

        $startAt = Carbon::parse($data['startDate']);
        $endAt = Carbon::parse($data['endDate']);

        if ($endAt->lte($startAt)) {
            return $this->errorResponse('endDate must be after startDate', [
                'code' => 'leave_end_before_start',
            ], null, 422);
        }

        $maxHours = max(1, (int) config('features.leave_max_hours', 12));
        if (!$endAt->isSameDay($startAt)) {
            return $this->errorResponse('A leave request must start and end on the same day', [
                'code' => 'leave_not_same_day',
                'maxHours' => $maxHours,
            ], null, 422);
        }
        if ($endAt->gt($startAt->copy()->addHours($maxHours))) {
            return $this->errorResponse("A leave request can last at most {$maxHours} hours", [
                'code' => 'leave_duration_exceeded',
                'maxHours' => $maxHours,
            ], null, 422);
        }

        $startDateTime = $startAt->toDateTimeString();
        $endDateTime = $endAt->toDateTimeString();

        $leaveBan = $this->getStudentLeaveBan($studentId, $startAt->toDateString(), $endAt->toDateString());
        if (!empty($leaveBan)) {
            return $this->successResponse('Leave request blocked', [
                'result' => 2,
                'leaveBans' => $leaveBan,
            ]);
        }

        $conflicts = $this->getLeaveRequestConflicts($studentId, $startDateTime, $endDateTime);
        if (!empty($conflicts)) {
            // 4xx responses are not logged by the framework; without this line a
            // student who is stuck behind an old request is invisible in the log.
            Log::warning('Leave request rejected: overlaps a pending or in-progress request', [
                'studentId' => $studentId,
                'startDate' => $startDateTime,
                'endDate' => $endDateTime,
                'conflicts' => array_column($conflicts, 'LeaveID'),
            ]);

            return $this->errorResponse('Leave request overlaps a pass that is not finished yet', [
                'code' => 'leave_conflict',
                'conflicts' => $conflicts,
            ], null, 409);
        }

        $leaveRequest = LeaveRequest::create([
            'LeaveType' => $data['leavetype'],
            'StudentID' => $studentId,
            'SDate' => $startDateTime,
            'EDate' => $endDateTime,
            'Reason' => $data['leaveReason'] ?? '',
            'Comment' => $data['comment'] ?? '',
            'ToDo' => $data['doing'] ?? '',
            'LeaveTime' => $data['hours'] ?? '',
            'LeaveStatus' => 'P',
            'ModifyUserID' => $studentId,
            'CreateUserID' => $studentId,
            'Source' => 'mobile'
        ]);

        return $this->successResponse('Leave request submitted', [
            'result' => 1,
            'leaveRequestId' => $leaveRequest->LeaveID ?? null,
        ], 201);
    }

    private function getStudentLeaveBan(string $studentId, string $startDate, string $endDate): array
    {
        return StudentLeaveBan::query()
            ->where('StudentID', $studentId)
            ->where('Status', 'A')
            ->where(function ($query) use ($startDate, $endDate) {
                $query->whereRaw('? BETWEEN FromDate AND ToDate', [$startDate])
                    ->orWhereRaw('? BETWEEN FromDate AND ToDate', [$endDate])
                    ->orWhere(function ($query) use ($startDate, $endDate) {
                        $query->where('FromDate', '<=', $startDate)
                            ->where('ToDate', '>=', $endDate);
                    });
            })
            ->get()
            ->toArray();
    }

    /**
     * Statuses that still block a new request for an overlapping window.
     *
     * A leave record moves P (submitted) -> I (signed out at atrium, off campus)
     * -> A (signed back in). A is a finished pass: a student who came back
     * early and wants to go out again must not be refused because the earlier
     * pass was booked until later. R is rejected. Only P and I are live.
     */
    public const BLOCKING_LEAVE_STATUSES = ['P', 'I'];

    private function getLeaveRequestConflicts(string $studentId, string $startDateTime, string $endDateTime): array
    {
        return $this->conflictQuery($studentId, $startDateTime, $endDateTime)
            ->get(['LeaveID', 'SDate', 'EDate', 'LeaveStatus'])
            ->toArray();
    }

    public function conflictQuery(string $studentId, string $startDateTime, string $endDateTime)
    {
        return LeaveRequest::query()
            ->where('StudentID', $studentId)
            ->whereIn('LeaveStatus', self::BLOCKING_LEAVE_STATUSES)
            ->where(function ($query) use ($startDateTime, $endDateTime) {
                $query->whereBetween('SDate', [$startDateTime, $endDateTime])
                    ->orWhereBetween('EDate', [$startDateTime, $endDateTime])
                    ->orWhere(function ($query) use ($startDateTime, $endDateTime) {
                        $query->where('SDate', '<=', $startDateTime)
                            ->where('EDate', '>=', $endDateTime);
                    });
            });
    }
}
