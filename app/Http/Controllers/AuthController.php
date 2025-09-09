<?php

namespace App\Http\Controllers;

use App\Models\Parents;
use App\Models\Semesters;
use App\Models\Students;
use App\Traits\HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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

        if( !$user ) {
            // check Student
            $user = Students::where('SchoolEmail', $request->email)->first();
            $role = 'student';
        }

        // then verify password
        if(!$user || !Hash::check($request->password, $user->getAuthPassword()) ) {
            return $this->errorResponse('Credentials Mismatched');
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
                    'lastname' => $user->LastName
                ]
            ]
        ); 
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['message' => 'Logged out']);
    }

}