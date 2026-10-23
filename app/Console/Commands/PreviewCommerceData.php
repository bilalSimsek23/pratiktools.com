<?php

namespace App\Console\Commands;

use App\Commerce\DataPreview;
use App\Console\Commands\Concerns\RunsInTenantContext;
use Illuminate\Console\Command;

final class PreviewCommerceData extends Command
{
    use RunsInTenantContext;

    protected $signature = 'commerce:preview {--tenant= : Tenant slug} {--limit=10}';

    protected $description = 'Show up to 10 synced customers, products and orders (contact data masked)';

    public function handle(DataPreview $preview): int
    {
        $this->line((string) json_encode(
            $this->inTenant(fn () => $preview->sample((int) $this->option('limit'))),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        return self::SUCCESS;
    }
}
