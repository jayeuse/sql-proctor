<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when application code attempts to update or delete an immutable audit log record.
 */
class ImmutableAuditLogException extends RuntimeException {}
