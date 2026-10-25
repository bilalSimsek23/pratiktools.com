<?php

namespace App\Operations;

/**
 * External prerequisites for live sending, derived from stored state (never
 * a hard-coded PASS).
 */
final class ExternalBlockers
{
    public function __construct(private readonly IntegrationStatus $integrations) {}

    /**
     * @param  array<string, array<string, mixed>>|null  $status
     * @return list<array{blocker: string, status: string, detail: string, action: ?string}>
     */
    public function all(?array $status = null): array
    {
        $s = $status ?? $this->integrations->all();
        $vault = fn (string $p) => ($s[$p]['vault_secret'] ?? false) && ($s[$p]['connected'] ?? false);
        $coverage = (array) ($s['onesignal']['external_id_coverage'] ?? ['with' => 0, 'total' => 0]);
        $row = fn (string $name, bool $ok, string $detail, string $action) => ['blocker' => $name, 'status' => $ok ? 'PASS' : 'BLOCKED', 'detail' => $detail, 'action' => $ok ? null : $action];

        return [
            $row('IYS_API_ACCESS', (bool) ($s['iys']['consent_available'] ?? false), ($s['iys']['configured'] ?? false) ? 'İYS integration '.$s['iys']['status'] : 'No İYS integration',
                'Obtain İYS API access and complete the F2 shadow sync.'),
            $row('SES_PRODUCTION_ACCESS', ($s['amazon_ses']['production_access'] ?? null) === true,
                ($s['amazon_ses']['production_access'] ?? null) === null ? 'Account status unknown' : 'Sandbox (production access not granted)', 'Wait for AWS SES production access.'),
            $row('EMAIL_UNSUBSCRIBE_KEY', ($s['amazon_ses']['unsubscribe_key'] ?? 'MISSING') === 'CONFIGURED', (string) ($s['amazon_ses']['unsubscribe_key'] ?? 'MISSING'),
                'Add EMAIL_UNSUBSCRIBE_KEY as Laravel Cloud Secret.'),
            $row('SES_VAULT_CONNECTION', $vault('amazon_ses'), (string) ($s['amazon_ses']['status'] ?? 'not_configured'), 'Operator: email:connect-ses (secret via STDIN) in this environment.'),
            $row('ONESIGNAL_VAULT_CONNECTION', $vault('onesignal'), (string) ($s['onesignal']['status'] ?? 'not_configured'), 'Operator: onesignal:connect (secret via STDIN) in this environment.'),
            $row('TICIMAX_VAULT_CONNECTION', $vault('ticimax'), (string) ($s['ticimax']['status'] ?? 'not_configured'), 'Operator: ticimax:connect (secret via STDIN) in this environment.'),
            $row('ONESIGNAL_EXTERNAL_ID', (int) $coverage['total'] > 0 && (int) $coverage['with'] > 0, "External ID coverage: {$coverage['with']} / {$coverage['total']}",
                'Action required: Ticimax native app must identify logged-in users to OneSignal.'),
        ];
    }
}
