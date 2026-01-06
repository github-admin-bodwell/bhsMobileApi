<?php

use App\Http\Controllers\AcademicController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AzureAuthController;
use App\Http\Controllers\ByodController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\CommunityController;
use App\Http\Controllers\DeviceTokenController;
use App\Http\Controllers\FbAuthController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ReactionController;
use App\Http\Controllers\StudentLifeController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\FormsController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\LeaveRequestController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/up', function () {
    return response()->json([
        'status' => 'ok',
        'time' => now()->toIso8601String(),
    ]);
});

Route::middleware('throttle:10,1')->post('/auth/login', [AuthController::class, 'login']);
Route::middleware('throttle:10,1')->post('/auth/sync-password', [AuthController::class, 'syncPassword']);
Route::middleware('throttle:10,1')->post('/auth/portal-login', [AuthController::class, 'portalLogin']);
Route::middleware('throttle:10,1')->post('/auth/device/issue',  [DeviceTokenController::class, 'issue']); // public: uses deviceToken hash
Route::middleware('throttle:10,1')->get('/auth/azure-redirect', [AzureAuthController::class, 'redirect']);

Route::middleware(['auth:sanctum', 'as.json'])->group(function() {

    Route::prefix('auth')->group(function() {
        Route::get('/me', [AuthController::class, 'me']);

        Route::post('/device/enable', [DeviceTokenController::class, 'enable']);
        Route::post('/device/disable',[DeviceTokenController::class, 'disable']);

        Route::post('/logout', [AuthController::class, 'logout']);
    });

    // Home
    Route::get('get-announcements', [AnnouncementController::class, 'getAnnouncements']);
    Route::get('get-student-info', [StudentController::class, 'getStudentInfo']);

    // Academics
    Route::get('get-academics', [AcademicController::class, 'getAcademics']);
    Route::get('get-academics/attendance', [AcademicController::class, 'getAttendance']);
    Route::post('get-academics/details', [AcademicController::class, 'getDetails']);

    // Student Life
    Route::get('get-student-life/{semesterId?}', [StudentLifeController::class, 'getStudentLife']);

    // Community
    Route::prefix('community')->group(function() {
        Route::middleware('throttle:10,1')->get('/posts', [FeedController::class, 'listPosts']);
        // Route::get('/posts/{post}', [PostController::class, 'show']);
        // Route::post('/posts', [PostController::class, 'store']);
        // Route::post('/posts/{post}/react', [ReactionController::class, 'toggle']);
        // Route::post('/posts/{post}/comments', [CommentController::class, 'store']);


        Route::get('/instagram', [CommunityController::class, 'getInstagramPosts']);
    });

    // Calendar
    Route::get('calendar/events', [CalendarController::class, 'index']);

    // Settings
    Route::post('settings/change-password', [AuthController::class, 'changePassword']);

    // Staff Involved
    Route::get('get-staff-involved/{semesterId?}', [StaffController::class, 'getStaffInvolved']);

    // Forms
    Route::post('forms/submit', [FormsController::class, 'submit']);

    // Leave Requests
    Route::get('get-leave-requests', [LeaveRequestController::class, 'index']);
    Route::post('add-leave-request', [LeaveRequestController::class, 'store']);

    // BYOD
    Route::get('get-byod-voucher', [ByodController::class, 'voucher']);

});
