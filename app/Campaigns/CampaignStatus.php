<?php

namespace App\Campaigns;

/**
 * draft → ready → scheduled ⇄ paused; completed only by a dispatcher
 * (none in F6); cancelled/completed/draft → archived. "scheduled" is an
 * intention, never a provider send in F6.
 */
enum CampaignStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Scheduled = 'scheduled';
    case Paused = 'paused';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Archived = 'archived';

    public function canBecome(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Draft => [self::Ready, self::Cancelled, self::Archived],
            self::Ready => [self::Scheduled, self::Cancelled],
            self::Scheduled => [self::Paused, self::Cancelled],
            self::Paused => [self::Scheduled, self::Cancelled],
            self::Completed, self::Cancelled => [self::Archived],
            self::Archived => [],
        }, true);
    }
}
