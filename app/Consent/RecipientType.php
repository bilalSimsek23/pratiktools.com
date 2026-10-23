<?php

namespace App\Consent;

enum RecipientType: string
{
    case Individual = 'INDIVIDUAL';
    case Merchant = 'MERCHANT';
}
