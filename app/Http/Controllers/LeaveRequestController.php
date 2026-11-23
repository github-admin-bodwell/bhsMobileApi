<?php

namespace App\Http\Controllers;

use App\Models\Parents;
use App\Models\LeaveRequest;
use App\Services\LeaveRequestEditor;
use App\Services\LeaveRuleResolver;
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
                CASE WHEN L.LeaveStatus = 'P' AND (L.OutDate IS NULL OR L.OutDate < '1901-01-01') THEN 1 ELSE 0 END AS CanEdit,
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

    /**
     * GET /get-leave-ban — the ban in force for the student at this moment,
     * for the card and the disabled Create button on the leave list screen.
     *
     * "This moment" is campus wall-clock time. The app runs on UTC, and
     * Vancouver is 7 or 8 hours behind it, so a UTC date turned every ban on
     * at 4 or 5pm the day before and off at 4 or 5pm on the day itself
     * (reported 2026-09-28: "a message on Thursday for a Friday ban").
     */
    public function currentBan(Request $request)
    {
        $user = $request->user();

        $studentId = $user->StudentID ?? $user->UserID ?? $request->input('studentId');
        if (!$studentId) {
            return $this->errorResponse('Missing studentId', [], null, 422);
        }

        $ban = $this->activeBanQuery($studentId)->first();

        return $this->successResponse('Success', [
            'hasBan' => (bool) $ban,
            'ban' => $ban,
        ]);
    }

    /** The current date and time on campus, as SQL Server expects it. */
    public static function campusNow(): string
    {
        return Carbon::now(config('features.campus_timezone'))->toDateTimeString();
    }

    public function activeBanQuery(string $studentId, ?string $now = null)
    {
        $now = $now ?? self::campusNow();

        return StudentLeaveBan::query()
            ->selectRaw('Comment, CONVERT(varchar, FromDate, 100) AS FromDate, CONVERT(varchar, ToDate, 100) AS ToDate')
            ->where('StudentID', $studentId)
            ->where('Status', 'A')
            ->whereRaw('? BETWEEN FromDate AND ToDate', [$now])
            ->orderByDesc('FromDate');
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

        if ($rejection = $this->windowRejection($studentId, $startAt, $endAt)) {
            return $rejection;
        }

        $startDateTime = $startAt->toDateTimeString();
        $endDateTime = $endAt->toDateTimeString();

        $leaveBan = $this->getStudentLeaveBan($studentId, $startDateTime, $endDateTime);
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

    /**
     * The same-day / hour-limit checks shared by create and update. Null when
     * the window is acceptable, otherwise the 422 to send back.
     */
    private function windowRejection(string $studentId, Carbon $startAt, Carbon $endAt)
    {
        if ($endAt->lte($startAt)) {
            return $this->errorResponse('endDate must be after startDate', [
                'code' => 'leave_end_before_start',
            ], null, 422);
        }

        $rule = $this->leaveRule($studentId);
        $maxHours = $rule['maxHours'];
        if (!$endAt->isSameDay($startAt)) {
            return $this->errorResponse('A leave request must start and end on the same day', [
                'code' => 'leave_not_same_day',
            ] + $rule, null, 422);
        }
        if ($endAt->gt($startAt->copy()->addHours($maxHours))) {
            return $this->errorResponse("A leave request can last at most {$maxHours} hours", [
                'code' => 'leave_duration_exceeded',
            ] + $rule, null, 422);
        }

        return null;
    }

    /**
     * POST /update-leave-request — a student changes the window or text of
     * their own request while it is still pending and not signed out.
     */
    public function update(Request $request)
    {
        $user = $request->user();
        $studentId = $user->StudentID ?? $user->UserID ?? $request->input('studentId');
        if (!$studentId) {
            return $this->errorResponse('Missing studentId', [], null, 422);
        }

        $data = $request->validate([
            'leaveId' => ['required', 'integer'],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date'],
            'comment' => ['nullable', 'string'],
            'doing' => ['nullable', 'string'],
        ]);
        $leaveId = (int) $data['leaveId'];

        $editor = app(LeaveRequestEditor::class);
        $row = $editor->findEditable($studentId, $leaveId);
        if (!$row) {
            return $this->notEditable($studentId, $leaveId, 'update');
        }

        $startAt = Carbon::parse($data['startDate']);
        $endAt = Carbon::parse($data['endDate']);
        if ($rejection = $this->windowRejection($studentId, $startAt, $endAt)) {
            return $rejection;
        }

        $startDateTime = $startAt->toDateTimeString();
        $endDateTime = $endAt->toDateTimeString();

        $leaveBan = $this->getStudentLeaveBan($studentId, $startDateTime, $endDateTime);
        if (!empty($leaveBan)) {
            return $this->successResponse('Leave request blocked', [
                'result' => 2,
                'leaveBans' => $leaveBan,
            ]);
        }

        $conflicts = $this->conflictQuery($studentId, $startDateTime, $endDateTime)
            ->where('LeaveID', '<>', $leaveId)
            ->get(['LeaveID', 'SDate', 'EDate', 'LeaveStatus'])
            ->toArray();
        if (!empty($conflicts)) {
            Log::warning('Leave request update rejected: overlaps a pending or in-progress request', [
                'studentId' => $studentId,
                'leaveId' => $leaveId,
                'startDate' => $startDateTime,
                'endDate' => $endDateTime,
                'conflicts' => array_column($conflicts, 'LeaveID'),
            ]);

            return $this->errorResponse('Leave request overlaps a pass that is not finished yet', [
                'code' => 'leave_conflict',
                'conflicts' => $conflicts,
            ], null, 409);
        }

        $changed = $editor->update($studentId, $leaveId, [
            'SDate' => $startDateTime,
            'EDate' => $endDateTime,
            'ToDo' => $data['doing'] ?? '',
            'Comment' => $data['comment'] ?? '',
        ]);
        if ($changed === 0) {
            return $this->notEditable($studentId, $leaveId, 'update');
        }

        Log::info('Leave request updated by student', [
            'studentId' => $studentId,
            'leaveId' => $leaveId,
            'from' => [$row->SDate, $row->EDate],
            'to' => [$startDateTime, $endDateTime],
        ]);

        return $this->successResponse('Leave request updated', [
            'result' => 1,
            'leaveRequestId' => $leaveId,
        ]);
    }

    /**
     * POST /cancel-leave-request — a student withdraws their own request
     * while it is still pending and not signed out. The row is deleted;
     * the window is logged so there is a trace.
     */
    public function cancel(Request $request)
    {
        $user = $request->user();
        $studentId = $user->StudentID ?? $user->UserID ?? $request->input('studentId');
        if (!$studentId) {
            return $this->errorResponse('Missing studentId', [], null, 422);
        }

        $data = $request->validate([
            'leaveId' => ['required', 'integer'],
        ]);
        $leaveId = (int) $data['leaveId'];

        $editor = app(LeaveRequestEditor::class);
        $row = $editor->findEditable($studentId, $leaveId);
        if (!$row) {
            return $this->notEditable($studentId, $leaveId, 'cancel');
        }

        if ($editor->delete($studentId, $leaveId) === 0) {
            return $this->notEditable($studentId, $leaveId, 'cancel');
        }

        Log::info('Leave request cancelled by student', [
            'studentId' => $studentId,
            'leaveId' => $leaveId,
            'window' => [$row->SDate, $row->EDate],
            'toDo' => $row->ToDo,
        ]);

        return $this->successResponse('Leave request cancelled', [
            'result' => 1,
            'leaveRequestId' => $leaveId,
        ]);
    }

    private function notEditable(string $studentId, int $leaveId, string $action)
    {
        // 4xx responses are not logged by the framework.
        Log::info("Leave request {$action} refused: not pending or already signed out", [
            'studentId' => $studentId,
            'leaveId' => $leaveId,
        ]);

        return $this->errorResponse('This leave request can no longer be changed', [
            'code' => 'leave_not_editable',
        ], null, 409);
    }

    /**
     * GET /get-leave-rule — the same-day hour limit that applies to the
     * signed-in student, so the app can check locally with the right number.
     */
    public function rule(Request $request)
    {
        $user = $request->user();
        $studentId = $user->StudentID ?? $user->UserID ?? $request->input('studentId');
        if (!$studentId) {
            return $this->errorResponse('Missing studentId', [], null, 422);
        }

        return $this->successResponse('Success', $this->leaveRule($studentId));
    }

    private function leaveRule(string $studentId): array
    {
        // Resolved from the container at call time so tests can stand in the
        // hall lookup without a SQL Server.
        return app(LeaveRuleResolver::class)->ruleFor($studentId);
    }

    private function getStudentLeaveBan(string $studentId, string $startDateTime, string $endDateTime): array
    {
        return $this->banQuery($studentId, $startDateTime, $endDateTime)->get()->toArray();
    }

    /**
     * Active bans whose window overlaps the requested pass.
     *
     * FromDate and ToDate are datetimes, and staff enter real times: the most
     * common ban on record runs 15:30 to 07:00 the next morning, and
     * residence now enters day bans as 00:05-23:55. The check must therefore
     * compare the pass window with the ban window, not a bare date. The
     * previous version compared the pass *date* (midnight) with the ban
     * columns, so a ban that started at 00:05 was never seen and seven
     * students left on passes during a ban on 2026-09-25.
     */
    public function banQuery(string $studentId, string $startDateTime, string $endDateTime)
    {
        return StudentLeaveBan::query()
            ->where('StudentID', $studentId)
            ->where('Status', 'A')
            ->where('FromDate', '<=', $endDateTime)
            ->where('ToDate', '>=', $startDateTime);
    }

    /**
     * Statuses that still block a new request for an overlapping window.
     *
     * SPAdmin (boarding/?page=leave) saves a record as I once OutDate is set and
     * as A once InDate is set too, so A normally means the student has signed
     * back in and the pass is finished. Staff can also pick "Approved" in the
     * modal before the student leaves; that row has no sign-in yet and must
     * still block. R is rejected. An empty date is stored as 1900-01-01.
     */
    public const BLOCKING_LEAVE_STATUSES = ['P', 'I'];
    public const NO_DATE_PLACEHOLDER = '1901-01-01';

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
            ->where(function ($query) {
                $query->whereIn('LeaveStatus', self::BLOCKING_LEAVE_STATUSES)
                    ->orWhere(function ($query) {
                        // Approved on the desktop but not signed back in.
                        $query->where('LeaveStatus', 'A')
                            ->where(function ($query) {
                                $query->whereNull('InDate')
                                    ->orWhere('InDate', '<', self::NO_DATE_PLACEHOLDER);
                            });
                    });
            })
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
