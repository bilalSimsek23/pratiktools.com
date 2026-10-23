<?php

namespace App\Console\Commands;

use App\Commerce\DataPreview;
use App\Consent\RecipientDirectory;
use App\Consent\SendEligibility;
use App\Console\Commands\Concerns\RunsInTenantContext;
use Illuminate\Console\Command;

final class CheckConsent extends Command
{
    use RunsInTenantContext;

    protected $signature = 'consent:check {--tenant= : Tenant slug} {--customer= : Customer id} {--email= : E-mail address}';

    protected $description = 'Explain the e-mail send decision for one customer or address (masked)';

    public function handle(SendEligibility $eligibility, RecipientDirectory $recipients): int
    {
        return $this->inTenant(function () use ($eligibility, $recipients) {
            if ($this->option('customer')) {
                $decision = $eligibility->forCustomer((string) $this->option('customer'));
                $label = 'customer '.$this->option('customer');
            } else {
                $address = $recipients->email((string) $this->option('email'));
                $id = $address === null ? null : ($recipients->lookup('email', [$address['address']])[$address['address']] ?? null);
                $decision = $id === null ? null : $eligibility->forRecipient($id);
                $label = DataPreview::maskEmail((string) $this->option('email'));
            }

            $this->line($label.': '.($decision === null ? 'NO_RECIPIENT' : $decision->reason->value).' '.json_encode($decision === null ? [] : $decision->details));

            return self::SUCCESS;
        });
    }
}
