<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the tenant context of an API request from the tenant the access
 * token was issued for, after confirming the user is still an active member
 * and the tenant is active. Request parameters and headers are never used
 * to choose the tenant. The context is cleared when the request ends.
 */
final class ResolveTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        abort_unless($user instanceof User && $token instanceof PersonalAccessToken && $token->tenant_id !== null, 401);

        try {
            $this->context->setUser($user->id);

            $membership = TenantUser::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $token->tenant_id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->first();

            abort_if($membership === null, 403);

            $this->context->setTenant($token->tenant_id);
            abort_unless(Tenant::query()->whereKey($token->tenant_id)->value('status') === Tenant::STATUS_ACTIVE, 403);
            $this->context->setMembership($membership);

            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
