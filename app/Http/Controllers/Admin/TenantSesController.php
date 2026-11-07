<?php

namespace App\Http\Controllers\Admin;

use App\Audit\Actor;
use App\Email\SesIntegrations;
use App\Email\TestEmails;
use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Platform\PlatformTenants;
use App\Platform\TenantSes;
use App\Tenancy\ProvisioningError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Super Admin › Müşteriler › {tenant} › Entegrasyonlar › Amazon SES: region,
 * the IAM key pair (write-only), configuration set, SNS topics, tracking,
 * sender identities and their DNS records, the SNS subscription, the test
 * allowlist and the test e-mail, plus the readiness summaries.
 *
 * Every change needs a recent password confirmation (route middleware), is
 * rate limited and audited, and calls the services the email:* commands
 * use. The key pair is never shown, put back into the form or flashed to
 * the session. Nothing here changes AWS or DNS.
 */
final class TenantSesController extends Controller
{
    public function show(string $tenant, PlatformTenants $tenants, TenantSes $ses): View
    {
        $t = $tenants->find($tenant) ?? abort(404);

        return view('admin.tenants.ses', ['t' => $t, 'webhookUrl' => rtrim((string) config('app.url'), '/').'/webhooks/amazon_ses/'.$t->public_id,
            'defaultRegion' => (string) config('email.ses.default_region'), 'confirmation' => TestEmails::CONFIRMATION] + $ses->show($t->id));
    }

    public function update(Request $request, string $tenant, PlatformTenants $tenants, TenantSes $ses): RedirectResponse
    {
        $t = $tenants->find($tenant) ?? abort(404);
        $d = $request->validate([
            'region' => ['required', 'string', 'max:32'], 'configuration_set' => ['nullable', 'string', 'max:64'], 'sns_topic_arns' => ['nullable', 'string', 'max:2000'],
            'tracking' => ['nullable', 'array'], 'tracking.*' => ['string', 'in:'.implode(',', SesIntegrations::TRACKING)],
            'aws_access_key_id' => ['nullable', 'string', 'max:128'], 'aws_secret_access_key' => ['nullable', 'string', 'max:256'],
        ]);
        $keyId = $d['aws_access_key_id'] ?? null;
        $secret = $d['aws_secret_access_key'] ?? null;
        unset($d['aws_access_key_id'], $d['aws_secret_access_key']);
        $rotated = trim((string) $keyId) !== '' || trim((string) $secret) !== '';
        try {
            $ses->configure($t->id, $d['region'], $d['configuration_set'] ?? null, preg_split('/[\s,\p{Z}]+/u', (string) ($d['sns_topic_arns'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [],
                array_values($d['tracking'] ?? []), $keyId, $secret, $this->actor());
        } catch (ProvisioningError $e) {
            return back()->withInput($request->except(['aws_access_key_id', 'aws_secret_access_key']))->withErrors(['ses' => $e->getMessage()]);
        } finally {
            unset($keyId, $secret);
        }

        return back()->with('status', $rotated ? 'SES ayarları ve AWS kimlik bilgileri kaydedildi. Bağlantıyı test edin.' : 'SES ayarları kaydedildi.');
    }

    public function test(string $tenant, PlatformTenants $tenants, TenantSes $ses): RedirectResponse
    {
        $t = $tenants->find($tenant) ?? abort(404);
        try {
            $result = $ses->test($t->id, $this->actor());
        } catch (ProvisioningError $e) {
            return back()->withErrors(['ses_test' => $e->getMessage()]);
        }

        return $result->ok ? back()->with('status', 'SES bağlantı testi başarılı.') : back()->withErrors(['ses_test' => 'SES bağlantı testi başarısız. Ayrıntı aşağıda.']);
    }

    public function storeSender(Request $request, string $tenant, PlatformTenants $tenants, TenantSes $ses): RedirectResponse
    {
        $t = $tenants->find($tenant) ?? abort(404);
        $d = $request->validate([
            'from_email' => ['required', 'string', 'max:320'], 'from_name' => ['nullable', 'string', 'max:120'],
            'reply_to' => ['nullable', 'string', 'max:320'], 'identity' => ['nullable', 'string', 'max:253'],
        ]);
        try {
            $ses->saveSender($t->id, $d['from_email'], $d['from_name'] ?? null, $d['reply_to'] ?? null, $d['identity'] ?? null, $this->actor());
        } catch (ProvisioningError $e) {
            return back()->withInput()->withErrors(['ses_sender' => $e->getMessage()]);
        }

        return back()->with('status', 'Gönderici kaydedildi. SES\'te doğrulanana kadar kullanılamaz: Doğrula / Yenile ile durumunu okuyun.');
    }

    public function refreshSender(string $tenant, string $sender, PlatformTenants $tenants, TenantSes $ses): RedirectResponse
    {
        $t = $tenants->find($tenant) ?? abort(404);
        try {
            $r = $ses->refreshSender($t->id, $sender, $this->actor());
        } catch (ProvisioningError $e) {
            return back()->withErrors(['ses_sender' => $e->getMessage()]);
        }

        return back()->with('status', "Gönderici durumu okundu: doğrulama {$r['verification_status']}, DKIM {$r['dkim_status']}, DMARC {$r['dmarc_status']}.");
    }

    public function confirmSubscription(string $tenant, PlatformTenants $tenants, TenantSes $ses): RedirectResponse
    {
        $t = $tenants->find($tenant) ?? abort(404);
        try {
            $ses->confirmSubscription($t->id, $this->actor());
        } catch (ProvisioningError $e) {
            return back()->withErrors(['ses_sns' => $e->getMessage()]);
        }

        return back()->with('status', 'SNS aboneliği onaylandı.');
    }

    public function scope(Request $request, string $tenant, PlatformTenants $tenants, TenantSes $ses): RedirectResponse
    {
        $t = $tenants->find($tenant) ?? abort(404);
        $d = $request->validate(['grant' => ['required', 'in:0,1'], 'confirm' => ['nullable', 'string', 'max:64']]);
        $grant = $d['grant'] === '1';
        if ($grant && ($d['confirm'] ?? null) !== 'send_email') {
            return back()->withErrors(['ses_scope' => 'Gönderim yetkisini açmak için kutuya send_email yazın.']);
        }
        try {
            $ses->setSendScope($t->id, $grant, $this->actor());
        } catch (ProvisioningError $e) {
            return back()->withErrors(['ses_scope' => $e->getMessage()]);
        }

        return back()->with('status', $grant
            ? 'send_email yetkisi açıldı. Gönderim modu kapalıyken hiçbir e-posta gönderilmez.'
            : 'send_email yetkisi kapatıldı.');
    }

    public function addTestRecipient(Request $request, string $tenant, PlatformTenants $tenants, TenantSes $ses): RedirectResponse
    {
        $t = $tenants->find($tenant) ?? abort(404);
        $d = $request->validate(['email' => ['required', 'string', 'max:320'], 'label' => ['required', 'string', 'max:120'], 'approved_by' => ['required', 'string', 'max:64']]);
        try {
            $ses->addTestRecipient($t->id, $d['email'], $d['label'], $d['approved_by'], $this->actor());
        } catch (ProvisioningError $e) {
            return back()->withInput()->withErrors(['ses_recipient' => $e->getMessage()]);
        }

        return back()->with('status', 'Test alıcısı eklendi.');
    }

    public function removeTestRecipient(Request $request, string $tenant, PlatformTenants $tenants, TenantSes $ses): RedirectResponse
    {
        $t = $tenants->find($tenant) ?? abort(404);
        $d = $request->validate(['email' => ['required', 'string', 'max:320']]);
        try {
            $ses->removeTestRecipient($t->id, $d['email'], $this->actor());
        } catch (ProvisioningError $e) {
            return back()->withErrors(['ses_recipient' => $e->getMessage()]);
        }

        return back()->with('status', 'Test alıcısı kaldırıldı.');
    }

    public function sendTest(Request $request, string $tenant, PlatformTenants $tenants, TenantSes $ses): RedirectResponse
    {
        $t = $tenants->find($tenant) ?? abort(404);
        $d = $request->validate([
            'recipients' => ['required', 'array', 'min:1', 'max:'.(int) config('email.max_test_recipients')], 'recipients.*' => ['string', 'max:320'],
            'from_email' => ['required', 'string', 'max:320'], 'confirmation' => ['required', 'string', 'max:64'], 'acknowledge' => ['accepted'],
        ]);
        try {
            $ids = $ses->sendTest($t->id, array_values($d['recipients']), $d['from_email'], $d['confirmation'], $this->actor());
        } catch (ProvisioningError $e) {
            return back()->withErrors(['ses_test_email' => $e->getMessage()]);
        }

        return back()->with('status', count($ids).' test e-postası kuyruğa alındı.');
    }

    private function actor(): Actor
    {
        /** @var PlatformAdmin $admin */
        $admin = Auth::guard('platform')->user();

        return Actor::platformAdmin($admin->id);
    }
}
