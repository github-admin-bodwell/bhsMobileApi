<?php
// app/Http/Controllers/FormsController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class FormsController extends Controller
{
    public function submit(Request $request)
    {
        // Pull raw inputs (no validation per your request)
        $studentId = (string) $request->input('studentId', '');
        $subject   = trim((string) $request->input('subject', '(no subject)'));
        $body      = (string) $request->input('body', '');
        // If using Sanctum, you likely have an authenticated user here:
        $user      = $request->user(); // may be null if not using auth on this route
        $replyTo   = $user->email ?? null;

        // Build plain-text email body
        $text = implode("\n", [
            "New message from mobile app",
            "----------------------------------------",
            "StudentID: {$studentId}",
            "Name: " . ($user->name ?? '(unknown)'),
            "Email: " . ($user->email ?? '(unknown)'),
            "User-Agent: " . ($request->header('User-Agent') ?? '(unknown)'),
            "----------------------------------------",
            "Subject: {$subject}",
            "",
            $body,
            "",
            "----------------------------------------",
            "Submitted at: " . now()->toDateTimeString(),
        ]);

        try {
            Mail::raw($text, function ($message) use ($subject, $replyTo) {
                $message->from('no-reply@bodwell.edu', 'Bodwell High School')
                        ->to([
                            'chanho.lee@bodwell.edu',
                            'rommel.parungao@bodwell.edu',
                        ])
                        ->subject('[MyBodwellApp] ' . $subject);

                // Reply-to the student when the account has an email, so a reply goes straight back.
                if ($replyTo) {
                    $message->replyTo($replyTo);
                }
            });

            return response()->json([
                'status'  => true,
                'message' => 'Your message has been sent.',
            ]);
        } catch (\Throwable $e) {
            // You can Log::error($e) if you want, but keeping it minimal
            return response()->json([
                'status'  => false,
                'message' => 'Could not send your message. Please try again.',
            ], 500);
        }
    }
}
