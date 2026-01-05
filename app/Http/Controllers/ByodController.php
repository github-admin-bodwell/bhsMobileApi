<?php

namespace App\Http\Controllers;

use App\Traits\HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ByodController extends Controller
{
    use HttpResponse;

    public function voucher(Request $request)
    {
        $user = $request->user();
        $studentId = $user->StudentID ?? $user->UserID ?? $request->input('studentId');
        if (!$studentId) {
            return $this->errorResponse('Missing studentId', [], null, 422);
        }

        $row = DB::selectOne("
            SELECT TOP 1
                Username,
                Password,
                CreateDate
            FROM tblBHSVoucher
            WHERE UserID = :studentId
            ORDER BY CreateDate DESC
        ", ['studentId' => $studentId]);

        if (!$row) {
            return $this->successResponse('No voucher', [
                'result' => 0,
                'voucher' => null,
            ]);
        }

        return $this->successResponse('Success', [
            'result' => 1,
            'voucher' => $row,
        ]);
    }
}
