<?php

namespace App\Consent\Via;

use App\Consent\ConsentChannel;

/**
 * Ask ViA to send an OTP for one channel of one address. The address is
 * held only in memory for the transport.
 */
final readonly class ViaOtpStartRequest
{
    public function __construct(public string $reference, public ConsentChannel $channel, public string $address) {}
}
