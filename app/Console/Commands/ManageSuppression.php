<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Consent\ConsentChannel;
use App\Consent\RecipientDirectory;
use App\Consent\SuppressionService;
use App\Consent\SuppressionSource;
use App\Console\Commands\Concerns\RunsInTenantContext;
use Illuminate\Console\Command;

final class ManageSuppression extends Command
{
    use RunsInTenantContext;

    protected $signature = 'consent:suppression
        {action : add or lift}
        {--tenant= : Tenant slug}
        {--email= : Address to suppress (add)}
        {--channel= : EMAIL, SMS, CALL; empty = all channels}
        {--source=MANUAL}
        {--id= : Suppression id (lift)}
        {--reason= : Required for lift}';

    protected $description = 'Add or explicitly lift a local suppression (audited)';

    public function handle(SuppressionService $suppressions, RecipientDirectory $recipients): int
    {
        $actor = Actor::console($this->getName());

        return $this->inTenant(function () use ($suppressions, $recipients, $actor) {
            if ($this->argument('action') === 'lift') {
                $suppressions->lift((string) $this->option('id'), (string) $this->option('reason'), $actor);
                $this->info('Suppression lifted.');

                return self::SUCCESS;
            }

            $address = $recipients->email((string) $this->option('email')) ?? throw new \InvalidArgumentException('Invalid --email.');
            $id = $recipients->ensure('email', [$address])[$address['address']];
            $channel = $this->option('channel') ? ConsentChannel::from((string) $this->option('channel')) : null;
            $suppressionId = $suppressions->suppress($id, SuppressionSource::from((string) $this->option('source')), $channel, $this->option('reason'), $actor);
            $this->info("Suppression {$suppressionId} active.");

            return self::SUCCESS;
        });
    }
}
