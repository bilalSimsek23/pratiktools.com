<?php

namespace App\Http\Controllers\Panel\Concerns;

use App\Audit\Actor;
use App\Tenancy\Permission;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

trait PanelHelpers
{
    protected function userId(): string
    {
        return (string) Auth::guard('web')->id();
    }

    protected function actor(): Actor
    {
        return Actor::user($this->userId());
    }

    protected function can(Permission $permission): bool
    {
        return Gate::allows($permission->value);
    }

    protected function authorizePanel(Permission $permission): void
    {
        Gate::authorize($permission->value);
    }

    /**
     * 404 unless the row exists for the current tenant. RLS hides other
     * tenants' rows, so a foreign UUID answers exactly like an unknown one,
     * before any validation or action runs.
     */
    protected function ownOr404(string $table, string $id): void
    {
        abort_unless(DB::table($table)->where('id', $id)->exists(), 404);
    }

    protected function role(): TenantRole
    {
        return app(TenantContext::class)->membership()->role;
    }

    protected function done(string $message): RedirectResponse
    {
        return back()->with('status', $message);
    }

    protected function failed(string $message): RedirectResponse
    {
        return back()->withErrors(['action' => $message])->withInput();
    }
}
