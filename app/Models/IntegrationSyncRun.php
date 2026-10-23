<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One sync run (possibly spanning several job slices) for an integration and entity.
 *
 * @property string $id
 * @property string $integration_id
 * @property string $entity
 * @property string $mode
 * @property string $status
 * @property ?Carbon $window_start
 * @property Carbon $window_end
 * @property ?string $page_token
 * @property int $records_read
 * @property int $records_inserted
 * @property int $records_updated
 * @property int $records_skipped
 * @property int $errors
 * @property int $api_calls
 * @property int $slices
 * @property Carbon $started_at
 * @property ?Carbon $finished_at
 * @property ?int $duration_ms
 * @property ?string $error_category
 * @property ?string $error_message
 */
class IntegrationSyncRun extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'integration_sync_runs';

    public $timestamps = false;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['window_start' => 'datetime', 'window_end' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
