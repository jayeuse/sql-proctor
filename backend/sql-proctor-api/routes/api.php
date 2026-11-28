<?php

use App\Http\Controllers\Api\Admin\AdminExamController;
use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\ExaminerController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Examiner\ClassroomController;
use App\Http\Controllers\Api\Examiner\ClassroomExamController;
use App\Http\Controllers\Api\Examiner\DatasetController;
use App\Http\Controllers\Api\Examiner\ExamAttemptGradingController;
use App\Http\Controllers\Api\Examiner\ExamController;
use App\Http\Controllers\Api\Examiner\ExamQuestionController;
use App\Http\Controllers\Api\Student\StudentExamController;
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

            Route::get('/exams', [AdminExamController::class, 'index']);
            Route::get('/exams/{exam}', [AdminExamController::class, 'show'])->whereUuid('exam');
            Route::post('/exams/{exam}/force-unpublish', [AdminExamController::class, 'forceUnpublish'])
                ->whereUuid('exam');

            Route::get('/datasets', [DatasetController::class, 'index']);
            Route::post('/datasets', [DatasetController::class, 'store']);
            Route::get('/datasets/{dataset}', [DatasetController::class, 'show'])->whereUuid('dataset');
            Route::patch('/datasets/{dataset}', [DatasetController::class, 'update'])->whereUuid('dataset');
            Route::delete('/datasets/{dataset}', [DatasetController::class, 'destroy'])->whereUuid('dataset');
            Route::post('/datasets/{dataset}/validate', [DatasetController::class, 'validateSchema'])
                ->whereUuid('dataset');
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

        Route::get('/datasets', [DatasetController::class, 'index']);
        Route::post('/datasets', [DatasetController::class, 'store']);
        Route::get('/datasets/{dataset}', [DatasetController::class, 'show'])->whereUuid('dataset');
        Route::patch('/datasets/{dataset}', [DatasetController::class, 'update'])->whereUuid('dataset');
        Route::delete('/datasets/{dataset}', [DatasetController::class, 'destroy'])->whereUuid('dataset');
        Route::post('/datasets/{dataset}/validate', [DatasetController::class, 'validateSchema'])
            ->whereUuid('dataset');

        Route::get('/exams', [ExamController::class, 'index']);
        Route::post('/exams', [ExamController::class, 'store']);
        Route::get('/exams/{exam}', [ExamController::class, 'show'])->whereUuid('exam');
        Route::patch('/exams/{exam}', [ExamController::class, 'update'])->whereUuid('exam');
        Route::delete('/exams/{exam}', [ExamController::class, 'destroy'])->whereUuid('exam');
        Route::post('/exams/{exam}/validate', [ExamController::class, 'validateExam'])->whereUuid('exam');
        Route::post('/exams/{exam}/publish', [ExamController::class, 'publish'])->whereUuid('exam');
        Route::post('/exams/{exam}/unpublish', [ExamController::class, 'unpublish'])->whereUuid('exam');
        Route::post('/exams/{exam}/archive', [ExamController::class, 'archive'])->whereUuid('exam');
        Route::post('/exams/{exam}/clone', [ExamController::class, 'cloneExam'])->whereUuid('exam');

        Route::post('/exams/{exam}/questions', [ExamQuestionController::class, 'store'])->whereUuid('exam');
        Route::put('/exams/{exam}/questions/reorder', [ExamQuestionController::class, 'reorder'])->whereUuid('exam');
        Route::patch('/exams/{exam}/questions/{question}', [ExamQuestionController::class, 'update'])
            ->whereUuid(['exam', 'question']);
        Route::delete('/exams/{exam}/questions/{question}', [ExamQuestionController::class, 'destroy'])
            ->whereUuid(['exam', 'question']);

        Route::get('/exams/{exam}/classrooms', [ClassroomExamController::class, 'index'])->whereUuid('exam');
        Route::post('/exams/{exam}/classrooms', [ClassroomExamController::class, 'store'])->whereUuid('exam');
        Route::patch('/exams/{exam}/classrooms/{classroom}', [ClassroomExamController::class, 'update'])
            ->whereUuid(['exam', 'classroom']);
        Route::delete('/exams/{exam}/classrooms/{classroom}', [ClassroomExamController::class, 'destroy'])
            ->whereUuid(['exam', 'classroom']);

        Route::get('/exams/{exam}/attempts', [ExamAttemptGradingController::class, 'index'])->whereUuid('exam');
        Route::get('/attempts/{attempt}', [ExamAttemptGradingController::class, 'show'])->whereUuid('attempt');
        Route::patch('/attempts/{attempt}/answers/{answer}/grade', [ExamAttemptGradingController::class, 'grade'])
            ->whereUuid(['attempt', 'answer']);
        Route::post('/attempts/{attempt}/finalize', [ExamAttemptGradingController::class, 'finalize'])
            ->whereUuid('attempt');
    });
});

Route::middleware([EnsureStatefulAuthSession::class])
    ->prefix('student')
    ->group(function (): void {
        Route::post('/join', [StudentSessionController::class, 'join']);

        Route::middleware(['student.session'])->group(function (): void {
            Route::get('/session', [StudentSessionController::class, 'show']);

            Route::get('/exams', [StudentExamController::class, 'exams']);
            Route::post('/exams/{classroomExam}/start', [StudentExamController::class, 'start'])
                ->whereUuid('classroomExam');
            Route::get('/attempts/{attempt}', [StudentExamController::class, 'show'])
                ->whereUuid('attempt');
            Route::put('/attempts/{attempt}/answers/{questionId}', [StudentExamController::class, 'saveAnswer'])
                ->whereUuid(['attempt', 'questionId']);
            Route::post('/attempts/{attempt}/questions/{questionId}/run', [StudentExamController::class, 'runQuery'])
                ->whereUuid(['attempt', 'questionId']);
            Route::post('/attempts/{attempt}/submit', [StudentExamController::class, 'submit'])
                ->whereUuid('attempt');
            Route::get('/attempts/{attempt}/result', [StudentExamController::class, 'result'])
                ->whereUuid('attempt');
        });

        Route::post('/leave', [StudentSessionController::class, 'leave'])
            ->middleware(['student.session:allow-closed']);
    });
