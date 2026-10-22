<?php

namespace App\Tenancy\Exceptions;

use RuntimeException;

final class TenantMismatch extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The record belongs to a different tenant than the current context.');
    }
}
