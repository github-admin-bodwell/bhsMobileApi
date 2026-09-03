<?php

namespace Tests\Feature;

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

    public function test_end_date_more_than_max_days_after_start_is_rejected(): void
    {
        config(['features.leave_max_days' => 5]);
        $this->actingAsStudent();

        $response = $this->postJson('/api/add-leave-request', $this->payload(
            '2026-09-01 17:39:00',
            '2027-09-01 17:39:00'
        ));

        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonPath('data.code', 'leave_duration_exceeded')
            ->assertJsonPath('data.maxDays', 5);
    }

    public function test_end_date_one_minute_past_the_limit_is_rejected(): void
    {
        config(['features.leave_max_days' => 5]);
        $this->actingAsStudent();

        $response = $this->postJson('/api/add-leave-request', $this->payload(
            '2026-09-01 17:39:00',
            '2026-09-06 17:40:00'
        ));

        $response->assertStatus(422)
            ->assertJsonPath('data.code', 'leave_duration_exceeded');
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

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson('/api/add-leave-request', $this->payload(
            '2026-09-02 16:00:00',
            '2026-09-02 16:15:00'
        ));

        $response->assertStatus(401);
    }
}
