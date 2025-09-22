<?php

use App\Http\Controllers\AcademicController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\CommunityController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ReactionController;
use App\Http\Controllers\StudentLifeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:10,1')->post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function() {

    Route::prefix('auth')->group(function() {
        Route::get('/me', [AuthController::class, 'me']);

        Route::post('/logout', [AuthController::class, 'logout']);
    });

    Route::get('get-announcements', [AnnouncementController::class, 'getAnnouncements']);

    // Academics
    Route::get('get-academics', [AcademicController::class, 'getAcademics']);
    Route::post('get-academics/details', [AcademicController::class, 'getDetails']);

    // Student Life
    Route::get('get-student-life/{semesterId?}', [StudentLifeController::class, 'getStudentLife']);

    // Community
    Route::prefix('community')->group(function() {
        Route::get('/feed', [FeedController::class, 'index']);              // public/school feed
        Route::get('/posts/{post}', [PostController::class, 'show']);

        Route::post('/posts', [PostController::class, 'store']);
        Route::post('/posts/{post}/react', [ReactionController::class, 'toggle']);
        Route::post('/posts/{post}/comments', [CommentController::class, 'store']);


        Route::get('/instagram', [CommunityController::class, 'getInstagramPosts']);
    });

    // Calendar
    Route::get('calendar/events', [CalendarController::class, 'index']);
});
