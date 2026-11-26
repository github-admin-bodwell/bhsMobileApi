<?php

namespace App\Jobs;

use App\Services\ExpoPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One notification to one user, sent off the request.
 *
 * Holds the user's id and class rather than the model: UserAuth and StudentAuth
 * share a table and differ by primary key, so the class is what says which one.
 *
 * Needs a queue worker (`php artisan queue:work`) on the server. Until one is
 * confirmed there, call ExpoPush directly or dispatch with dispatchSync().
 */
class SendPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public string $userClass,
        public int|string $userId,
        public string $title,
        public string $body,
        public array $data = [],
    ) {}

    public static function for($user, string $title, string $body, array $data = []): self {
        return new self(get_class($user), $user->getKey(), $title, $body, $data);
    }

    public function handle(ExpoPush $push): void {
        $user = $this->userClass::find($this->userId);
        if (!$user) return;

        $push->sendToUser($user, $this->title, $this->body, $this->data);
    }
}
