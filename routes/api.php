<?php

use App\Http\Controllers\AcademicController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\CommunityController;
use App\Http\Controllers\DeviceTokenController;
use App\Http\Controllers\FbAuthController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ReactionController;
use App\Http\Controllers\StudentLifeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:10,1')->post('/auth/login', [AuthController::class, 'login']);
Route::middleware('throttle:10,1')->post('/auth/device/issue',  [DeviceTokenController::class, 'issue']); // public: uses deviceToken hash

Route::get('/auth/facebook/redirect', [FbAuthController::class, 'redirect'])->name('fb.redirect');
Route::get('/auth/facebook/callback', [FbAuthController::class, 'callback'])->name('fb.callback');

Route::middleware(['auth:sanctum', 'as.json'])->group(function() {

    Route::prefix('auth')->group(function() {
        Route::get('/me', [AuthController::class, 'me']);

        Route::post('/device/enable', [DeviceTokenController::class, 'enable']);
        Route::post('/device/disable',[DeviceTokenController::class, 'disable']);

        Route::post('/logout', [AuthController::class, 'logout']);
    });

    Route::get('get-announcements', [AnnouncementController::class, 'getAnnouncements']);

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

});
