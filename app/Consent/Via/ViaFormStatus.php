<?php

namespace App\Consent\Via;

/**
 * Status of the tenant's ViA form at İYS, as recorded by an authorized
 * person after seeing it in the İYS portal. Only an approved form may be
 * used for production consent acquisition.
 */
enum ViaFormStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Disabled = 'disabled';

    /**
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Draft => [self::PendingApproval],
            self::PendingApproval => [self::Approved, self::Rejected],
            self::Approved => [self::Disabled],
            self::Rejected, self::Disabled => [self::PendingApproval],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Taslak',
            self::PendingApproval => 'Onay bekliyor',
            self::Approved => 'Onaylandı',
            self::Rejected => 'Reddedildi',
            self::Disabled => 'Devre dışı',
        };
    }
}
