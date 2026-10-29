<?php

namespace App\Http\Middleware;

use App\Site\SiteOwner;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Website management exists only for members of the site-owner tenant;
 * for every other tenant the routes do not exist (404).
 */
final class EnsureSiteOwner
{
    public function __construct(private readonly SiteOwner $owner, private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->owner->is($this->context->tenantId()), 404);

        return $next($request);
    }
}
