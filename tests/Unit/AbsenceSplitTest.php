<?php

namespace Tests\Unit;

use App\Http\Controllers\AcademicController;
use PHPUnit\Framework\TestCase;

/**
 * The excused / unexcused split shown on the Academics course cards. Mirrors the
 * admin site's rule (unexcused = absences - excused) and never lets the two parts
 * disagree with the total.
 */
class AbsenceSplitTest extends TestCase
{
    public function test_all_excused(): void
    {
        // Meghan's case: nine absences, all excused, so the parent sees 9 / 0.
        $this->assertSame([9, 0], AcademicController::splitAbsences(9, 9));
    }

    public function test_none_excused(): void
    {
        $this->assertSame([0, 6], AcademicController::splitAbsences(6, 0));
    }

    public function test_mixed(): void
    {
        $this->assertSame([2, 3], AcademicController::splitAbsences(5, 2));
    }

    public function test_no_records(): void
    {
        $this->assertSame([0, 0], AcademicController::splitAbsences(0, 0));
    }

    public function test_excused_cannot_exceed_total(): void
    {
        // A row flagged Excuse=1 without AbsencePeriod=1 must not produce a negative unexcused count.
        $this->assertSame([3, 0], AcademicController::splitAbsences(3, 4));
    }
}
