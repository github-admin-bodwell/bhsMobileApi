<?php

namespace App\Http\Controllers;

use App\Models\Parents;
use App\Models\Semesters;
use App\Models\Students;
use App\Traits\HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller {

    use HttpResponse;

    public function login(Request $request) {

        $validate = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required'
        ]);

        if( $validate->fails() ) {
            return $this->errorResponse('Validation Error', $validate->errors());
        }

        $role = 'parent';

        // check Parent first
        $user = Parents::where('VerifiedEmail', $request->email)->first();

        // hard code to update the pw1 in dev for Parents Only
        // if( $user->UserID === '202500076' || $user->UserID === 202500076 ) {
        //     $hashUpdate = Hash::make('bodwell');
        //     $user->PW1 = $hashUpdate;
        //     $user->save();
        // }

        if( !$user ) {
            // check Student
            $user = Students::where('SchoolEmail', $request->email)->first();
            $role = 'student';

            if( $user && ($user->HashPassword === '' || $user->HashPassword === null) ) {
                // hash students password
                $hashed = Hash::make($user->Password);

                $user->HashPassword = $hashed;
                $user->save(); // save hashed password
            }
        }

        // then verify password
        Log::debug("User Logs", ['user'=>$user]);
        if(!$user || !Hash::check($request->password, $user->getAuthPassword()) ) {
            return $this->errorResponse('Email Address or Password is invalid!');
        }

        $abilities = $role === 'parent'
                            ? [ 'parent:*' ]
                            : [ 'student:*' ];

        $user->tokens()->delete(); // delete all tokens of the user

        $token = $user->createToken('mobile', $abilities, now()->addDay());

        // current SemesterData
        $currentSemester = Semesters::getCurrentSemester([ 'SemesterID', 'SemesterName', 'FExam1', 'FExam2', 'MidCutOffDate' ]);

        return $this->successResponse(
            'Successfully Logged In',
            [
                'user' => [
                    'studentId' => isset($user->StudentID) ? $user->StudentID : $user->UserID,
                    'firstname' => $user->FirstName,
                    'lastname' => $user->LastName,
                    'role' => $role
                ],
                'semester' => $currentSemester,
                '__t' => $token->plainTextToken
            ]
        );
    }

    public function me(Request $request) {
        $user = $request->user();
        $role = $user instanceof Parents ? 'parent' : 'student';

        return $this->successResponse(
            'Successfully Logged In',
            [
                'user' => [
                    'studentId' => isset($user->StudentID) ? $user->StudentID : $user->UserID,
                    'firstname' => $user->FirstName,
                    'lastname' => $user->LastName,
                    'role' => $role
                ]
            ]
        );
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        return $this->successResponse(
            'Success'
        );
    }

    public function changePassword(Request $request) {
        $user = $request->user();
        $studentId = $user->StudentID ?? $user->UserID;
        $role = $user instanceof Parents ? 'parent' : 'student';

        Log::info('Change Password attempt', ['role'=>$role, 'studentId'=>$studentId, 'now'=>now()]);

        $validate = Validator::make($request->all(), [
            'oldPassword' => 'required',
            'newPassword' => 'required|min:8',
            'confirmPassword' => 'required|same:newPassword'
        ]);

        if( $validate->fails() ) {
            Log::info('Change Password attempt form error', ['role'=>$role, 'studentId'=>$studentId]);
            return $this->errorResponse('Validation Error', $validate->errors());
        }

        if( !Hash::check($request->oldPassword, $user->getAuthPassword()) ) {
            Log::info('Change Password attempt failed', ['role'=>$role, 'studentId'=>$studentId]);
            return $this->errorResponse('Old password does not match!');
        }

        $hashUpdate = Hash::make($request->newPassword);

        if( $role === 'parent' ) {
            $user->PW1 = $hashUpdate;
            $user->PW2 = $request->newPassword;
            $user->save();
            Log::info('Change Password save', ['date'=>now(), 'studentId'=>$studentId, 'fortherecord' => $request->newPassword]);
        }

        if( $role === 'student' ) {
            $user->HashPassword = $hashUpdate;
            $user->save();
            Log::info('Change Password save', ['date'=>now(), 'studentId'=>$studentId, 'fortherecord' => $request->newPassword]);
        }

        // Send an email to notify the user for the changed password

        return $this->successResponse(
            'Successfully Updated Password',
        );
    }

}
