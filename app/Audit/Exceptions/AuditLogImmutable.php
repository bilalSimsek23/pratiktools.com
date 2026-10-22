<?php

namespace App\Audit\Exceptions;

use LogicException;

final class AuditLogImmutable extends LogicException
{
    public function __construct()
    {
        parent::__construct('Audit log entries are append-only and cannot be changed or deleted.');
    }
}
