<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveRequest extends Model
{
    protected $table = 'tblBHSStudentLeaveRequest';
    protected $primaryKey = 'LeaveID';
    public $timestamps = false;

    protected $fillable = [
        'LeaveType',
        'StudentID',
        'SDate',
        'EDate',
        'Reason',
        'Comment',
        'ToDo',
        'LeaveTime',
        'LeaveStatus',
        'ModifyUserID',
        'CreateUserID',
    ];
}
