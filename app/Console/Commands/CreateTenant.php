<?php

namespace App\Console\Commands;

use App\Audit\Actor;
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
        {--country=TR} {--timezone=Europe/Istanbul} {--currency=TRY} {--locale=tr}';

    protected $description = 'Provision a tenant with its settings, owner and data key';

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
            'slug' => ['required', 'regex:/^[a-z0-9][a-z0-9-]{1,62}$/'],
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

        $password = $this->secret('Owner password (leave empty to keep an existing user\'s password or set a random one)');

        $tenant = $provisioner->provision(
            ['name' => $input['name'], 'slug' => $input['slug'], 'country' => $input['country'], 'timezone' => $input['timezone'], 'currency' => $input['currency'], 'locale' => $input['locale']],
            ['email' => $input['owner_email'], 'name' => $input['owner_name'], 'password' => $password ?: null],
            Actor::console($this->getName()),
        );

        $this->info("Tenant {$tenant->slug} created ({$tenant->id}).");

        return self::SUCCESS;
    }
}
