<?php

namespace Tests\Feature;

use App\Jobs\SendPush;
use App\Models\PushToken;
use App\Models\StudentAuth;
use App\Models\UserAuth;
use App\Services\ExpoPush;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Push token registration and sending through Expo.
 *
 * tblBHSUserAuth is an existing SQL Server table, not a migration, so it is built
 * here. Parent and student share it and can share an id, which is why every token
 * lookup has to match on class as well.
 */
class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_A = 'ExponentPushToken[aaaaaaaaaaaaaaaaaaaaaa]';
    private const TOKEN_B = 'ExponentPushToken[bbbbbbbbbbbbbbbbbbbbbb]';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tblBHSUserAuth', function (Blueprint $t) {
            $t->unsignedBigInteger('LoginIDParent')->nullable();
            $t->unsignedBigInteger('UserID')->nullable();
            $t->string('FirstName')->nullable();
            $t->string('LastName')->nullable();
        });

        config(['push.enabled' => true]);
    }

    private function parent(int $id = 1001): UserAuth
    {
        UserAuth::insert(['LoginIDParent' => $id, 'FirstName' => 'Pat', 'LastName' => 'Guardian']);
        return UserAuth::where('LoginIDParent', $id)->first();
    }

    private function student(int $id = 2002): StudentAuth
    {
        StudentAuth::insert(['UserID' => $id, 'FirstName' => 'Sam', 'LastName' => 'Learner']);
        return StudentAuth::where('UserID', $id)->first();
    }

    private function register(string $token, string $platform = 'ios')
    {
        return $this->postJson('/api/push/register', ['token' => $token, 'platform' => $platform, 'deviceId' => 'device-abc']);
    }

    // ---------- registration ----------

    public function test_register_needs_a_signed_in_user(): void
    {
        $this->register(self::TOKEN_A)->assertStatus(401);
    }

    public function test_register_stores_the_token_for_the_caller(): void
    {
        $parent = $this->parent();
        Sanctum::actingAs($parent, ['parent:*']);

        $this->register(self::TOKEN_A)->assertOk()->assertJsonPath('data.registered', true);

        $row = PushToken::first();
        $this->assertSame(self::TOKEN_A, $row->token);
        $this->assertSame(UserAuth::class, $row->tokenable_type);
        $this->assertEquals($parent->getKey(), $row->tokenable_id);
        $this->assertSame('ios', $row->platform);
    }

    public function test_registering_the_same_token_twice_keeps_one_row(): void
    {
        Sanctum::actingAs($this->parent(), ['parent:*']);

        $this->register(self::TOKEN_A)->assertOk();
        $this->register(self::TOKEN_A)->assertOk();

        $this->assertSame(1, PushToken::count());
    }

    public function test_a_new_phone_replaces_the_users_old_one(): void
    {
        Sanctum::actingAs($this->parent(), ['parent:*']);

        $this->register(self::TOKEN_A)->assertOk();
        $this->register(self::TOKEN_B, 'android')->assertOk();

        $this->assertSame([self::TOKEN_B], PushToken::pluck('token')->all());
    }

    public function test_a_phone_moves_to_whoever_signed_in_last(): void
    {
        $parent = $this->parent();
        $student = $this->student();

        Sanctum::actingAs($parent, ['parent:*']);
        $this->register(self::TOKEN_A)->assertOk();

        Sanctum::actingAs($student, ['student:*']);
        $this->register(self::TOKEN_A)->assertOk();

        $this->assertSame(1, PushToken::count());
        $this->assertSame(StudentAuth::class, PushToken::first()->tokenable_type);
        $this->assertSame(0, PushToken::forUser($parent)->count());
    }

    public function test_parent_and_student_with_the_same_id_do_not_share_tokens(): void
    {
        $parent = $this->parent(500);
        $student = $this->student(500);

        Sanctum::actingAs($parent, ['parent:*']);
        $this->register(self::TOKEN_A)->assertOk();
        Sanctum::actingAs($student, ['student:*']);
        $this->register(self::TOKEN_B)->assertOk();

        $this->assertSame(2, PushToken::count());
        $this->assertSame([self::TOKEN_A], PushToken::forUser($parent)->pluck('token')->all());
        $this->assertSame([self::TOKEN_B], PushToken::forUser($student)->pluck('token')->all());
    }

    public function test_register_refuses_a_token_that_is_not_an_expo_token(): void
    {
        Sanctum::actingAs($this->parent(), ['parent:*']);

        $this->register('not-a-token')->assertStatus(422);
        $this->postJson('/api/push/register', ['token' => self::TOKEN_A, 'platform' => 'windows'])->assertStatus(422);
        $this->assertSame(0, PushToken::count());
    }

    public function test_register_stores_nothing_while_the_feature_is_off(): void
    {
        config(['push.enabled' => false]);
        Sanctum::actingAs($this->parent(), ['parent:*']);

        $this->register(self::TOKEN_A)->assertOk()->assertJsonPath('data.enabled', false);
        $this->getJson('/api/push/config')->assertOk()->assertJsonPath('data.enabled', false);
        $this->assertSame(0, PushToken::count());
    }

    public function test_unregister_removes_only_the_callers_token(): void
    {
        $parent = $this->parent();
        $student = $this->student();
        PushToken::register($parent, self::TOKEN_A, 'ios');
        PushToken::register($student, self::TOKEN_B, 'android');

        // Works with the feature off, so switching it off never strands a token.
        config(['push.enabled' => false]);
        Sanctum::actingAs($parent, ['parent:*']);

        // Someone else's token in the body removes nothing.
        $this->postJson('/api/push/unregister', ['token' => self::TOKEN_B])->assertOk()->assertJsonPath('data.removed', 0);
        $this->postJson('/api/push/unregister', ['token' => self::TOKEN_A])->assertOk()->assertJsonPath('data.removed', 1);

        $this->assertSame([self::TOKEN_B], PushToken::pluck('token')->all());
    }

    // ---------- sending ----------

    public function test_send_posts_the_message_to_expo(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-1']]])]);
        $parent = $this->parent();
        PushToken::register($parent, self::TOKEN_A, 'ios');

        $tickets = app(ExpoPush::class)->sendToUser($parent, 'MyBodwell', 'You have a new update', ['url' => '/leaveRequest']);

        $this->assertSame('ok', $tickets[0]['status']);
        $this->assertSame('ticket-1', $tickets[0]['id']);

        Http::assertSent(function ($request) {
            $m = $request->data()[0];
            return $request->url() === 'https://exp.host/--/api/v2/push/send'
                && $m['to'] === self::TOKEN_A
                && $m['title'] === 'MyBodwell'
                && $m['data'] === ['url' => '/leaveRequest']
                && $m['channelId'] === 'default'
                && !$request->hasHeader('Authorization');
        });
    }

    public function test_send_uses_the_access_token_when_one_is_set(): void
    {
        config(['push.expo.access_token' => 'secret-token']);
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-1']]])]);
        $parent = $this->parent();
        PushToken::register($parent, self::TOKEN_A, 'ios');

        app(ExpoPush::class)->sendToUser($parent, 'MyBodwell', 'Hello');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret-token'));
    }

    public function test_a_dead_token_is_removed(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [[
            'status' => 'error',
            'message' => 'not a registered push notification recipient',
            'details' => ['error' => 'DeviceNotRegistered'],
        ]]])]);
        $parent = $this->parent();
        PushToken::register($parent, self::TOKEN_A, 'ios');

        $tickets = app(ExpoPush::class)->sendToUser($parent, 'MyBodwell', 'Hello');

        $this->assertSame('DeviceNotRegistered', $tickets[0]['error']);
        $this->assertSame(0, PushToken::count());
    }

    public function test_a_failed_request_keeps_the_token_and_reports_an_error(): void
    {
        Http::fake(['exp.host/*' => Http::response(['errors' => [['code' => 'TOO_MANY_REQUESTS', 'message' => 'slow down']]], 429)]);
        $parent = $this->parent();
        PushToken::register($parent, self::TOKEN_A, 'ios');

        $tickets = app(ExpoPush::class)->sendToUser($parent, 'MyBodwell', 'Hello');

        $this->assertSame('error', $tickets[0]['status']);
        $this->assertSame('TOO_MANY_REQUESTS', $tickets[0]['error']);
        $this->assertSame(1, PushToken::count());
    }

    public function test_more_than_100_messages_go_out_in_batches(): void
    {
        Http::fake(fn ($request) => Http::response([
            'data' => array_map(fn () => ['status' => 'ok', 'id' => 'x'], $request->data()),
        ]));

        $push = app(ExpoPush::class);
        $messages = [];
        for ($i = 0; $i < 230; $i++) {
            $messages[] = $push->message("ExponentPushToken[t{$i}]", 'MyBodwell', 'Hello');
        }

        $this->assertCount(230, $push->send($messages));
        Http::assertSentCount(3);
    }

    public function test_nothing_is_sent_while_the_feature_is_off(): void
    {
        Http::fake();
        $parent = $this->parent();
        PushToken::register($parent, self::TOKEN_A, 'ios');
        config(['push.enabled' => false]);

        $this->assertSame([], app(ExpoPush::class)->sendToUser($parent, 'MyBodwell', 'Hello'));
        Http::assertNothingSent();
    }

    public function test_the_job_sends_to_the_right_one_of_two_users_sharing_an_id(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-1']]])]);
        $parent = $this->parent(500);
        $student = $this->student(500);
        PushToken::register($parent, self::TOKEN_A, 'ios');
        PushToken::register($student, self::TOKEN_B, 'android');

        SendPush::dispatch(StudentAuth::class, 500, 'MyBodwell', 'Hello');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->data()[0]['to'] === self::TOKEN_B);
    }

    public function test_the_test_command_sends_to_a_registered_user(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-1']]])]);
        $parent = $this->parent();
        PushToken::register($parent, self::TOKEN_A, 'ios');

        $this->artisan('push:test', ['id' => 1001, '--role' => 'parent'])->assertSuccessful();
        Http::assertSentCount(1);

        $this->artisan('push:test', ['id' => 9999])->assertFailed();
    }
}
