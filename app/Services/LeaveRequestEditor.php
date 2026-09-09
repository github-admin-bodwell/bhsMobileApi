<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A student may change or cancel their own leave request only while it is
 * still pending and atrium has not signed them out. SPAdmin's leave modal
 * writes an empty date as 1900-01-01, so "no sign-out" is null or before
 * 1901. Every write repeats the same conditions in its WHERE clause, so a
 * staff sign-out that lands at the same moment wins and the student sees a
 * "no longer editable" message instead of overwriting a live pass.
 */
class LeaveRequestEditor
{
    public const TABLE = 'tblBHSStudentLeaveRequest';
    public const EDITABLE_STATUS = 'P';
    public const NO_DATE_PLACEHOLDER = '1901-01-01';

    private function editable(string $studentId, int $leaveId): Builder
    {
        return DB::table(self::TABLE)
            ->where('LeaveID', $leaveId)
            ->where('StudentID', $studentId)
            ->where('LeaveStatus', self::EDITABLE_STATUS)
            ->where(function ($query) {
                $query->whereNull('OutDate')
                    ->orWhere('OutDate', '<', self::NO_DATE_PLACEHOLDER);
            });
    }

    /** The row if the student may still change it, otherwise null. */
    public function findEditable(string $studentId, int $leaveId): ?object
    {
        return $this->editable($studentId, $leaveId)
            ->first(['LeaveID', 'StudentID', 'LeaveStatus', 'SDate', 'EDate', 'ToDo', 'Comment']);
    }

    /** Rows changed: 1, or 0 when staff got there first. */
    public function update(string $studentId, int $leaveId, array $fields): int
    {
        return $this->editable($studentId, $leaveId)->update($fields + [
            'ModifyUserID' => $studentId,
            'ModifyDate' => now()->toDateTimeString(),
        ]);
    }

    /** Rows deleted: 1, or 0 when staff got there first. */
    public function delete(string $studentId, int $leaveId): int
    {
        return $this->editable($studentId, $leaveId)->delete();
    }
}
