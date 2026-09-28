<?php

namespace Tests\Feature;

use App\Http\Controllers\LeaveRequestController;
use App\Models\StudentAuth;
use App\Services\LeaveRequestEditor;
use App\Services\LeaveRuleResolver;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the checks in LeaveRequestController::store that run before any
 * database access, so no SQL Server connection is needed.
 */
class LeaveRequestStoreTest extends TestCase
{
    private function actingAsStudent(?string $hall = 'Legacy West'): void
    {
        $student = new StudentAuth();
        $student->UserID = '202600081';
        Sanctum::actingAs($student);

        // The hall comes from tblBHSHomestay; stand it in so no SQL Server is needed.
        $this->partialMock(LeaveRuleResolver::class, function ($mock) use ($hall) {
            $mock->shouldReceive('hallFor')->andReturn($hall);
        });
    }

    private function payload(string $start, string $end): array
    {
        return [
            'leavetype' => 'Campus Leave',
            'startDate' => $start,
            'endDate' => $end,
            'doing' => 'Shopping',
        ];
    }

    public function test_end_on_a_later_day_is_rejected(): void
    {
        $this->actingAsStudent();

        // The original incident: a year-long leave from the iOS year wheel.
        $response = $this->postJson('/api/add-leave-request', $this->payload(
            '2026-09-01 17:39:00',
            '2027-09-01 17:39:00'
        ));

        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonPath('data.code', 'leave_not_same_day')
            ->assertJsonPath('data.maxHours', 8)
            ->assertJsonPath('data.tier', 'senior');
    }

    public function test_crossing_midnight_is_rejected_even_when_short(): void
    {
        $this->actingAsStudent();

        $response = $this->postJson('/api/add-leave-request', $this->payload(
            '2026-09-02 23:00:00',
            '2026-09-03 00:30:00'
        ));

        $response->assertStatus(422)
            ->assertJsonPath('data.code', 'leave_not_same_day');
    }

    public function test_senior_over_eight_hours_is_rejected(): void
    {
        $this->actingAsStudent('Legacy West');

        $response = $this->postJson('/api/add-leave-request', $this->payload(
            '2026-09-02 08:00:00',
            '2026-09-02 16:01:00'
        ));

        $response->assertStatus(422)
            ->assertJsonPath('data.code', 'leave_duration_exceeded')
            ->assertJsonPath('data.maxHours', 8)
            ->assertJsonPath('data.tier', 'senior')
            ->assertJsonPath('data.juniorHours', 6)
            ->assertJsonPath('data.seniorHours', 8);
    }

    public function test_junior_hall_over_six_hours_is_rejected(): void
    {
        $this->actingAsStudent('Unity East (Jr.)');

        $response = $this->postJson('/api/add-leave-request', $this->payload(
            '2026-09-02 08:00:00',
            '2026-09-02 14:01:00'
        ));

        $response->assertStatus(422)
            ->assertJsonPath('data.code', 'leave_duration_exceeded')
            ->assertJsonPath('data.maxHours', 6)
            ->assertJsonPath('data.tier', 'junior')
            ->assertJsonPath('data.hall', 'Unity East (Jr.)');
    }

    public function test_student_with_no_hall_gets_the_junior_limit(): void
    {
        $this->actingAsStudent(null);

        $response = $this->postJson('/api/add-leave-request', $this->payload(
            '2026-09-02 08:00:00',
            '2026-09-02 14:01:00'
        ));

        $response->assertStatus(422)
            ->assertJsonPath('data.code', 'leave_duration_exceeded')
            ->assertJsonPath('data.maxHours', 6)
            ->assertJsonPath('data.tier', 'junior');
    }

    public function test_tier_comes_from_the_hall_marker(): void
    {
        $this->assertSame('junior', LeaveRuleResolver::tierForHall('Courage West (Jr.)'));
        $this->assertSame('junior', LeaveRuleResolver::tierForHall('Harmony East (Jr.)'));
        $this->assertSame('junior', LeaveRuleResolver::tierForHall('Unity East (Jr.)'));
        $this->assertSame('senior', LeaveRuleResolver::tierForHall('Courage West (Sr.)'));
        $this->assertSame('senior', LeaveRuleResolver::tierForHall('Legacy West'));
        $this->assertSame('senior', LeaveRuleResolver::tierForHall('Discovery East'));
        $this->assertSame('junior', LeaveRuleResolver::tierForHall(null));
        $this->assertSame('junior', LeaveRuleResolver::tierForHall('   '));
    }

    public function test_leave_rule_endpoint_reports_the_student_limit(): void
    {
        $this->actingAsStudent('Harmony East (Jr.)');

        $this->getJson('/api/get-leave-rule')
            ->assertOk()
            ->assertJsonPath('data.maxHours', 6)
            ->assertJsonPath('data.tier', 'junior')
            ->assertJsonPath('data.juniorHours', 6)
            ->assertJsonPath('data.seniorHours', 8);
    }

    public function test_zero_length_request_is_rejected(): void
    {
        $this->actingAsStudent();

        $response = $this->postJson('/api/add-leave-request', $this->payload(
            '2026-09-02 16:01:00',
            '2026-09-02 16:01:00'
        ));

        $response->assertStatus(422)
            ->assertJsonPath('data.code', 'leave_end_before_start');
    }

    public function test_end_before_start_is_rejected_with_a_code(): void
    {
        $this->actingAsStudent();

        $response = $this->postJson('/api/add-leave-request', $this->payload(
            '2026-09-02 16:00:00',
            '2026-09-02 15:00:00'
        ));

        $response->assertStatus(422)
            ->assertJsonPath('data.code', 'leave_end_before_start');
    }

    /**
     * 2026-09-09 report: a student who came back early from a 3:00-6:00 pass
     * could not submit 4:00-5:00, because the finished pass (status A, signed
     * back in) still counted as a conflict. P (not yet out), I (out now) and
     * an A with no sign-in recorded may block; a finished A and R must not.
     */
    public function test_conflict_check_only_looks_at_pending_and_out_passes(): void
    {
        $query = app(LeaveRequestController::class)
            ->conflictQuery('202600081', '2026-09-09 16:00:00', '2026-09-09 17:00:00');

        $sql = $query->toSql();
        $bindings = $query->getBindings();

        $this->assertMatchesRegularExpression('/.LeaveStatus. in \(\?, \?\)/', $sql);
        $this->assertStringNotContainsString('<>', $sql);
        $this->assertSame(['P', 'I'], LeaveRequestController::BLOCKING_LEAVE_STATUSES);
        $this->assertContains('P', $bindings);
        $this->assertContains('I', $bindings);
        $this->assertNotContains('R', $bindings);

        // A still blocks while the desktop has not recorded a sign-in: SPAdmin
        // stores an empty date as 1900-01-01, so "no sign-in" is null or earlier
        // than 1901.
        $this->assertMatchesRegularExpression('/.LeaveStatus. = \? and \(.InDate. is null or .InDate. < \?\)/', $sql);
        $this->assertContains('A', $bindings);
        $this->assertContains('1901-01-01', $bindings);
    }

    public function test_ban_check_compares_the_pass_window_with_the_ban_times(): void
    {
        // 2026-09-25: residence entered the day's bans as 00:05-23:55. The
        // old check compared the bare pass date (midnight) with the ban
        // columns and never matched, so the passes went through.
        $query = app(LeaveRequestController::class)
            ->banQuery('202600081', '2026-09-25 16:00:00', '2026-09-25 18:00:00');

        $sql = $query->toSql();
        $bindings = $query->getBindings();

        $this->assertMatchesRegularExpression('/.FromDate. <= \?/', $sql);
        $this->assertMatchesRegularExpression('/.ToDate. >= \?/', $sql);
        $this->assertStringNotContainsString('between', strtolower($sql));
        $this->assertContains('2026-09-25 16:00:00', $bindings);
        $this->assertContains('2026-09-25 18:00:00', $bindings);
        $this->assertNotContains('2026-09-25', $bindings);
        $this->assertContains('A', $bindings);
    }

    public function test_store_passes_the_full_window_to_the_ban_check(): void
    {
        $this->actingAsStudent();

        $captured = null;
        $controller = $this->partialMock(LeaveRequestController::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('banQuery')->once()->andReturnUsing(function ($studentId, $start, $end) use (&$captured) {
                $captured = [$studentId, $start, $end];
                // A ban row: the request must stop here with result 2 and touch nothing else.
                return new class {
                    public function get() { return collect([['BanID' => 1]]); }
                };
            });
        });

        $response = $this->postJson('/api/add-leave-request', $this->payload(
            '2026-09-25 16:00:00',
            '2026-09-25 18:00:00'
        ));

        $response->assertOk()->assertJsonPath('data.result', 2);
        $this->assertSame(['202600081', '2026-09-25 16:00:00', '2026-09-25 18:00:00'], $captured);
    }

    public function test_current_ban_is_judged_by_campus_time_not_utc(): void
    {
        // Thursday 2026-09-24, 4:30pm in Vancouver is already Friday in UTC.
        // The old check used the UTC date, so a Friday ban showed on Thursday
        // from 5pm and disappeared at 5pm on Friday.
        Carbon::setTestNow(Carbon::parse('2026-09-24 23:30:00', 'UTC'));
        try {
            $this->assertSame('2026-09-24 16:30:00', LeaveRequestController::campusNow());

            $query = app(LeaveRequestController::class)->activeBanQuery('202600081');
            $this->assertMatchesRegularExpression('/\? BETWEEN FromDate AND ToDate/', $query->toSql());
            $this->assertContains('2026-09-24 16:30:00', $query->getBindings());
            $this->assertNotContains('2026-09-25', $query->getBindings());
        } finally {
            Carbon::setTestNow();
        }
    }

    private function pendingRow(int $leaveId = 5001): object
    {
        return (object) [
            'LeaveID' => $leaveId,
            'StudentID' => '202600081',
            'LeaveStatus' => 'P',
            'SDate' => '2026-09-10 15:00:00',
            'EDate' => '2026-09-10 18:00:00',
            'ToDo' => 'Shopping',
            'Comment' => '',
        ];
    }

    public function test_update_is_refused_when_the_request_is_not_pending_any_more(): void
    {
        $this->actingAsStudent();
        $this->partialMock(LeaveRequestEditor::class, function ($mock) {
            $mock->shouldReceive('findEditable')->with('202600081', 5001)->andReturn(null);
            $mock->shouldNotReceive('update');
        });

        $this->postJson('/api/update-leave-request', [
            'leaveId' => 5001,
            'startDate' => '2026-09-10 16:00:00',
            'endDate' => '2026-09-10 17:00:00',
            'doing' => 'Shopping',
        ])->assertStatus(409)
            ->assertJsonPath('data.code', 'leave_not_editable');
    }

    public function test_update_applies_the_same_window_rules_as_create(): void
    {
        $this->actingAsStudent('Unity East (Jr.)');
        $this->partialMock(LeaveRequestEditor::class, function ($mock) {
            $mock->shouldReceive('findEditable')->andReturn($this->pendingRow());
            $mock->shouldNotReceive('update');
        });

        $this->postJson('/api/update-leave-request', [
            'leaveId' => 5001,
            'startDate' => '2026-09-10 08:00:00',
            'endDate' => '2026-09-10 14:01:00',
            'doing' => 'Shopping',
        ])->assertStatus(422)
            ->assertJsonPath('data.code', 'leave_duration_exceeded')
            ->assertJsonPath('data.maxHours', 6);

        $this->postJson('/api/update-leave-request', [
            'leaveId' => 5001,
            'startDate' => '2026-09-10 23:00:00',
            'endDate' => '2026-09-11 00:30:00',
            'doing' => 'Shopping',
        ])->assertStatus(422)
            ->assertJsonPath('data.code', 'leave_not_same_day');
    }

    public function test_cancel_deletes_a_pending_request(): void
    {
        $this->actingAsStudent();
        $this->partialMock(LeaveRequestEditor::class, function ($mock) {
            $mock->shouldReceive('findEditable')->with('202600081', 5001)->andReturn($this->pendingRow());
            $mock->shouldReceive('delete')->with('202600081', 5001)->once()->andReturn(1);
        });

        $this->postJson('/api/cancel-leave-request', ['leaveId' => 5001])
            ->assertOk()
            ->assertJsonPath('data.result', 1)
            ->assertJsonPath('data.leaveRequestId', 5001);
    }

    public function test_cancel_is_refused_when_staff_signed_the_student_out_first(): void
    {
        $this->actingAsStudent();
        $this->partialMock(LeaveRequestEditor::class, function ($mock) {
            // Editable when read, but the conditional DELETE finds nothing:
            // atrium recorded the sign-out in between.
            $mock->shouldReceive('findEditable')->andReturn($this->pendingRow());
            $mock->shouldReceive('delete')->once()->andReturn(0);
        });

        $this->postJson('/api/cancel-leave-request', ['leaveId' => 5001])
            ->assertStatus(409)
            ->assertJsonPath('data.code', 'leave_not_editable');
    }

    public function test_cancel_needs_a_leave_id(): void
    {
        $this->actingAsStudent();

        $this->postJson('/api/cancel-leave-request', [])->assertStatus(422);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson('/api/add-leave-request', $this->payload(
            '2026-09-02 16:00:00',
            '2026-09-02 16:15:00'
        ));

        $response->assertStatus(401);
    }
}
