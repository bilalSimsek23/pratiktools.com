<?php

namespace App\Consent;

enum ConsentChannel: string
{
    case Email = 'EMAIL';
    case Sms = 'SMS';
    case Call = 'CALL';
    // Push permission comes from the device subscription (OneSignal), never
    // from İYS.
    case Push = 'PUSH';

    public function addressType(): ?string
    {
        return match ($this) {
            self::Email => 'email',
            self::Sms, self::Call => 'phone',
            self::Push => null,
        };
    }
}
