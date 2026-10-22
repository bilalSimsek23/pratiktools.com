<?php

namespace App\Http\Controllers\Admin;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsurePlatformTwoFactor;
use App\Models\PlatformAdmin;
use App\Platform\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class TwoFactorController extends Controller
{
    private const PENDING_SECRET = 'platform.two_factor_pending_secret';

    public function __construct(
        private readonly TwoFactor $twoFactor,
        private readonly AuditLogger $audit,
    ) {}

    public function setup(Request $request): View|RedirectResponse
    {
        $admin = $this->admin();

        if ($admin->hasTwoFactorEnabled()) {
            return redirect()->route('admin.two-factor.challenge');
        }

        $secret = $request->session()->get(self::PENDING_SECRET) ?? $this->twoFactor->generateSecret();
        $request->session()->put(self::PENDING_SECRET, $secret);

        return view('admin.two-factor-setup', [
            'secret' => $secret,
            'uri' => $this->twoFactor->provisioningUri($admin, $secret),
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $admin = $this->admin();
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:10']])['code'];
        $secret = (string) $request->session()->get(self::PENDING_SECRET, '');

        if ($secret === '' || ! $this->twoFactor->verify($admin, $secret, $code)) {
            throw ValidationException::withMessages(['code' => 'Invalid code.']);
        }

        $admin->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
        $request->session()->forget(self::PENDING_SECRET);
        $request->session()->put(EnsurePlatformTwoFactor::SESSION_KEY, $admin->id);
        $request->session()->regenerate();

        $this->audit->record(AuditAction::PlatformTwoFactorEnabled, 'platform_admin', $admin->id, [], Actor::platformAdmin($admin->id), tenantId: false, connection: 'pgsql_platform');

        return redirect()->route('admin.dashboard');
    }

    public function challenge(): View
    {
        return view('admin.two-factor-challenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $admin = $this->admin();
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:10']])['code'];

        if (! $admin->hasTwoFactorEnabled() || ! $this->twoFactor->verify($admin, (string) $admin->two_factor_secret, $code)) {
            throw ValidationException::withMessages(['code' => 'Invalid code.']);
        }

        $request->session()->regenerate();
        $request->session()->put(EnsurePlatformTwoFactor::SESSION_KEY, $admin->id);

        return redirect()->route('admin.dashboard');
    }

    private function admin(): PlatformAdmin
    {
        $admin = Auth::guard('platform')->user();
        abort_unless($admin instanceof PlatformAdmin, 401);

        return $admin;
    }
}
