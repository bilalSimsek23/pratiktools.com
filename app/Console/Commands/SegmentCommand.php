<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Console\Commands\Concerns\RunsInTenantContext;
use App\Segments\SegmentRuleError;
use App\Segments\Segments;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 *   preview   --rules='{"all":[…]}' or --segment=<id>   read-only counts and exclusion reasons
 *   create    --name --type=dynamic|snapshot --rules
 *   snapshot  --segment=<id>                            freeze current members
 *   list
 */
final class SegmentCommand extends Command
{
    use RunsInTenantContext;

    protected $signature = 'segment {action : preview|create|snapshot|list} {--tenant= : Tenant slug} {--rules= : Rule DSL (JSON)} {--segment= : Segment id}
        {--name= : Segment name} {--type=dynamic : dynamic|snapshot}';

    protected $description = 'Segments: read-only preview, create, snapshot, list';

    public function handle(Segments $segments): int
    {
        return $this->inTenant(function () use ($segments) {
            try {
                $rules = fn () => $this->option('segment') ? $segments->definition((string) $this->option('segment')) : (array) json_decode((string) $this->option('rules'), true, 32, JSON_THROW_ON_ERROR);

                switch ($this->argument('action')) {
                    case 'preview':
                        $this->line(json_encode($segments->preview($rules()), JSON_PRETTY_PRINT));
                        break;
                    case 'create':
                        $this->info('Segment '.$segments->create((string) $this->option('name'), (string) $this->option('type'), $rules(), Actor::console($this->getName())).' created.');
                        break;
                    case 'snapshot':
                        $id = $segments->snapshot((string) $this->option('segment'), Actor::console($this->getName()));
                        $this->info("Snapshot {$id}: {$segments->snapshotCount($id)} members.");
                        break;
                    default:
                        $this->table(['id', 'name', 'type', 'created'], DB::table('segments')->orderBy('name')->get(['id', 'name', 'type', 'created_at'])->map(fn ($r) => (array) $r)->all());
                }
            } catch (SegmentRuleError $e) {
                $this->error("[{$e->reason}] {$e->getMessage()}");

                return self::INVALID;
            } catch (\JsonException) {
                $this->error('--rules must be JSON.');

                return self::INVALID;
            }

            return self::SUCCESS;
        });
    }
}
