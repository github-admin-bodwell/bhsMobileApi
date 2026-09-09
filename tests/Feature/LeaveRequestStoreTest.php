<?php

namespace Tests\Feature;

use App\Models\StudentAuth;
use App\Services\LeaveRuleResolver;
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

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson('/api/add-leave-request', $this->payload(
            '2026-09-02 16:00:00',
            '2026-09-02 16:15:00'
        ));

        $response->assertStatus(401);
    }
}
