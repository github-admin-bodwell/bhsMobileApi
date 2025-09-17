<?php

use App\Http\Controllers\AcademicController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AuthController;
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
});
