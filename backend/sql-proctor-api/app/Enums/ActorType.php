<?php

namespace App\Enums;

enum ActorType: string
{
    case Admin = 'admin';
    case Examiner = 'examiner';
    case Student = 'student';
    case System = 'system';
    case Anonymous = 'anonymous';
}
