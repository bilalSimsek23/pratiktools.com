<?php

namespace App\Automation;

enum RunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Waiting = 'waiting';
    case Completed = 'completed';
    case Exited = 'exited';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Running, self::Waiting], true);
    }
}
