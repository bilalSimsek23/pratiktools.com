<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Console\Commands\Concerns\RunsInTenantContext;
use App\Leads\LeadForms;
use App\Site\SiteInquiries;
use App\Site\SiteOwner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Connects the public website to the site-owner tenant: creates (once) the
 * F6 lead form that website inquiries go to, allowed for this site's
 * origin only, and stores its public id. The tenant must be the one named
 * in SITE_OWNER_TENANT. Sends nothing anywhere.
 */
final class SiteSetupCommand extends Command
{
    use RunsInTenantContext;

    protected $signature = 'site:setup {--tenant= : Site-owner tenant slug (must equal SITE_OWNER_TENANT)}';

    protected $description = 'Create the website inquiry lead form in the site-owner tenant';

    public function handle(SiteOwner $owner, LeadForms $forms): int
    {
        return $this->inTenant(function (string $tenantId) use ($owner, $forms): int {
            if (! $owner->is($tenantId)) {
                $this->error('This tenant is not the site owner. Set SITE_OWNER_TENANT to its slug first.');

                return self::FAILURE;
            }
            $existing = json_decode((string) DB::table('site_settings')->where('key', 'lead_form')->value('value'), true)['public_id'] ?? null;
            if (is_string($existing) && DB::table('lead_forms')->where('tenant_id', $tenantId)->where('public_id', $existing)->exists()) {
                $this->info("Website lead form already set up ({$existing}).");

                return self::SUCCESS;
            }
            $actor = Actor::console($this->getName());
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
