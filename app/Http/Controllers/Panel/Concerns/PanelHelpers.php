<?php

namespace App\Http\Controllers\Panel\Concerns;

use App\Audit\Actor;
use App\Tenancy\Permission;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
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
