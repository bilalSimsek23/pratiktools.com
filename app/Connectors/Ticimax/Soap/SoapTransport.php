<?php

namespace App\Connectors\Ticimax\Soap;

/**
 * Executes one SOAP operation and returns the decoded result as arrays.
 * The only class that talks to the network; replaced by a fake in tests.
 */
interface SoapTransport
{
    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function call(string $service, string $operation, array $parameters): array;
}
