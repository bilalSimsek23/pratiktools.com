<?php

namespace App\Automation;

/**
 * draft → published ⇄ paused; any → archived (final).
 */
enum AutomationStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Paused = 'paused';
    case Archived = 'archived';

    public function canBecome(self $next): bool
    {
        return match ($this) {
            self::Draft => in_array($next, [self::Published, self::Archived], true),
            self::Published => in_array($next, [self::Published, self::Paused, self::Archived], true),
            self::Paused => in_array($next, [self::Published, self::Archived], true),
            self::Archived => false,
        };
    }
}
