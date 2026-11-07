<?php

namespace App\Http\Controllers\Admin;

use App\Audit\Actor;
use App\Email\EmailSendingMode;
use App\Email\PlatformEmailMode;
use App\Health\HealthChecker;
use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Platform\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Super Admin › Sistem Durumu: infrastructure health and the platform
 * e-mail switch (E-posta Gönderim Modu) with each tenant's own mode and the
 * effective result. Changing the switch needs a recent password (route
 * middleware); live additionally needs the password again, a current
 * second-factor code and the typed phrase, in the same request.
 */
final class SystemController extends Controller
{
    private const ERRORS = [
        'UNCHANGED' => 'Platform gönderim modu zaten bu değerde.',
        'ABOVE_CEILING' => 'Ortam üst sınırı (EMAIL_SENDING_MODE) bu moda izin vermiyor. Üst sınır yalnız Laravel Cloud ortam ayarlarından yükseltilebilir.',
        'LIVE_NOT_CONFIRMED' => 'Canlı mod için güçlü onay gerekli.',
    ];

    public function __invoke(HealthChecker $health, PlatformEmailMode $mode): View
    {
        $state = $mode->state();
        $tenants = DB::connection('pgsql_platform')->table('tenants as t')->leftJoin('tenant_settings as s', 's.tenant_id', '=', 't.id')
            ->orderBy('t.name')->get(['t.id', 't.name', 't.slug', 't.status', 's.sending_mode'])
            ->map(fn (object $t) => [
                'id' => $t->id, 'name' => $t->name, 'slug' => $t->slug, 'status' => $t->status, 'mode' => (string) ($t->sending_mode ?? 'off'),
                'effective' => EmailSendingMode::stricter($state['effective'], EmailSendingMode::fromTenant($t->sending_mode)),
                'reason' => PlatformEmailMode::explain($state['ceiling'], $state['stored'], $t->sending_mode),
            ])->all();
        $by = $state['updated_by'] === null ? null : PlatformAdmin::query()->whereKey($state['updated_by'])->value('name');

        return view('admin.system', ['health' => $health->check(), 'email' => $state + ['updated_by_name' => $by], 'tenants' => $tenants, 'phrase' => PlatformEmailMode::LIVE_PHRASE]);
    }

    public function emailSendingMode(Request $request, PlatformEmailMode $mode, TwoFactor $twoFactor): RedirectResponse
    {
        $d = $request->validate(['mode' => ['required', 'in:off,test_only,live']]);
        $to = EmailSendingMode::from($d['mode']);
        /** @var PlatformAdmin $admin */
        $admin = Auth::guard('platform')->user();
        $actor = Actor::platformAdmin($admin->id);

        $confirmed = false;
        if ($to === EmailSendingMode::Live) {
            $failed = match (true) {
                ! $request->boolean('acknowledge') => 'LIVE_ACKNOWLEDGE',
                trim((string) $request->input('phrase')) !== PlatformEmailMode::LIVE_PHRASE => 'LIVE_PHRASE',
                ! Hash::check((string) $request->input('password'), (string) $admin->getAuthPassword()) => 'LIVE_PASSWORD',
                ! $admin->hasTwoFactorEnabled() || ! $twoFactor->verify($admin, (string) $admin->two_factor_secret, (string) $request->input('code')) => 'LIVE_TWO_FACTOR',
                default => null,
            };
            if ($failed !== null) {
                $mode->refuseLive($failed, $actor);

                return back()->withErrors(['email_mode' => 'Canlı moda geçilmedi: onay kutusu, onay ifadesi, şifre ve 2FA kodunun hepsi doğru olmalı.']);
            }
            $confirmed = true;
        }

        try {
            $mode->set($to, $actor, $confirmed);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['email_mode' => self::ERRORS[$e->getMessage()] ?? 'Mod değiştirilemedi.']);
        }

        return back()->with('status', "Platform e-posta gönderim modu: {$to->label()}. Etkin platform modu: {$mode->effective()->label()}.");
    }
}
