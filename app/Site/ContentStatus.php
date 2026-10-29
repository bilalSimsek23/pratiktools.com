<?php

namespace App\Site;

enum ContentStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Taslak',
            self::Published => 'Yayında',
            self::Archived => 'Arşiv',
        };
    }
}
