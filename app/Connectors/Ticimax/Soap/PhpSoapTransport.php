<?php

namespace App\Connectors\Ticimax\Soap;

use Illuminate\Support\Facades\Context;
use SoapClient;

/**
 * ext-soap transport. One SoapClient per service, WSDL cached on disk and
 * in memory, TLS verified, request/response tracing off unless explicitly
 * enabled outside production.
 */
final class PhpSoapTransport implements SoapTransport
{
    /** @var array<string, SoapClient> */
    private array $clients = [];

    public function __construct(
        private readonly string $serviceBaseUrl,
        private readonly int $connectTimeout = 15,
        private readonly int $readTimeout = 60,
        private readonly bool $trace = false,
    ) {}

    public function call(string $service, string $operation, array $parameters): array
    {
        $previousTimeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', (string) $this->readTimeout);

        try {
            $result = $this->client($service)->__soapCall($operation, [$parameters]);
        } finally {
            ini_set('default_socket_timeout', (string) $previousTimeout);
        }

        $decoded = json_decode((string) json_encode($result), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function client(string $service): SoapClient
    {
        return $this->clients[$service] ??= new SoapClient(rtrim($this->serviceBaseUrl, '/').'/'.$service.'.svc?wsdl', [
            'exceptions' => true,
            'trace' => $this->trace && ! app()->isProduction(),
            'cache_wsdl' => WSDL_CACHE_BOTH,
            'connection_timeout' => $this->connectTimeout,
            'keep_alive' => true,
            'compression' => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP,
            'user_agent' => 'MarketingAutomation/1.0',
            'stream_context' => stream_context_create([
                'http' => [
                    'timeout' => $this->readTimeout,
                    'header' => 'X-Request-Id: '.(Context::get('request_id') ?? ''),
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]),
        ]);
    }
}
