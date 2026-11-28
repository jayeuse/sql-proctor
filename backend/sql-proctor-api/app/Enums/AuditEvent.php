<?php

namespace App\Enums;

enum AuditEvent: string
{
    case AdminBootstrapCompleted = 'admin.bootstrap_completed';
    case AuthLoginSucceeded = 'auth.login_succeeded';
    case AuthLoginFailed = 'auth.login_failed';
    case AuthLogout = 'auth.logout';
    case AuthLockout = 'auth.lockout';
    case ExaminerCreated = 'examiner.created';
    case ExaminerUpdated = 'examiner.updated';
    case ExaminerDeactivated = 'examiner.deactivated';
    case ExaminerPasswordReset = 'examiner.password_reset';
    case ClassCreated = 'class.created';
    case ClassOpened = 'class.opened';
    case ClassClosed = 'class.closed';
    case ClassCodeRegenerated = 'class.code_regenerated';
    case StudentJoinedClass = 'student.joined_class';
    case StudentJoinFailed = 'student.join_failed';
    case StudentLeftClass = 'student.left_class';
}
