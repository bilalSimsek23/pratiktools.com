<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

trait FindsAutomation
{
    protected function automationId(): string
    {
        $ref = (string) $this->option('automation');
        $id = DB::table('automations')->where(Str::isUuid($ref) ? 'id' : 'name', $ref)->value('id');

        return $id === null ? throw new RuntimeException('Unknown automation. Pass --automation=<id or name>.') : (string) $id;
    }
}
