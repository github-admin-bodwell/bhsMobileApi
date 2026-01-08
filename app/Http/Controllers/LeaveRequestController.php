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
                CONVERT(varchar, L.OutDate, 100) AS OutDate,
                CONVERT(varchar, L.InDate, 100) AS InDate,
                CONVERT(varchar, L.OutDate, 120) AS OutDateISO,
                CONVERT(varchar, L.InDate, 120) AS InDateISO,
                DATEDIFF(minute, L.OutDate, L.InDate) AS OutDurationMinutes,
                CONVERT(varchar, L.SDate, 100) AS SDate,
                CONVERT(varchar, L.EDate, 100) AS EDate,
                CONCAT(S.FirstName, ' ', S.LastName) AS StaffFullName
            FROM tblBHSStudentLeaveRequest L
            LEFT JOIN tblStaff S ON L.ApprovalStaff = S.StaffID
            WHERE L.StudentID = :studentId
            ORDER BY L.CreateDate DESC";

        $params = ['studentId' => $studentId];
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

        if ($endAt->lt($startAt)) {
            return $this->errorResponse('endDate must be on or after startDate', [], null, 422);
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
            return $this->errorResponse('Leave request conflicts with an existing request', [
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

    private function getLeaveRequestConflicts(string $studentId, string $startDateTime, string $endDateTime): array
    {
        return LeaveRequest::query()
            ->where('StudentID', $studentId)
            ->where('LeaveStatus', '<>', 'R')
            ->where(function ($query) use ($startDateTime, $endDateTime) {
                $query->whereBetween('SDate', [$startDateTime, $endDateTime])
                    ->orWhereBetween('EDate', [$startDateTime, $endDateTime])
                    ->orWhere(function ($query) use ($startDateTime, $endDateTime) {
                        $query->where('SDate', '<=', $startDateTime)
                            ->where('EDate', '>=', $endDateTime);
                    });
            })
            ->get(['LeaveID', 'SDate', 'EDate', 'LeaveStatus'])
            ->toArray();
    }
}
