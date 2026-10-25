<?php

namespace App\Campaigns;

use InvalidArgumentException;

final class CampaignError extends InvalidArgumentException
{
    /**
     * @param  list<string>  $codes
     */
    public function __construct(public readonly string $reason, string $message, public readonly array $codes = [])
    {
        parent::__construct($message);
    }
}
