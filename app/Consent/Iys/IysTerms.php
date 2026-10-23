<?php

namespace App\Consent\Iys;

use App\Consent\ConsentChannel;
use App\Consent\ConsentStatus;
use App\Consent\RecipientType;
use App\Integrations\Errors\ProviderError;

/**
 * The only place İYS vocabulary meets canonical vocabulary.
 */
final class IysTerms
{
    private const CHANNELS = ['EPOSTA' => ConsentChannel::Email, 'MESAJ' => ConsentChannel::Sms, 'ARAMA' => ConsentChannel::Call];

    private const STATUSES = ['ONAY' => ConsentStatus::Granted, 'RET' => ConsentStatus::Revoked];

    private const RECIPIENT_TYPES = ['BIREYSEL' => RecipientType::Individual, 'TACIR' => RecipientType::Merchant];

    public static function channel(string $iys): ConsentChannel
    {
        return self::CHANNELS[strtoupper($iys)] ?? throw ProviderError::schema("Unknown İYS type [{$iys}].");
    }

    public static function toIysChannel(ConsentChannel $channel): string
    {
        return array_search($channel, self::CHANNELS, true) ?: throw new \InvalidArgumentException("{$channel->value} is not an İYS channel.");
    }

    public static function status(string $iys): ConsentStatus
    {
        return self::STATUSES[strtoupper($iys)] ?? throw ProviderError::schema("Unknown İYS status [{$iys}].");
    }

    public static function recipientType(string $iys): RecipientType
    {
        return self::RECIPIENT_TYPES[strtoupper($iys)] ?? throw ProviderError::schema("Unknown İYS recipient type [{$iys}].");
    }

    public static function toIysRecipientType(RecipientType $type): string
    {
        return (string) array_search($type, self::RECIPIENT_TYPES, true);
    }
}
