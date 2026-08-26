<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\StudentAuth;
use App\Models\UserAuth;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Biometrics v2, task V6.
 *
 * A device token is exchanged for a session without the password ever being
 * presented, so it must not outlive the password it was enrolled under.
 *
 * The two paths differ on purpose. changePassword is user-initiated and keeps the
 * caller's own session. syncPassword is the portal's reset hook — the compromise
 * response — and cuts sessions too.
 */
class PasswordChangeRevokesCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private const CHANGE = '/api/settings/change-password';
    private const SYNC = '/api/auth/sync-password';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tblBHSUserAuth', function (Blueprint $t) {
            $t->unsignedBigInteger('LoginIDParent')->nullable();
            $t->unsignedBigInteger('UserID')->nullable();
            $t->string('LoginID')->nullable();
            $t->string('FirstName')->nullable();
            $t->string('LastName')->nullable();
            $t->string('VerifiedEmail')->nullable();
            $t->string('HashPassword')->nullable();
            $t->string('PW1')->nullable();
            $t->string('PW2')->nullable();
            $t->string('PW3')->nullable();
        });
    }

    private function parent(string $password = 'old-password'): UserAuth
    {
        UserAuth::insert([
            'LoginIDParent' => 1001,
            'HashPassword' => Hash::make($password),
            'PW2' => $password,
        ]);

        return UserAuth::where('LoginIDParent', 1001)->first();
    }

    private function student(string $password = 'old-password'): StudentAuth
    {
        StudentAuth::insert([
            'UserID' => 2002,
            'PW1' => Hash::make($password),
            'PW3' => $password,
        ]);

        return StudentAuth::where('UserID', 2002)->first();
    }

    private function enrolDevice($user, string $deviceId = 'device-abc'): void
    {
        DeviceToken::create([
            'tokenable_id' => $user->getKey(),
            'tokenable_type' => get_class($user),
            'device_id' => $deviceId,
            'token' => hash('sha256', 'dt_' . Str::random(64)),
            'abilities' => ['device.issue'],
            'expires_at' => now()->addYear(),
        ]);
    }

    private function devicesFor($user): int
    {
        return DeviceToken::where('tokenable_type', get_class($user))
            ->where('tokenable_id', $user->getKey())
            ->count();
    }

    // ---------- user-initiated change ----------

    public function test_changing_a_password_revokes_the_users_device_tokens(): void
    {
        $user = $this->parent();
        $this->enrolDevice($user, 'device-phone');
        $this->enrolDevice($user, 'device-tablet');
        Sanctum::actingAs($user, ['parent:*']);

        $this->postJson(self::CHANGE, [
            'oldPassword' => 'old-password',
            'newPassword' => 'a-new-password',
            'confirmPassword' => 'a-new-password',
        ])->assertStatus(200);

        $this->assertSame(0, $this->devicesFor($user));
    }

    public function test_a_students_password_change_revokes_their_device_tokens(): void
    {
        $user = $this->student();
        $this->enrolDevice($user);
        Sanctum::actingAs($user, ['student:*']);

        $this->postJson(self::CHANGE, [
            'oldPassword' => 'old-password',
            'newPassword' => 'a-new-password',
            'confirmPassword' => 'a-new-password',
        ])->assertStatus(200);

        $this->assertSame(0, $this->devicesFor($user));
    }

    public function test_a_failed_password_change_revokes_nothing(): void
    {
        $user = $this->parent();
        $this->enrolDevice($user);
        Sanctum::actingAs($user, ['parent:*']);

        $this->postJson(self::CHANGE, [
            'oldPassword' => 'wrong-password',
            'newPassword' => 'a-new-password',
            'confirmPassword' => 'a-new-password',
        ]);

        $this->assertSame(1, $this->devicesFor($user), 'a rejected change must not revoke anything');
    }

    public function test_one_users_change_leaves_another_users_devices_alone(): void
    {
        $parent = $this->parent();
        $student = $this->student();
        $this->enrolDevice($parent);
        $this->enrolDevice($student);
        Sanctum::actingAs($parent, ['parent:*']);

        $this->postJson(self::CHANGE, [
            'oldPassword' => 'old-password',
            'newPassword' => 'a-new-password',
            'confirmPassword' => 'a-new-password',
        ])->assertStatus(200);

        // UserAuth and StudentAuth share a table, so this also pins that the revoke
        // matches on tokenable_type and not on id alone.
        $this->assertSame(0, $this->devicesFor($parent));
        $this->assertSame(1, $this->devicesFor($student));
    }

    // ---------- portal-driven reset ----------

    public function test_a_portal_reset_revokes_device_tokens_and_sessions(): void
    {
        $user = $this->student();
        $this->enrolDevice($user);
        $user->createToken('mobile', ['student:*']);

        $this->postJson(self::SYNC, [
            'studentId' => 2002,
            'password' => 'reset-password',
            'role' => 'student',
        ])->assertStatus(200);

        $this->assertSame(0, $this->devicesFor($user));
        $this->assertSame(0, $user->tokens()->count(), 'a forced reset must end live sessions');
    }

    public function test_a_portal_reset_for_a_parent_revokes_device_tokens_and_sessions(): void
    {
        $user = $this->parent();
        $this->enrolDevice($user);
        $user->createToken('mobile', ['parent:*']);

        $this->postJson(self::SYNC, [
            'studentId' => 1001,
            'password' => 'reset-password',
            'role' => 'parent',
        ])->assertStatus(200);

        $this->assertSame(0, $this->devicesFor($user));
        $this->assertSame(0, $user->tokens()->count());
    }
}
