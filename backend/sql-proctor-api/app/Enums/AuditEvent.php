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

    // Phase 2-4: Datasets, Exams, Questions, Execution, and Grading
    case DatasetCreated = 'dataset.created';
    case DatasetUpdated = 'dataset.updated';
    case DatasetDeleted = 'dataset.deleted';
    case DatasetValidated = 'dataset.validated';

    case ExamCreated = 'exam.created';
    case ExamUpdated = 'exam.updated';
    case ExamDeleted = 'exam.deleted';
    case ExamPublished = 'exam.published';
    case ExamUnpublished = 'exam.unpublished';
    case ExamForceUnpublished = 'exam.force_unpublished';
    case ExamArchived = 'exam.archived';
    case ExamCloned = 'exam.cloned';

    case ExamClassroomAssigned = 'exam.classroom_assigned';
    case ExamClassroomUpdated = 'exam.classroom_updated';
    case ExamClassroomUnassigned = 'exam.classroom_unassigned';
    case ExamResultsReleased = 'exam.results_released';

    case QuestionCreated = 'question.created';
    case QuestionUpdated = 'question.updated';
    case QuestionDeleted = 'question.deleted';
    case QuestionReordered = 'question.reordered';

    case StudentExamStarted = 'student.exam_started';
    case StudentAnswerSubmitted = 'student.answer_submitted';
    case StudentExamSubmitted = 'student.exam_submitted';
    case StudentExamAutoSubmitted = 'student.exam_auto_submitted';
    case StudentExamForceClosed = 'student.exam_force_closed';
    case StudentQueryBlocked = 'student.query_blocked';

    case GradingAnswerGraded = 'grading.answer_graded';
    case GradingScoreOverridden = 'grading.score_overridden';
    case AttemptFinalized = 'attempt.finalized';
}
