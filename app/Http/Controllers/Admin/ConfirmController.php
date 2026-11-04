<?php

namespace App\Http\Controllers\Admin;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Middleware\PlatformRecentAuth;
use App\Models\PlatformAdmin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Super Admin password re-confirmation before tenant changes.
 */
final class ConfirmController extends Controller
{
    public function show(): View
    {
        return view('admin.confirm');
    }

    public function confirm(Request $request, AuditLogger $audit): RedirectResponse
    {
        $password = (string) $request->validate(['password' => ['required', 'string', 'max:255']])['password'];
        /** @var PlatformAdmin $admin */
        $admin = Auth::guard('platform')->user();
        if (! Hash::check($password, (string) $admin->password)) {
            throw ValidationException::withMessages(['password' => __('auth.password')]);
        }
        $request->session()->put(PlatformRecentAuth::SESSION_KEY, time());
        $audit->record(AuditAction::PlatformReauthenticated, 'platform_admin', $admin->id, [], Actor::platformAdmin($admin->id), tenantId: false, connection: 'pgsql_platform');

        return redirect()->to((string) $request->session()->pull('platform.intended', route('admin.tenants')))->with('status', 'Şifre doğrulandı; işlemi tekrar gönderin.');
    }
}
