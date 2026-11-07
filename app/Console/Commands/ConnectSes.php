<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Console\Commands\Concerns\RunsInTenantContext;
use App\Email\SesIntegrations;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Input\StreamableInputInterface;

/**
 * Creates or updates a tenant's Amazon SES integration. The IAM access key
 * pair is read from STDIN only (two lines: access key id, secret access
 * key) and stored in the vault. Scopes start read-only; sending needs
 * `email:ses scope --grant=send_email` and EMAIL_SENDING_MODE.
 *
 * Break-glass / development only: Super Admin › Entegrasyonlar › Amazon SES
 * does the same through SesIntegrations.
 */
final class ConnectSes extends Command
{
    use RunsInTenantContext;

    protected $signature = 'email:connect-ses {--tenant= : Tenant slug} {--region= : AWS region, e.g. eu-central-1} {--configuration-set= : SES configuration set name}
        {--sns-topic-arn=* : SNS topic ARN(s) allowed to deliver SES events} {--role=primary : primary|secondary} {--secret-stdin : Read "ACCESS_KEY_ID\nSECRET_ACCESS_KEY" from STDIN}';

    protected $description = 'Connect a tenant to Amazon SES (read-only scope; sending stays off)';

    private const ERRORS = [
        'INVALID_REGION' => 'Pass --region as an AWS region name.',
        'INVALID_CONFIGURATION_SET' => 'Invalid --configuration-set.',
        'INVALID_TOPIC_ARN' => 'Invalid --sns-topic-arn.',
        'TOPIC_REGION_MISMATCH' => 'The SNS topic must be in the SES region.',
        'INVALID_ROLE' => '--role must be primary or secondary.',
        'KEY_PAIR_REQUIRED' => 'A new integration needs --secret-stdin.',
        'KEY_PAIR_INCOMPLETE' => 'STDIN must hold two lines: access key id, secret access key.',
        'KEY_PAIR_INVALID' => 'STDIN must hold two lines: access key id, secret access key.',
    ];

    public function handle(SesIntegrations $integrations): int
    {
        $region = strtolower(trim((string) ($this->option('region') ?: config('email.ses.default_region'))));
        $accessKeyId = null;
        $secretAccessKey = null;
        if ($this->option('secret-stdin')) {
            $stream = $this->input instanceof StreamableInputInterface && $this->input->getStream() !== null ? $this->input->getStream() : STDIN;
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) stream_get_contents($stream)) ?: [])));
            if (count($lines) !== 2) {
                $this->error('STDIN must hold two lines: access key id, secret access key.');

                return self::INVALID;
            }
            [$accessKeyId, $secretAccessKey] = $lines;
            unset($lines);
        }

        try {
            $integration = $this->inTenant(fn () => $integrations->save($region, $this->option('configuration-set') ?: null, (array) $this->option('sns-topic-arn'),
                $accessKeyId, $secretAccessKey, Actor::console($this->getName()), role: (string) $this->option('role')));
        } catch (InvalidArgumentException $e) {
            $this->error(self::ERRORS[$e->getMessage()] ?? 'Invalid input.');

            return self::INVALID;
        } finally {
            unset($accessKeyId, $secretAccessKey);
        }

        $this->info("Amazon SES integration {$integration->id} saved (region {$region}, status {$integration->status}). Run email:ses check next.");

        return self::SUCCESS;
    }
}
