<?php

namespace Tests\Feature;

use App\Mail\SelfSubmitHoursApprovalMail;
use App\Models\StudentAuth;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Both emails the API sends must come from no-reply@bodwell.edu (the address the
 * school's SendGrid account sends as) and the contact form must reach both IT staff.
 * Uses the array mailer from phpunit.xml, so no SMTP or SQL Server is needed.
 */
class MailSenderTest extends TestCase
{
    public function test_contact_form_goes_to_both_it_staff_from_no_reply(): void
    {
        $student = new StudentAuth();
        $student->UserID = '202600081';
        Sanctum::actingAs($student);

        $response = $this->postJson('/api/forms/submit', [
            'studentId' => '202600081',
            'subject' => 'Cannot see my schedule',
            'body' => 'The class schedule page is blank.',
        ]);

        $response->assertOk()->assertJsonPath('status', true);

        $sent = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);

        $email = $sent[0]->getOriginalMessage();
        $this->assertSame('no-reply@bodwell.edu', $email->getFrom()[0]->getAddress());
        $this->assertSame(
            ['chanho.lee@bodwell.edu', 'rommel.parungao@bodwell.edu'],
            array_map(fn ($a) => $a->getAddress(), $email->getTo())
        );
        $this->assertSame('[MyBodwellApp] Cannot see my schedule', $email->getSubject());
        $this->assertStringContainsString('The class schedule page is blank.', $email->getTextBody());
    }

    public function test_approval_email_is_from_no_reply(): void
    {
        $mail = new SelfSubmitHoursApprovalMail('Test Student', 'Food Bank', 'North Van', '2026-10-23', '3', 'A Teacher');
        $mail->build();

        $this->assertTrue($mail->hasFrom('no-reply@bodwell.edu', 'Bodwell High School'));
        $this->assertTrue($mail->hasSubject('Self-submitted hours - Test Student'));
        $this->assertStringContainsString('Food Bank', $mail->render());
    }
}
