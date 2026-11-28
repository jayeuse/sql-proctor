<?php

use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\ExaminerController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Examiner\ClassroomController;
use App\Http\Controllers\Api\Student\StudentSessionController;
use App\Http\Middleware\EnsureStatefulAuthSession;
use Illuminate\Support\Facades\Route;

Route::middleware([EnsureStatefulAuthSession::class])->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/logout', [AuthController::class, 'logout'])
        ->middleware(['auth:sanctum', 'active']);
});

Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
    Route::get('/user', [AuthController::class, 'user']);

    Route::middleware(['role:admin'])->group(function (): void {
        Route::prefix('admin')->group(function (): void {
            Route::get('/examiners', [ExaminerController::class, 'index']);
            Route::post('/examiners', [ExaminerController::class, 'store']);
            Route::patch('/examiners/{examiner}', [ExaminerController::class, 'update'])
                ->where('examiner', '[1-9][0-9]{0,17}');
            Route::post('/examiners/{examiner}/reset-password', [ExaminerController::class, 'resetPassword'])
                ->where('examiner', '[1-9][0-9]{0,17}');
        });

        Route::get('/audit-logs', [AuditLogController::class, 'index']);
    });

    Route::middleware(['role:examiner'])->prefix('examiner')->group(function (): void {
        Route::get('/classes', [ClassroomController::class, 'index']);
        Route::post('/classes', [ClassroomController::class, 'store']);
        Route::get('/classes/{classroom}', [ClassroomController::class, 'show'])
            ->whereUuid('classroom');
        Route::post('/classes/{classroom}/open', [ClassroomController::class, 'open'])
            ->whereUuid('classroom');
        Route::post('/classes/{classroom}/close', [ClassroomController::class, 'close'])
            ->whereUuid('classroom');
        Route::post('/classes/{classroom}/regenerate-code', [ClassroomController::class, 'regenerateCode'])
            ->whereUuid('classroom');
    });
});

Route::middleware([EnsureStatefulAuthSession::class])
    ->prefix('student')
    ->group(function (): void {
        Route::post('/join', [StudentSessionController::class, 'join']);

        Route::middleware(['student.session'])->group(function (): void {
            Route::get('/session', [StudentSessionController::class, 'show']);
        });

        Route::post('/leave', [StudentSessionController::class, 'leave'])
            ->middleware(['student.session:allow-closed']);
    });
