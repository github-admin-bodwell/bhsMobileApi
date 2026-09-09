<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The same-day hour limit that applies to one student.
 *
 * Residence rule (2026-09-09): 6 hours for a Junior student, 8 for a Senior
 * one. Junior is decided by hall: tblBHSDorm.HallName carries a "(Jr.)"
 * suffix on three halls (Courage West, Harmony East, Unity East) and the hall
 * assignment in phpadmin copies that name verbatim into tblBHSHomestay.Halls.
 */
class LeaveRuleResolver
{
    public const TIER_JUNIOR = 'junior';
    public const TIER_SENIOR = 'senior';

    /**
     * No hall on record counts as junior: a wrongly restricted senior gets a
     * clear message and can go to Boarding Admin, a wrongly relaxed junior
     * would get two extra hours.
     */
    public static function tierForHall(?string $hall): string
    {
        $hall = trim((string) $hall);
        if ($hall === '') {
            return self::TIER_JUNIOR;
        }
        $marker = (string) config('features.leave_junior_hall_marker', '(Jr.)');
        if ($marker !== '' && stripos($hall, $marker) !== false) {
            return self::TIER_JUNIOR;
        }

        return self::TIER_SENIOR;
    }

    /** Hall from the homestay record, or null when the student has none. */
    public function hallFor(string $studentId): ?string
    {
        $row = DB::table('tblBHSHomestay')
            ->where('StudentID', $studentId)
            ->orderByDesc('CheckInDate')
            ->first(['Halls']);

        $hall = trim((string) ($row->Halls ?? ''));

        return $hall === '' ? null : $hall;
    }

    /**
     * @return array{maxHours:int,tier:string,hall:?string,juniorHours:int,seniorHours:int}
     */
    public function ruleFor(string $studentId): array
    {
        $juniorHours = max(1, (int) config('features.leave_max_hours_junior', 6));
        $seniorHours = max(1, (int) config('features.leave_max_hours_senior', 8));

        $hall = $this->hallFor($studentId);
        $tier = self::tierForHall($hall);
        if ($hall === null) {
            Log::info('Leave rule: student has no hall on record, treated as junior', [
                'studentId' => $studentId,
            ]);
        }

        return [
            'maxHours' => $tier === self::TIER_JUNIOR ? $juniorHours : $seniorHours,
            'tier' => $tier,
            'hall' => $hall,
            'juniorHours' => $juniorHours,
            'seniorHours' => $seniorHours,
        ];
    }
}
