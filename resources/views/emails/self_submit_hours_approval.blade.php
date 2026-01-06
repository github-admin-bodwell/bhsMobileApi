<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Self-Submit Hours Approval</title>
    <style>
        .table-mail {
            border-collapse: collapse;
            border: none;
            width: 900px;
        }
        th, td {
            border-bottom: 1px solid #d2d2d2;
        }
        .table-mail tr td {
            padding: 10px;
            font-size: 14px;
        }
        .title {
            color: #30297E;
        }
        .tableTitle {
            font-weight: bold;
            color: #66615b;
            width: 300px;
        }
        .linkContainer {
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div>
        <h3 class="title">
            Student Portal System Notification<br>
        </h3>
        <br>
        Student self-submitted hours pending your approval.<br>
        <br>
        <br>
        <table class="table-mail">
            <tr>
                <td class="tableTitle">Student Name</td>
                <td colspan="3">{{ $studentName }}</td>
            </tr>
            <tr>
                <td class="tableTitle">Activity Name</td>
                <td colspan="3">{{ $activityName }}</td>
            </tr>
            <tr>
                <td class="tableTitle">Location</td>
                <td colspan="3">{{ $activityLocation }}</td>
            </tr>
            <tr>
                <td class="tableTitle">Date &amp; Hours</td>
                <td>{{ $activityDate }} <span>({{ $activityHours }} Hrs)</span></td>
                <td></td>
                <td></td>
            </tr>
            <tr>
                <td class="tableTitle">Approval Status</td>
                <td colspan="3" style="color:#F44336">Pending Approval</td>
            </tr>
            <tr>
                <td class="tableTitle">Approver</td>
                <td colspan="3">{{ $approverName }}</td>
            </tr>
        </table>
    </div>
    <div class="linkContainer">
        Please sign-in to your SP Admin to approve hours.
    </div>
</body>
</html>
