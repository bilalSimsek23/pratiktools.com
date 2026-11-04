<?php

namespace App\Http\Controllers\Panel;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Middleware\PanelRecentAuth;
use App\Http\Middleware\PanelTenant;
use App\Models\User;
use App\Tenancy\ProvisioningError;
use App\Tenancy\TenantInvitations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\Response;

/**
 * Invitation link (/app/davet/{token}, signed): a new account chooses its
 * own password; an existing account signs in as itself and accepts. Every
 * invalid, used, revoked or expired link gets the same answer.
 */
final class InvitationController extends Controller
{
    public function __construct(private readonly TenantInvitations $invitations) {}

    public function show(Request $request, string $token): Response
    {
        $invitation = $this->invitations->resolve($token);
        if ($invitation === null) {
            return $this->invalid();
        }
        $signedIn = Auth::guard('web')->user();
        if (! $invitation['new_account'] && ! ($signedIn instanceof User && $signedIn->id === $invitation['user_id'])) {
            // Back here after signing in as the invited account.
            $request->session()->put('panel.invitation', $request->fullUrl());
        }

        return response()->view('panel.invitation', ['invitation' => $invitation, 'action' => $request->fullUrl(),
            'signedInAsInvitee' => $signedIn instanceof User && $signedIn->id === $invitation['user_id']])
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function accept(Request $request, string $token, AuditLogger $audit): RedirectResponse|Response
    {
        $invitation = $this->invitations->resolve($token);
        if ($invitation === null) {
            return $this->invalid();
        }
        $password = null;
        if ($invitation['new_account']) {
            $password = (string) $request->validate(['password' => ['required', 'string', 'confirmed', 'max:255', Password::min(12)]])['password'];
        }
        $signedIn = Auth::guard('web')->user();
        try {
            $result = $this->invitations->accept($token, $password, $signedIn instanceof User ? $signedIn : null);
        } catch (ProvisioningError $e) {
            return match ($e->getMessage()) {
                'sign_in' => redirect()->route('panel.login')->withErrors(['email' => 'Bu davet mevcut bir hesaba ait. Önce o hesapla giriş yapın; ardından davet bağlantısını tekrar açın.']),
                'password' => back()->withErrors(['password' => 'Şifre en az 12 karakter olmalı.']),
                default => $this->invalid(),
            };
        }
        $request->session()->forget('panel.invitation');
        if (! $signedIn instanceof User || $signedIn->id !== $result['user']->id) {
            Auth::guard('web')->login($result['user']);
            $request->session()->regenerate();
            $audit->record(AuditAction::PanelLogin, 'user', $result['user']->id, ['via' => 'invitation'], Actor::user($result['user']->id), tenantId: false);
        }
        $request->session()->put(PanelRecentAuth::SESSION_KEY, time());
        $request->session()->put(PanelTenant::SESSION_TENANT, $result['tenant_id']);

        return redirect()->route('panel.dashboard')->with('status', 'Hesabınız etkin. Hoş geldiniz.');
    }

    private function invalid(): Response
    {
        return response()->view('panel.invitation-invalid', [], 410)->header('Cache-Control', 'no-store');
    }
}
