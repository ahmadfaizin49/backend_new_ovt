<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [App\Http\Controllers\Api\AuthController::class, 'register']);
Route::post('/login', [App\Http\Controllers\Api\AuthController::class, 'login']);
Route::post('/forgot-password', [App\Http\Controllers\Api\AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [App\Http\Controllers\Api\AuthController::class, 'resetPassword']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::post('/update-password', [App\Http\Controllers\Api\AuthController::class, 'updatePassword']);
    Route::post('/request-update-email', [App\Http\Controllers\Api\AuthController::class, 'requestUpdateEmail']);
    Route::post('/verify-update-email', [App\Http\Controllers\Api\AuthController::class, 'verifyUpdateEmail']);
    Route::post('/update-gaji', [App\Http\Controllers\Api\AuthController::class, 'updateGaji']);
    Route::post('/update-profile', [App\Http\Controllers\Api\AuthController::class, 'updateProfile']);
    Route::post('/logout', [App\Http\Controllers\Api\AuthController::class, 'logout']);

    // Overtime (Lembur)
    Route::get('/overtime/dashboard', [App\Http\Controllers\Api\OvertimeController::class, 'dashboard']);
    Route::get('/overtime/overview', [App\Http\Controllers\Api\OvertimeController::class, 'overview']);
    Route::get('/overtime', [App\Http\Controllers\Api\OvertimeController::class, 'index']);
    Route::post('/overtime', [App\Http\Controllers\Api\OvertimeController::class, 'store']);
    Route::put('/overtime/{id}', [App\Http\Controllers\Api\OvertimeController::class, 'update']);
    Route::delete('/overtime/{id}', [App\Http\Controllers\Api\OvertimeController::class, 'destroy']);
});
