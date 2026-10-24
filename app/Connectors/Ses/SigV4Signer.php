<?php

namespace App\Connectors\Ses;

use Carbon\CarbonImmutable;

/**
 * AWS Signature Version 4 for JSON REST calls (SES API v2: endpoint
 * email.{region}.amazonaws.com, signing name "ses"). Signs host,
 * x-amz-date and x-amz-content-sha256; the path is expected with each
 * segment already URI-encoded once and is encoded again for the canonical
 * request, as SigV4 requires for every service except S3.
 */
final class SigV4Signer
{
    public const ALGORITHM = 'AWS4-HMAC-SHA256';

    /**
     * @param  array<string, string>  $query
     * @return array<string, string> headers to send
     */
    public static function sign(
        string $method,
        string $host,
        string $path,
        array $query,
        string $body,
        string $region,
        string $service,
        #[\SensitiveParameter] string $accessKeyId,
        #[\SensitiveParameter] string $secretAccessKey,
        ?CarbonImmutable $now = null,
    ): array {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $amzDate = $now->format('Ymd\THis\Z');
        $date = $now->format('Ymd');
        $payloadHash = hash('sha256', $body);

        $headers = ['host' => $host, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $amzDate];
        ksort($headers);
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name.':'.trim($value)."\n";
        }
        $signedHeaders = implode(';', array_keys($headers));

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            self::canonicalPath($path),
            self::canonicalQuery($query),
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $scope = "{$date}/{$region}/{$service}/aws4_request";
        $stringToSign = implode("\n", [self::ALGORITHM, $amzDate, $scope, hash('sha256', $canonicalRequest)]);

        $key = hash_hmac('sha256', $date, 'AWS4'.$secretAccessKey, true);
        $key = hash_hmac('sha256', $region, $key, true);
        $key = hash_hmac('sha256', $service, $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);
        $signature = hash_hmac('sha256', $stringToSign, $key);

        return [
            'Authorization' => self::ALGORITHM." Credential={$accessKeyId}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}",
            'X-Amz-Date' => $amzDate,
            'X-Amz-Content-Sha256' => $payloadHash,
        ];
    }

    public static function canonicalPath(string $path): string
    {
        $segments = explode('/', $path === '' ? '/' : $path);

        return implode('/', array_map(fn (string $s) => rawurlencode($s), $segments));
    }

    /**
     * @param  array<string, string>  $query
     */
    public static function canonicalQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $k => $v) {
            $pairs[rawurlencode((string) $k)] = rawurlencode((string) $v);
        }
        ksort($pairs, SORT_STRING);

        return implode('&', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($pairs), $pairs));
    }
}
