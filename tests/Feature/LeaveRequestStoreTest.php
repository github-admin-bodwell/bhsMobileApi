<?php

namespace Tests\Feature;

use App\Http\Controllers\LeaveRequestController;
use App\Models\StudentAuth;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the checks in LeaveRequestController::store that run before any
 * database access, so no SQL Server connection is needed.
 */
class LeaveRequestStoreTest extends TestCase
{
    private function actingAsStudent(): void
    {
        $student = new StudentAuth();
        $student->UserID = '202600081';
        Sanctum::actingAs($student);
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
        config(['features.leave_max_hours' => 12]);
        $this->actingAsStudent();

        // The original incident: a year-long leave from the iOS year wheel.
        $response = $this->postJson('/api/add-leave-request', $this->payload(
            '2026-09-01 17:39:00',
            '2027-09-01 17:39:00'
        ));

        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonPath('data.code', 'leave_not_same_day')
            ->assertJsonPath('data.maxHours', 12);
    }

    public function test_crossing_midnight_is_rejected_even_when_short(): void
    {
        config(['features.leave_max_hours' => 12]);
        $this->actingAsStudent();

        $response = $this->postJson('/api/add-leave-request', $this->payload(
            '2026-09-02 23:00:00',
            '2026-09-03 00:30:00'
        ));

        $response->assertStatus(422)
            ->assertJsonPath('data.code', 'leave_not_same_day');
    }

    public function test_same_day_but_over_max_hours_is_rejected(): void
    {
        config(['features.leave_max_hours' => 12]);
        $this->actingAsStudent();

        $response = $this->postJson('/api/add-leave-request', $this->payload(
            '2026-09-02 08:00:00',
            '2026-09-02 20:01:00'
        ));

        $response->assertStatus(422)
            ->assertJsonPath('data.code', 'leave_duration_exceeded')
            ->assertJsonPath('data.maxHours', 12);
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
     * back in) still counted as a conflict. Only P (not yet out) and I (out
     * now) may block; A and R must be ignored.
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
        $this->assertNotContains('A', $bindings);
        $this->assertNotContains('R', $bindings);
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
