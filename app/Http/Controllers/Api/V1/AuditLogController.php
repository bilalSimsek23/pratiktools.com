<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Tenancy\Permission;
use App\Tenancy\TenantContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class AuditLogController extends Controller
{
    public function index(TenantContext $context): AnonymousResourceCollection
    {
        Gate::authorize(Permission::AuditView->value);

        return AuditLogResource::collection(
            AuditLog::query()
                ->where('tenant_id', $context->requireTenant())
                ->orderByDesc('created_at')
                ->cursorPaginate(100)
        );
    }
}
