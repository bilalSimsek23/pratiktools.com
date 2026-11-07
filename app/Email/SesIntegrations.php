<?php

namespace App\Email;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Connectors\Ses\SesCredentials;
use App\Models\TenantIntegration;
use App\Security\Vault\SecretVault;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates or updates the current tenant's Amazon SES integration: the one
 * place email:connect-ses and Super Admin › Entegrasyonlar › Amazon SES
 * save the region, configuration set, SNS topics, tracking and the IAM
 * access key pair.
 *
 * The key pair goes only into the vault (AES-256-GCM, bound to the tenant
 * and integration). Both parts are given together or not at all: without
 * them the stored pair is kept. A new pair (or another region) invalidates
 * the last account check, so the integration waits for a new test. Scopes
 * start read-only; send_email is a separate, explicit grant.
 */
final class SesIntegrations
{
    public const TRACKING = ['open', 'click'];

    private const SETTINGS = ['region', 'configuration_set', 'sns_topic_arns', 'tracking', 'email_role'];

    public function __construct(
        private readonly SecretVault $vault,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public function current(): ?TenantIntegration
    {
        $this->context->requireTenant();

        return TenantIntegration::query()->where('provider', 'amazon_ses')->where('capability', 'email')->first();
    }

    /**
     * Must run inside the tenant's context. $tracking and $role null keep the
     * stored values.
     *
     * @param  list<string>  $topicArns
     * @param  list<string>|null  $tracking
     *
     * @throws InvalidArgumentException invalid settings, half a key pair, or a new integration without one
     */
    public function save(string $region, ?string $configurationSet, array $topicArns, #[\SensitiveParameter] ?string $accessKeyId, #[\SensitiveParameter] ?string $secretAccessKey,
        Actor $actor, ?array $tracking = null, ?string $role = null): TenantIntegration
    {
        $region = strtolower(trim($region));
        $configurationSet = $configurationSet === null || trim($configurationSet) === '' ? null : trim($configurationSet);
        $topicArns = array_values(array_unique(array_filter(array_map(self::clean(...), $topicArns), fn (string $a) => $a !== '')));
        $secret = self::keyPair($accessKeyId, $secretAccessKey);
        unset($accessKeyId, $secretAccessKey);

        if (! SesCredentials::validRegion($region)) {
            throw new InvalidArgumentException('INVALID_REGION');
        }
        if ($configurationSet !== null && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $configurationSet) !== 1) {
            throw new InvalidArgumentException('INVALID_CONFIGURATION_SET');
        }
        foreach ($topicArns as $arn) {
            if (! self::validTopicArn($arn)) {
                throw new InvalidArgumentException('INVALID_TOPIC_ARN');
            }
            // SES publishes events only to a topic in the configuration set's own region.
            if (explode(':', $arn)[3] !== $region) {
                throw new InvalidArgumentException('TOPIC_REGION_MISMATCH');
            }
        }
        if ($tracking !== null && array_diff($tracking, self::TRACKING) !== []) {
            throw new InvalidArgumentException('INVALID_TRACKING');
        }
        if ($role !== null && ! in_array($role, ['primary', 'secondary'], true)) {
            throw new InvalidArgumentException('INVALID_ROLE');
        }

        return DB::transaction(function () use ($region, $configurationSet, $topicArns, $tracking, $role, $secret, $actor) {
            $integration = TenantIntegration::query()->where('provider', 'amazon_ses')->where('capability', 'email')->lockForUpdate()->first();
            $created = $integration === null;
            if ($created && $secret === null) {
                throw new InvalidArgumentException('KEY_PAIR_REQUIRED');
            }

            $integration ??= new TenantIntegration(['provider' => 'amazon_ses', 'capability' => 'email', 'status' => 'pending', 'scopes' => ['read_account', 'read_identities']]);
            $integration->tenant_id = $this->context->requireTenant();
            $stored = (array) $integration->settings;
            $before = $created ? [] : array_intersect_key($stored, array_flip(self::SETTINGS));
            $after = [
                'region' => $region, 'configuration_set' => $configurationSet, 'sns_topic_arns' => $topicArns,
                'tracking' => array_values($tracking ?? (array) ($stored['tracking'] ?? [])), 'email_role' => $role ?? ($stored['email_role'] ?? 'primary'),
            ];
            $changed = array_values(array_filter(self::SETTINGS, fn (string $k) => ($before[$k] ?? null) !== $after[$k]));
            $settings = $after + array_diff_key($stored, array_flip(self::SETTINGS));

            $previous = $integration->secret_fingerprint;
            // Another key pair or region: the last account check says nothing any more.
            if ($secret !== null || in_array('region', $changed, true)) {
                unset($settings['account'], $settings['configuration_set_check']);
                $integration->forceFill(['status' => 'pending', 'last_error' => null, 'last_verified_at' => null]);
            } elseif (in_array('configuration_set', $changed, true) || in_array('sns_topic_arns', $changed, true)) {
                unset($settings['configuration_set_check']);
            }
            $integration->settings = $settings;
            if ($secret !== null) {
                $this->vault->seal($integration, $secret);
            }
            $integration->save();

            if ($created) {
                $this->audit->record(AuditAction::IntegrationCreated, 'tenant_integration', $integration->id, ['provider' => 'amazon_ses', 'capability' => 'email', 'region' => $region], $actor);
            } elseif ($changed !== []) {
                $this->audit->record(AuditAction::IntegrationSettingsChanged, 'tenant_integration', $integration->id,
                    ['changed' => $changed, 'from' => array_intersect_key($before, array_flip($changed)), 'to' => array_intersect_key($after, array_flip($changed))], $actor);
            }
            if ($secret !== null) {
                $this->audit->record($created ? AuditAction::IntegrationSecretCreated : AuditAction::IntegrationSecretChanged, 'tenant_integration', $integration->id, array_filter([
                    'previous_fingerprint' => $created ? null : $previous, 'fingerprint' => $integration->secret_fingerprint,
                ]), $actor);
            }

            return $integration;
        });
    }

    /**
     * Grants or revokes send_email (audited). Granting never sends anything:
     * EMAIL_SENDING_MODE, the tenant mode and the send guard still decide.
     *
     * @return list<string> the scopes afterwards
     */
    public function setSendScope(TenantIntegration $integration, bool $grant, Actor $actor): array
    {
        $scopes = array_values(array_unique($grant ? [...(array) $integration->scopes, EmailSendGuard::SCOPE] : array_diff((array) $integration->scopes, [EmailSendGuard::SCOPE])));
        $integration->forceFill(['scopes' => $scopes])->save();
        $this->audit->record(AuditAction::IntegrationScopeChanged, 'tenant_integration', $integration->id,
            ['granted' => $grant ? EmailSendGuard::SCOPE : null, 'revoked' => $grant ? null : EmailSendGuard::SCOPE, 'scopes' => $scopes], $actor);

        return $scopes;
    }

    /**
     * Text copied from the AWS console can carry invisible characters (no-break
     * or zero-width spaces, a byte order mark) that trim() keeps; they are
     * never part of an ARN.
     */
    public static function clean(string $value): string
    {
        return (string) preg_replace('/[\s\p{Z}\p{Cf}]+/u', '', $value);
    }

    public static function validTopicArn(string $arn): bool
    {
        return preg_match('/^arn:aws(-[a-z]+)?:sns:[a-z0-9-]+:\d{12}:[A-Za-z0-9_-]{1,256}$/', $arn) === 1;
    }

    /**
     * The vault secret: JSON {"access_key_id", "secret_access_key"}, or null
     * when neither part is given (keep the stored pair).
     *
     * @throws InvalidArgumentException only one part, or a malformed one
     */
    private static function keyPair(#[\SensitiveParameter] ?string $accessKeyId, #[\SensitiveParameter] ?string $secretAccessKey): ?string
    {
        $id = trim((string) $accessKeyId);
        $key = trim((string) $secretAccessKey);
        if ($id === '' && $key === '') {
            return null;
        }
        if ($id === '' || $key === '') {
            throw new InvalidArgumentException('KEY_PAIR_INCOMPLETE');
        }
        if (preg_match('/^[A-Z0-9]{16,128}$/', $id) !== 1 || strlen($key) < 16 || strlen($key) > 256) {
            throw new InvalidArgumentException('KEY_PAIR_INVALID');
        }

        return json_encode(['access_key_id' => $id, 'secret_access_key' => $key], JSON_THROW_ON_ERROR);
    }
}
