<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SelfSubmitHoursApprovalMail extends Mailable
{
    use Queueable, SerializesModels;

    public $studentName;
    public $activityName;
    public $activityLocation;
    public $activityDate;
    public $activityHours;
    public $approverName;

    public function __construct(
        string $studentName,
        string $activityName,
        string $activityLocation,
        string $activityDate,
        string $activityHours,
        string $approverName
    ) {
        $this->studentName = $studentName;
        $this->activityName = $activityName;
        $this->activityLocation = $activityLocation;
        $this->activityDate = $activityDate;
        $this->activityHours = $activityHours;
        $this->approverName = $approverName;
    }

    public function build()
    {
        return $this->from('heldesk@bodwell.edu', 'BHS IT Help Desk')
            ->subject("Self-submitted hours - {$this->studentName}")
            ->view('emails.self_submit_hours_approval');
    }
}
