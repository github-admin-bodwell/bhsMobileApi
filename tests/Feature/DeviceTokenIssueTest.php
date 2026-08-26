<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\StudentAuth;
use App\Models\Students;
use App\Models\UserAuth;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the biometric device-token exchange.
 *
 * v1 (/auth/device/issue) must answer 410 unconditionally, feature flag or not —
 * re-arming it revives the launch loop on the February 2026 bundle.
 * v2 (/auth/device/v2/issue) is the live exchange.
 *
 * tblBHSUserAuth is an existing SQL Server table, not a migration, so it is built
 * here. Both UserAuth (parent) and StudentAuth (student) live on it and differ only
 * by primary key — which is exactly why role must be decided by class.
 */
class DeviceTokenIssueTest extends TestCase
{
    use RefreshDatabase;

    private const V1 = '/api/auth/device/issue';
    private const V2 = '/api/auth/device/v2/issue';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tblBHSUserAuth', function (Blueprint $t) {
            $t->unsignedBigInteger('LoginIDParent')->nullable();
            $t->unsignedBigInteger('UserID')->nullable();
            $t->string('FirstName')->nullable();
            $t->string('LastName')->nullable();
            $t->string('VerifiedEmail')->nullable();
            $t->string('HashPassword')->nullable();
            $t->string('PW1')->nullable();
        });

        config(['features.biometric_login' => true]);
    }

    /** Mint a device token and return [plaintext, record]. */
    private function deviceTokenFor($user, ?string $deviceId = 'device-abc'): array
    {
        $plain = 'dt_' . Str::random(64);

        $record = DeviceToken::create([
            'tokenable_id'   => $user->getKey(),
            'tokenable_type' => get_class($user),
            'device_id'      => $deviceId,
            'token'          => hash('sha256', $plain),
            'abilities'      => ['device.issue'],
            'expires_at'     => null,
        ]);

        return [$plain, $record];
    }

    private function parent(): UserAuth
    {
        UserAuth::insert(['LoginIDParent' => 1001, 'FirstName' => 'Pat', 'LastName' => 'Guardian']);
        return UserAuth::where('LoginIDParent', 1001)->first();
    }

    private function student(): StudentAuth
    {
        StudentAuth::insert(['UserID' => 2002, 'FirstName' => 'Sam', 'LastName' => 'Learner']);
        return StudentAuth::where('UserID', 2002)->first();
    }

    // ---------- v1 is permanently dormant ----------

    public function test_v1_answers_410_when_the_flag_is_off(): void
    {
        config(['features.biometric_login' => false]);

        $this->postJson(self::V1, ['deviceToken' => 'dt_whatever'])
            ->assertStatus(410);
    }

    public function test_v1_still_answers_410_when_the_flag_is_on(): void
    {
        // The invariant this whole route split exists to protect. If this ever fails,
        // every February-bundle device goes back into the infinite biometric prompt.
        config(['features.biometric_login' => true]);

        $user = $this->parent();
        [$plain] = $this->deviceTokenFor($user);

        $this->postJson(self::V1, ['deviceToken' => $plain, 'deviceId' => 'device-abc'])
            ->assertStatus(410);
    }

    // ---------- v2 gating and input ----------

    public function test_v2_answers_410_while_the_flag_is_off(): void
    {
        config(['features.biometric_login' => false]);

        $this->postJson(self::V2, ['deviceToken' => 'dt_x', 'deviceId' => 'device-abc'])
            ->assertStatus(410);
    }

    public function test_v2_requires_a_device_id(): void
    {
        $this->postJson(self::V2, ['deviceToken' => 'dt_x'])->assertStatus(422);
    }

    public function test_v2_requires_a_device_token(): void
    {
        $this->postJson(self::V2, ['deviceId' => 'device-abc'])->assertStatus(422);
    }

    // ---------- v2 rejection paths ----------

    public function test_v2_rejects_an_unknown_token(): void
    {
        $this->postJson(self::V2, ['deviceToken' => 'dt_nope', 'deviceId' => 'device-abc'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Device Unauthorized');
    }

    public function test_v2_rejects_a_mismatched_device_id(): void
    {
        [$plain] = $this->deviceTokenFor($this->parent(), 'device-abc');

        $this->postJson(self::V2, ['deviceToken' => $plain, 'deviceId' => 'device-zzz'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Device Unauthorized');
    }

    public function test_an_unbound_device_token_cannot_even_be_stored(): void
    {
        // v1 accepted records with no device_id and skipped binding for them entirely.
        // Since V5 the column is NOT NULL, so the case issueV2 guards against can no
        // longer reach the database at all. The guard stays as defence in depth.
        $this->expectException(QueryException::class);

        $this->deviceTokenFor($this->parent(), null);
    }

    public function test_a_user_cannot_hold_two_tokens_for_the_same_device(): void
    {
        $user = $this->parent();
        $this->deviceTokenFor($user, 'device-abc');

        $this->expectException(QueryException::class);

        $this->deviceTokenFor($user, 'device-abc');
    }

    public function test_v2_rejects_an_expired_device_token(): void
    {
        $user = $this->parent();
        [$plain, $record] = $this->deviceTokenFor($user);
        $record->update(['expires_at' => now()->subDay()]);

        $this->postJson(self::V2, ['deviceToken' => $plain, 'deviceId' => 'device-abc'])
            ->assertStatus(401);
    }

    // ---------- V5: rolling 12-month lifetime ----------

    public function test_enable_requires_a_device_id(): void
    {
        Sanctum::actingAs($this->parent(), ['parent:*']);

        $this->postJson('/api/auth/device/enable', ['deviceName' => 'Pixel'])
            ->assertStatus(422);
    }

    public function test_enable_mints_a_token_expiring_in_twelve_months(): void
    {
        Sanctum::actingAs($this->parent(), ['parent:*']);

        $res = $this->postJson('/api/auth/device/enable', ['deviceId' => 'device-abc'])
            ->assertStatus(200)
            ->assertJsonStructure(['deviceToken', 'deviceId', 'expiresAt']);

        $expires = Carbon::parse($res->json('expiresAt'));
        $this->assertTrue(
            $expires->between(now()->addMonths(12)->subMinute(), now()->addMonths(12)->addMinute()),
            'expected a 12-month window, got ' . $expires->toIso8601String()
        );
    }

    public function test_re_enabling_the_same_device_replaces_rather_than_stacks(): void
    {
        $user = $this->parent();
        Sanctum::actingAs($user, ['parent:*']);

        $first = $this->postJson('/api/auth/device/enable', ['deviceId' => 'device-abc'])->json('deviceToken');
        $this->postJson('/api/auth/device/enable', ['deviceId' => 'device-abc'])->assertStatus(200);

        $this->assertSame(1, DeviceToken::where('device_id', 'device-abc')->count());

        // The superseded token must be dead, not merely orphaned.
        $this->postJson(self::V2, ['deviceToken' => $first, 'deviceId' => 'device-abc'])
            ->assertStatus(401);
    }

    public function test_enabling_one_device_leaves_the_users_other_devices_alone(): void
    {
        $user = $this->parent();
        $this->deviceTokenFor($user, 'device-tablet');
        Sanctum::actingAs($user, ['parent:*']);

        $this->postJson('/api/auth/device/enable', ['deviceId' => 'device-phone'])->assertStatus(200);

        $this->assertSame(1, DeviceToken::where('device_id', 'device-tablet')->count());
        $this->assertSame(2, DeviceToken::where('tokenable_id', $user->getKey())->count());
    }

    public function test_a_successful_unlock_rolls_the_expiry_forward(): void
    {
        $user = $this->parent();
        [$plain, $record] = $this->deviceTokenFor($user);
        $record->update(['expires_at' => now()->addMonth()]);

        $this->postJson(self::V2, ['deviceToken' => $plain, 'deviceId' => 'device-abc'])
            ->assertStatus(200);

        $rolled = $record->fresh()->expires_at;
        $this->assertTrue(
            $rolled->between(now()->addMonths(12)->subMinute(), now()->addMonths(12)->addMinute()),
            'expected the window to roll to 12 months, got ' . $rolled->toIso8601String()
        );
    }

    public function test_v2_rejects_an_unrecognised_tokenable(): void
    {
        Schema::create('tblBHSStudent', function (Blueprint $t) {
            $t->unsignedBigInteger('StudentID');
            $t->string('SchoolEmail')->nullable();
            $t->string('FirstName')->nullable();
            $t->string('PW1')->nullable();
        });
        Students::insert(['StudentID' => 55, 'SchoolEmail' => 's@bodwell.edu']);
        $row = Students::where('StudentID', 55)->first();

        [$plain] = $this->deviceTokenFor($row);

        // Under the old property-sniffing roleFor this returned 200 with student:*.
        $this->postJson(self::V2, ['deviceToken' => $plain, 'deviceId' => 'device-abc'])
            ->assertStatus(401);
    }

    public function test_v2_does_not_revoke_sessions_on_a_rejected_request(): void
    {
        $user = $this->parent();
        $user->createToken('mobile', ['parent:*']);
        [$plain] = $this->deviceTokenFor($user, 'device-abc');

        $this->postJson(self::V2, ['deviceToken' => $plain, 'deviceId' => 'device-wrong'])
            ->assertStatus(401);

        $this->assertSame(1, $user->tokens()->count(), 'a rejected unlock must not log the user out elsewhere');
    }

    // ---------- v2 success ----------

    public function test_v2_issues_a_parent_token(): void
    {
        $user = $this->parent();
        [$plain, $record] = $this->deviceTokenFor($user);

        $res = $this->postJson(self::V2, ['deviceToken' => $plain, 'deviceId' => 'device-abc'])
            ->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonStructure(['data' => ['__t', 'expiresAt']]);

        // The response carries the token only — /auth/me supplies user and semester.
        $this->assertArrayNotHasKey('user', $res->json('data'));
        $this->assertArrayNotHasKey('semester', $res->json('data'));

        $this->assertSame(['parent:*'], $user->tokens()->first()->abilities);
        $this->assertNotNull($record->fresh()->last_used_at);
    }

    public function test_v2_replaces_the_users_existing_session(): void
    {
        // Single session per user, matching password login (AuthController:75) and
        // Azure login (AzureAuthController:94). Pinned so it stays deliberate.
        $user = $this->parent();
        $old = $user->createToken('mobile', ['parent:*'])->accessToken;
        [$plain] = $this->deviceTokenFor($user);

        $this->postJson(self::V2, ['deviceToken' => $plain, 'deviceId' => 'device-abc'])
            ->assertStatus(200);

        $this->assertSame(1, $user->tokens()->count());
        $this->assertNull($user->tokens()->find($old->getKey()), 'the previous session should be revoked');
    }

    public function test_v2_issues_a_student_token(): void
    {
        $user = $this->student();
        [$plain] = $this->deviceTokenFor($user);

        $this->postJson(self::V2, ['deviceToken' => $plain, 'deviceId' => 'device-abc'])
            ->assertStatus(200);

        // Under the old roleFor this was staff:* — a student holding staff abilities.
        $this->assertSame(['student:*'], $user->tokens()->first()->abilities);
    }
}
