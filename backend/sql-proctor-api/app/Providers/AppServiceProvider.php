<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\SqlDataset;
use App\Models\User;
use App\Policies\AuditLogPolicy;
use App\Policies\ClassroomPolicy;
use App\Policies\ExamAttemptPolicy;
use App\Policies\ExamPolicy;
use App\Policies\SqlDatasetPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Classroom::class, ClassroomPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(SqlDataset::class, SqlDatasetPolicy::class);
        Gate::policy(Exam::class, ExamPolicy::class);
        Gate::policy(ExamAttempt::class, ExamAttemptPolicy::class);
    }
}
