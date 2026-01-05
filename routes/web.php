<?php

use App\Http\Controllers\FbAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'Unauthorized Access.'
    ]);
});

Route::get('/auth/facebook/redirect', [FbAuthController::class, 'redirect'])->name('fb.redirect');
Route::get('/auth/facebook/callback', [FbAuthController::class, 'callback'])->name('fb.callback');
