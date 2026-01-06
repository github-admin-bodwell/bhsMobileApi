<?php

namespace App\Http\Controllers;

use App\Models\UserAuth;
use App\Models\StudentAuth;
use App\Models\Semesters;
use App\Models\Students;
use App\Traits\HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller {

    use HttpResponse;

    public function login(Request $request) {

        $validate = Validator::make($request->all(), [
            'email' => 'required',
            'password' => 'required',
            'role' => 'required'
        ]);

        if( $validate->fails() ) {
            return $this->errorResponse('Validation Error', $validate->errors());
        }

        $role = $request->role ?? 'parent';

        // check Parent first
        // Added ability to use PEN ID
        if( $role === 'parent' ) {
            if(filter_var($request->email, FILTER_VALIDATE_EMAIL)) {
                $user = UserAuth::where('VerifiedEmail', $request->email)->first();
            } else {
                // Parents uses PEN ID
                $user = UserAuth::where('LoginIDParent', $request->email)->first();
            }
        } else {
            // student
            $user = StudentAuth::where('LoginID', $request->email)->first();
        }

        $isBoarding = false;
        if( $role === 'student' && $user ) {
            $this->processStudentLogin($user);

            // get Residence from tblBHSHomestay
            $residence = DB::table('tblBHSHomestay')->select('Residence')->where('StudentID', $user->UserID)->first();
            if( $residence && $residence->Residence === 'Y' ) {
                $isBoarding = true;
            }
        }


        if( $role === 'parent' && $user ) {
            $this->processParentLogin($user);
        }

        if(!$user || !Hash::check($request->password, $user->getAuthPassword()) ) {
            return $this->errorResponse('Login is invalid!');
        }

        // then verify password
        Log::debug("User Logs", ['user'=>$user]);

        $abilities = $role === 'parent'
                            ? [ 'parent:*' ]
                            : [ 'student:*' ];

        $user->tokens()->delete(); // delete all tokens of the user

        $token = $user->createToken('mobile', $abilities, now()->addDay());

        // current SemesterData
        $currentSemester = Semesters::getCurrentSemester(
            [ 'SemesterID', 'SemesterName', 'FExam1', 'FExam2', 'MidCutOffDate', 'StartDate', 'EndDate' ]
        );

        $today = date('Y-m-d');
        $progressText = 'no_current_term';

        if( $today < $currentSemester->StartDate ) {
            $progressText = 'term_not_started';
        } else if( $today >= $currentSemester->StartDate && $today < $currentSemester->MidCutOffDate ) {
            $progressText = 'first_half';
        } else if( $today >= $currentSemester->MidCutOffDate && $today <= $currentSemester->EndDate ) {
            $progressText = 'second_half';
        } else {
            $progressText = 'end_of_term';
        }

        // add text to currentSemester
        $currentSemester->progressText = $progressText;

        return $this->successResponse(
            'Successfully Logged In',
            [
                'user' => [
                    'studentId' => isset($user->StudentID) ? $user->StudentID : $user->UserID,
                    'firstname' => $user->FirstName,
                    'lastname' => $user->LastName,
                    'role' => $role,
                    'boarding' => $isBoarding
                ],
                'semester' => $currentSemester,
                '__t' => $token->plainTextToken
            ]
        );
    }

    public function syncPassword(Request $request) {
        $validate = Validator::make($request->all(), [
            'studentId' => 'required',
            'password' => 'required',
            'role' => 'required'
        ]);

        if( $validate->fails() ) {
            return $this->errorResponse('Validation Error', $validate->errors());
        }

        $role = $request->role;

        if( $role === 'student' ) {
            $student = StudentAuth::where('UserID', $request->studentId)->first();

            if( !$student ) {
                return $this->errorResponse('Student not found');
            }

            $student->PW1 = Hash::make($request->password);
            $student->PW3 = $request->password;
            $student->save();
            Log::info("[Student Portal] Password change request", ['studentId'=>$request->studentId, 'password'=>$request->password]);
        } else if( $role === 'parent' ) {
            $parent = UserAuth::where('LoginIDParent', $request->studentId)->first(); // PEN ID

            if( !$parent ) {
                return $this->errorResponse('Parent not found');
            }

            $parent->HashPassword = Hash::make($request->password);
            $parent->PW2 = $request->password;
            $parent->save();
            Log::info("[Parent Portal] Password change request", ['PEN ID'=>$request->studentId, 'password'=>$request->password]);
        } else {
            return $this->errorResponse('Invalid role');
        }

        return $this->successResponse('Password updated');
    }

    public function portalLogin(Request $request) {

        $validate = Validator::make($request->all(), [
            'email' => 'required',
            'password' => 'required',
            'role' => 'required'
        ]);

        if( $validate->fails() ) {
            return $this->errorResponse('Validation Error', $validate->errors());
        }

        $role = $request->role ?? 'parent';

        if( $role === 'parent' ) {
            if(filter_var($request->email, FILTER_VALIDATE_EMAIL)) {
                $user = UserAuth::where('VerifiedEmail', $request->email)->first();
            } else {
                $user = UserAuth::where('LoginIDParent', $request->email)->first();
            }
        } else {
            $user = StudentAuth::where('LoginID', $request->email)->first();
        }

        if( $role === 'student' && $user ) {
            $this->processStudentLogin($user);
        }

        if( $role === 'parent' && $user ) {
            $this->processParentLogin($user);
        }

        $isValid = $user && Hash::check($request->password, $user->getAuthPassword());

        return $this->successResponse('Success', ['authenticated' => $isValid]);
    }

    private function processParentLogin($user) {
        // check HashPassword first
        if( !$user->HashPassword || $user->HashPassword === null) {
            // create a hashpassword we can use and use their PW2 password
            $hashPW2 = Hash::make($user->PW2);
            //then update $user
            $user->HashPassword = $hashPW2;
            $user->save();
        }
        Log::info('[Parent Login] - Parent password successfully hashed');
    }

    private function processStudentLogin($user) {
        $hashPW3 = Hash::make($user->PW3);
        $user->PW1 = $hashPW3;
        $user->save();
        Log::info('[Student Login] - Student password successfully hashed');
    }

    public function me(Request $request) {
        $user = $request->user();
        $role = $user instanceof UserAuth ? 'parent' : 'student';
        $isBoarding = false;

        if ($role === 'student' && $user) {
            $residence = DB::table('tblBHSHomestay')
                ->select('Residence')
                ->where('StudentID', $user->StudentID ?? $user->UserID)
                ->first();

            if ($residence && $residence->Residence === 'Y') {
                $isBoarding = true;
            }
        }
        // current SemesterData
        $currentSemester = Semesters::getCurrentSemester([ 'SemesterID', 'SemesterName', 'FExam1', 'FExam2', 'MidCutOffDate', 'StartDate', 'EndDate' ]);

        $today = date('Y-m-d');
        $progressText = 'no_current_term';

        if( $today < $currentSemester->StartDate ) {
            $progressText = 'term_not_started';
        } else if( $today >= $currentSemester->StartDate && $today < $currentSemester->MidCutOffDate ) {
            $progressText = 'first_half';
        } else if( $today >= $currentSemester->MidCutOffDate && $today <= $currentSemester->EndDate ) {
            $progressText = 'second_half';
        } else {
            $progressText = 'end_of_term';
        }

        // add text to currentSemester
        $currentSemester->progressText = $progressText;

        return $this->successResponse(
            'Successfully Logged In',
            [
                'user' => [
                    'studentId' => isset($user->StudentID) ? $user->StudentID : $user->UserID,
                    'firstname' => $user->FirstName,
                    'lastname' => $user->LastName,
                    'role' => $role,
                    'boarding' => $isBoarding
                ],
                'semester' => $currentSemester,
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
        $role = $user instanceof UserAuth ? 'parent' : 'student';

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
            if( $user instanceof StudentAuth ) {
                $user->PW1 = $hashUpdate;
                $user->PW3 = $request->newPassword;
            } else {
                $user->HashPassword = $hashUpdate;
            }
            $user->save();
            Log::info('Change Password save', ['date'=>now(), 'studentId'=>$studentId, 'fortherecord' => $request->newPassword]);
        }

        // Send an email to notify the user for the changed password

        return $this->successResponse(
            'Successfully Updated Password',
        );
    }

}
