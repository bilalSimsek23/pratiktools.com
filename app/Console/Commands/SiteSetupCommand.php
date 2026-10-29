<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Console\Commands\Concerns\RunsInTenantContext;
use App\Leads\LeadForms;
use App\Site\SiteInquiries;
use App\Site\SiteOwner;
use App\Site\SiteSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bootstraps the public website for a tenant, idempotently:
 *
 * - records it as the site owner in the site settings (the runtime source
 *   of truth) when no owner is saved yet; refuses if another tenant already
 *   owns the site (changing the owner is a panel action);
 * - creates once the F6 lead form that website inquiries go to, allowed for
 *   this site's origin only, and stores its public id.
 *
 * SITE_OWNER_TENANT is not needed. Sends nothing anywhere.
 */
final class SiteSetupCommand extends Command
{
    use RunsInTenantContext;

    protected $signature = 'site:setup {--tenant= : Slug of the tenant that owns the website}';

    protected $description = 'Create the website inquiry lead form in the site-owner tenant';

    public function handle(SiteOwner $owner, SiteSettings $settings, LeadForms $forms): int
    {
        return $this->inTenant(function (string $tenantId) use ($owner, $settings, $forms): int {
            $actor = Actor::console($this->getName());
            $current = $owner->tenantId();
            if ($current !== null && ! hash_equals($current, $tenantId)) {
                $this->error('Another tenant owns the website. Change the owner in Web Sitesi › Site Ayarları.');

                return self::FAILURE;
            }
            if ($settings->ownerSlug()['source'] !== 'settings') {
                $settings->set('owner', ['tenant' => (string) $this->option('tenant')], $actor);
                $this->info('Recorded '.$this->option('tenant').' as the website owner (site settings).');
            }
            $existing = json_decode((string) DB::table('site_settings')->where('key', 'lead_form')->value('value'), true)['public_id'] ?? null;
            if (is_string($existing) && DB::table('lead_forms')->where('tenant_id', $tenantId)->where('public_id', $existing)->exists()) {
                $this->info("Website lead form already set up ({$existing}).");

                return self::SUCCESS;
            }
            $id = $forms->create('PratikTools web sitesi', 'landing_page', 'Kampanya ve duyurulardan e-posta ile haberdar olmak istiyorum.',
                'Teşekkürler, talebiniz alındı.', [SiteInquiries::origin()], $actor);
            $forms->setActive($id, true, $actor);
            $publicId = (string) DB::table('lead_forms')->where('tenant_id', $tenantId)->where('id', $id)->value('public_id');
            DB::table('site_settings')->upsert([['key' => 'lead_form', 'value' => json_encode(['public_id' => $publicId]), 'updated_by' => 'console:site:setup', 'updated_at' => now()]],
                ['key'], ['value', 'updated_by', 'updated_at']);
            $this->info("Website lead form created and active ({$publicId}), origin ".SiteInquiries::origin().'.');

            return self::SUCCESS;
        });
    }
}
