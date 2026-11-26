<?php

namespace App\Console\Commands;

use App\Models\PushToken;
use App\Models\StudentAuth;
use App\Models\UserAuth;
use App\Services\ExpoPush;
use Illuminate\Console\Command;

class PushTest extends Command {
    protected $signature = 'push:test
        {id? : LoginIDParent for a parent, UserID for a student}
        {--role=parent : parent or student}
        {--title=MyBodwell : Notification title}
        {--body=Test notification : Notification text}
        {--url= : Screen to open on tap, for example /leaveRequest}
        {--receipt=* : Look up delivery receipts for these ticket ids instead of sending}';

    protected $description = 'Send one test push notification to one user, or look up delivery receipts';

    public function handle(ExpoPush $push): int {
        if ($ids = $this->option('receipt')) {
            foreach ($push->receipts($ids) as $id => $r) {
                $this->line(sprintf('%s  %s  %s', $id, $r['status'] ?? '?', $r['details']['error'] ?? ($r['message'] ?? '')));
            }
            return self::SUCCESS;
        }

        if (!config('push.enabled')) {
            $this->error('Push notifications are off. Set PUSH_NOTIFICATIONS_ENABLED=true first.');
            return self::FAILURE;
        }

        $id = $this->argument('id');
        if (!$id) {
            $this->error('Give the user id.');
            return self::FAILURE;
        }

        $role = $this->option('role');
        $user = $role === 'student' ? StudentAuth::find($id) : UserAuth::find($id);
        if (!$user) {
            $this->error("No {$role} with id {$id}.");
            return self::FAILURE;
        }

        $count = PushToken::forUser($user)->count();
        if ($count === 0) {
            $this->warn('This user has no registered phone. They need to sign in on a build with notifications and allow them.');
            return self::FAILURE;
        }

        $data = $this->option('url') ? ['url' => $this->option('url')] : [];
        $tickets = $push->sendToUser($user, $this->option('title'), $this->option('body'), $data);

        foreach ($tickets as $t) {
            $this->line(sprintf('%s  %s  %s', $t['status'], $t['id'] ?? '-', $t['error'] ?? ''));
        }
        $this->info('Sent to Expo. For the delivery result, wait about 15 minutes, then: php artisan push:test --receipt=<ticket id>');

        return self::SUCCESS;
    }
}
