<?php

namespace App\Services;

use App\Models\PushToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends notifications through Expo's push service.
 *
 * Expo answers each message with a "ticket". A ticket with status ok means Expo
 * took the message, not that the phone received it; the delivery result is a
 * "receipt", available about 15 minutes later and kept for 24 hours.
 * https://docs.expo.dev/push-notifications/sending-notifications/
 */
class ExpoPush
{
    /** Expo accepts at most 100 messages per request. */
    private const BATCH = 100;

    /**
     * Send one notification to every phone a user is signed in on.
     * Returns the tickets, one per token, each with the token's row id added.
     *
     * Keep $title and $body generic: they show on a locked screen. Put the
     * detail behind the tap, in $data (for example ['url' => '/leaveRequest']).
     */
    public function sendToUser($user, string $title, string $body, array $data = []): array {
        $tokens = PushToken::forUser($user)->get();
        if ($tokens->isEmpty()) return [];

        $messages = $tokens->map(fn (PushToken $t) => $this->message($t->token, $title, $body, $data))->all();

        return $this->send($messages);
    }

    public function message(string $to, string $title, string $body, array $data = []): array {
        $message = [
            'to' => $to,
            'title' => $title,
            'body' => $body,
            'sound' => 'default',
            'priority' => 'high',
            'channelId' => (string) config('push.android_channel'),
        ];
        // Expo wants an object here; an empty PHP array would be sent as [].
        if ($data) $message['data'] = $data;

        return $message;
    }

    /**
     * Send prepared messages. Returns one ticket per message, in order, as
     * ['to' => token, 'status' => 'ok'|'error', 'id' => ?, 'error' => ?, 'message' => ?].
     * Never throws: a failed request becomes error tickets, so one bad batch
     * does not stop the caller's loop.
     */
    public function send(array $messages): array {
        if (!config('push.enabled')) {
            Log::info('[Push] not sent, feature is off', ['count' => count($messages)]);
            return [];
        }

        $tickets = [];

        foreach (array_chunk($messages, self::BATCH) as $chunk) {
            try {
                $res = $this->client()->post(config('push.expo.send_url'), $chunk);
            } catch (\Throwable $e) {
                Log::error('[Push] request failed', ['error' => $e->getMessage()]);
                foreach ($chunk as $m) {
                    $tickets[] = ['to' => $m['to'], 'status' => 'error', 'error' => 'RequestFailed', 'message' => $e->getMessage()];
                }
                continue;
            }

            $data = $res->json('data');

            if (!$res->successful() || !is_array($data)) {
                $why = $res->json('errors.0.code') ?? ('HTTP ' . $res->status());
                Log::error('[Push] Expo refused the batch', ['status' => $res->status(), 'error' => $why]);
                foreach ($chunk as $m) {
                    $tickets[] = ['to' => $m['to'], 'status' => 'error', 'error' => $why, 'message' => $res->json('errors.0.message')];
                }
                continue;
            }

            foreach ($chunk as $i => $m) {
                $t = $data[$i] ?? [];
                $error = $t['details']['error'] ?? null;

                $tickets[] = [
                    'to' => $m['to'],
                    'status' => $t['status'] ?? 'error',
                    'id' => $t['id'] ?? null,
                    'error' => $error,
                    'message' => $t['message'] ?? null,
                ];

                // The app was removed or notifications were turned off for it.
                // Expo asks senders to stop using the token.
                if ($error === 'DeviceNotRegistered') {
                    PushToken::where('token', $m['to'])->delete();
                    Log::info('[Push] dead token removed');
                }
            }
        }

        return $tickets;
    }

    /**
     * Delivery results for ticket ids, keyed by id. Ask about 15 minutes after
     * sending. Tokens are not removed here because a receipt does not carry the
     * token; DeviceNotRegistered is reported so the caller can act on it.
     */
    public function receipts(array $ids): array {
        $res = $this->client()->post(config('push.expo.receipts_url'), ['ids' => array_values($ids)]);

        return $res->json('data') ?? [];
    }

    private function client() {
        $client = Http::acceptJson()->asJson()->timeout((int) config('push.expo.timeout'));

        if ($token = config('push.expo.access_token')) {
            $client = $client->withToken($token);
        }

        return $client;
    }
}
