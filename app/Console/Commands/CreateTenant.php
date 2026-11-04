<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Tenancy\ProvisioningError;
use App\Tenancy\TenantProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

final class CreateTenant extends Command
{
    protected $signature = 'tenant:create
        {name : Display name}
        {slug : URL-safe identifier}
        {--owner-email= : Owner e-mail (created if missing)}
        {--owner-name= : Owner name}
        {--country=TR} {--timezone=Europe/Istanbul} {--currency=TRY} {--locale=tr}
        {--invite : Do not ask for a password; the owner activates the account through an invitation link}
        {--show-invite-link : Print the one-time invitation link (otherwise create a new one in Platform Yönetimi)}';

    protected $description = 'Provision a tenant with its settings, owner and data key (same service as Platform Yönetimi › Yeni Müşteri)';

    public function handle(TenantProvisioner $provisioner): int
    {
        $input = [
            'name' => (string) $this->argument('name'),
            'slug' => (string) $this->argument('slug'),
            'country' => (string) $this->option('country'),
            'timezone' => (string) $this->option('timezone'),
            'currency' => (string) $this->option('currency'),
            'locale' => (string) $this->option('locale'),
            'owner_email' => (string) $this->option('owner-email'),
            'owner_name' => (string) ($this->option('owner-name') ?: $this->option('owner-email')),
        ];

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'regex:'.TenantProvisioner::SLUG_PATTERN],
            'country' => ['required', 'size:2', 'alpha'],
            'timezone' => ['required', 'timezone:all'],
            'currency' => ['required', 'size:3', 'alpha'],
            'locale' => ['required', 'max:16'],
            'owner_email' => ['required', 'email'],
            'owner_name' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $password = $this->option('invite') ? null : $this->secret('Owner password (empty: invite the owner to set their own password)');
        if (is_string($password) && $password !== '' && strlen($password) < 12) {
            $this->error('Password must be at least 12 characters.');

            return self::FAILURE;
        }

        try {
            $result = $provisioner->provision(
                ['name' => $input['name'], 'slug' => $input['slug'], 'country' => $input['country'], 'timezone' => $input['timezone'], 'currency' => $input['currency'], 'locale' => $input['locale']],
                ['email' => $input['owner_email'], 'name' => $input['owner_name'], 'password' => $password ?: null],
                Actor::console($this->getName()),
            );
        } catch (ProvisioningError $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $result->created) {
            $this->info("Tenant {$result->tenant->slug} already exists for this owner; nothing changed.");

            return self::SUCCESS;
        }
        $this->info("Tenant {$result->tenant->slug} created ({$result->tenant->id}).");
        if ($result->invitation !== null) {
            $this->line('Owner invitation created (expires '.$result->invitation->expiresAt->toDateTimeString().').');
            $this->option('show-invite-link')
                ? $this->line($result->invitation->url)
                : $this->line('Link not printed. Show it with --show-invite-link, or create a new link in Platform Yönetimi › Müşteriler.');
        }

        return self::SUCCESS;
    }
}
