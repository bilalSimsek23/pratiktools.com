<?php

namespace App\Console\Commands;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Connectors\Ses\SesCredentials;
use App\Console\Commands\Concerns\RunsInTenantContext;
use App\Models\TenantIntegration;
use App\Security\Vault\SecretVault;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\StreamableInputInterface;

/**
 * Creates or updates a tenant's Amazon SES integration. The IAM access key
 * pair is read from STDIN only (two lines: access key id, secret access
 * key) and stored in the vault. Scopes start read-only; sending needs
 * `email:ses scope --grant=send_email` and EMAIL_SENDING_MODE.
 */
final class ConnectSes extends Command
{
    use RunsInTenantContext;

    protected $signature = 'email:connect-ses {--tenant= : Tenant slug} {--region= : AWS region, e.g. eu-central-1} {--configuration-set= : SES configuration set name}
        {--sns-topic-arn=* : SNS topic ARN(s) allowed to deliver SES events} {--role=primary : primary|secondary} {--secret-stdin : Read "ACCESS_KEY_ID\nSECRET_ACCESS_KEY" from STDIN}';

    protected $description = 'Connect a tenant to Amazon SES (read-only scope; sending stays off)';

    public function handle(SecretVault $vault, AuditLogger $audit): int
    {
        $region = strtolower(trim((string) ($this->option('region') ?: config('email.ses.default_region'))));
        if (! SesCredentials::validRegion($region)) {
            $this->error('Pass --region as an AWS region name.');

            return self::INVALID;
        }
        $configurationSet = $this->option('configuration-set') ?: null;
        if ($configurationSet !== null && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $configurationSet) !== 1) {
            $this->error('Invalid --configuration-set.');

            return self::INVALID;
        }
        $topics = array_values(array_unique(array_map('trim', (array) $this->option('sns-topic-arn'))));
        foreach ($topics as $arn) {
            if (preg_match('/^arn:aws(-[a-z]+)?:sns:[a-z0-9-]+:\d{12}:[A-Za-z0-9_-]{1,256}$/', $arn) !== 1) {
                $this->error('Invalid --sns-topic-arn.');

                return self::INVALID;
            }
        }
        if (! in_array($this->option('role'), ['primary', 'secondary'], true)) {
            $this->error('--role must be primary or secondary.');

            return self::INVALID;
        }

        $secret = null;
        if ($this->option('secret-stdin')) {
            $stream = $this->input instanceof StreamableInputInterface && $this->input->getStream() !== null ? $this->input->getStream() : STDIN;
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) stream_get_contents($stream)) ?: [])));
            if (count($lines) !== 2 || preg_match('/^[A-Z0-9]{16,128}$/', $lines[0]) !== 1 || strlen($lines[1]) < 16) {
                $this->error('STDIN must hold two lines: access key id, secret access key.');

                return self::INVALID;
            }
            $secret = json_encode(['access_key_id' => $lines[0], 'secret_access_key' => $lines[1]], JSON_THROW_ON_ERROR);
            unset($lines);
        }

        $integration = $this->inTenant(fn (string $tenantId) => DB::transaction(function () use ($tenantId, $region, $configurationSet, $topics, $secret, $vault, $audit) {
            $integration = TenantIntegration::query()->where('provider', 'amazon_ses')->where('capability', 'email')->first();
            $created = $integration === null;
            if ($created && $secret === null) {
                throw new \InvalidArgumentException('A new integration needs --secret-stdin.');
            }

            $integration ??= new TenantIntegration(['provider' => 'amazon_ses', 'capability' => 'email', 'status' => 'pending', 'scopes' => ['read_account', 'read_identities']]);
            $integration->tenant_id = $tenantId;
            $integration->settings = ['region' => $region, 'configuration_set' => $configurationSet, 'sns_topic_arns' => $topics, 'email_role' => $this->option('role')]
                + array_diff_key((array) $integration->settings, array_flip(['region', 'configuration_set', 'sns_topic_arns', 'email_role']));

            $previous = $integration->secret_fingerprint;
            if ($secret !== null) {
                $vault->seal($integration, $secret);
                $integration->status = 'pending';
            }
            $integration->save();

            if ($created) {
                $audit->record(AuditAction::IntegrationCreated, 'tenant_integration', $integration->id, ['provider' => 'amazon_ses', 'capability' => 'email', 'region' => $region]);
            }
            if ($secret !== null) {
                $audit->record($created ? AuditAction::IntegrationSecretCreated : AuditAction::IntegrationSecretChanged, 'tenant_integration', $integration->id, array_filter([
                    'previous_fingerprint' => $created ? null : $previous, 'fingerprint' => $integration->secret_fingerprint,
                ]));
            }

            return $integration;
        }));

        unset($secret);
        $this->info("Amazon SES integration {$integration->id} saved (region {$region}, status {$integration->status}). Run email:ses check next.");

        return self::SUCCESS;
    }
}
