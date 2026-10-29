<?php

namespace App\Site;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Leads\LeadCapture;
use App\Leads\LeadError;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Website inquiries ("Teklif Al", "Görüşme Talep Et", ...).
 *
 * The public POST is stateless (no session/cookie, cacheable pages), so it
 * is protected by: same-origin check (Origin, else Referer), payload size
 * limit, per-IP rate limits, honeypot, a signed render timestamp (too fast
 * or too old = bot), the F6 bot verifier and strict field validation.
 * Accepted, duplicate and bot submissions get the same answer.
 *
 * Storage reuses F6 lead capture in the site-owner tenant (lead customer,
 * consent recipient, metrics, idempotency; marketing consent only if the
 * visitor ticks the optional box) plus a lead_inquiries row (RLS) with the
 * message and sanitized attribution.
 */
final class SiteInquiries
{
    public const KINDS = ['teklif' => 'Teklif Al', 'gorusme' => 'Görüşme Talep Et', 'proje' => 'Projenizi Anlatın', 'demo' => 'Demo Talep Et'];

    public function __construct(
        private readonly SiteOwner $owner,
        private readonly TenantContext $context,
        private readonly LeadCapture $capture,
        private readonly SiteEvents $events,
        private readonly AuditLogger $audit,
    ) {}

    public static function stamp(?int $at = null): string
    {
        $at ??= time();

        return $at.'.'.substr(hash_hmac('sha256', 'site-inquiry|'.$at, (string) config('platform.audit.hash_key')), 0, 32);
    }

    public function available(): bool
    {
        return $this->owner->tenantId() !== null && $this->leadForm() !== null;
    }

    /**
     * @return array{outcome: string, errors: array<string, string>}
     *                                                               outcome: accepted | invalid | forbidden | rate_limited | unavailable
     */
    public function submit(Request $request): array
    {
        $result = fn (string $outcome, array $errors = []) => ['outcome' => $outcome, 'errors' => $errors];

        if (strlen((string) $request->getContent()) > (int) config('site.inquiry.max_payload_bytes')) {
            return $result('invalid', ['form' => 'Mesaj çok uzun.']);
        }
        if (! $this->sameOrigin($request)) {
            return $result('forbidden');
        }
        $ip = hash('sha256', (string) $request->ip());
        foreach (['m' => [(int) config('site.inquiry.per_ip_per_minute'), 60], 'h' => [(int) config('site.inquiry.per_ip_per_hour'), 3600]] as $w => [$max, $decay]) {
            if (RateLimiter::tooManyAttempts("site-inquiry:{$w}:{$ip}", $max)) {
                return $result('rate_limited');
            }
            RateLimiter::hit("site-inquiry:{$w}:{$ip}", $decay);
        }

        $in = fn (string $k) => is_string($request->input($k)) ? (string) $request->input($k) : '';
        if (trim($in('website')) !== '' || ! $this->stampValid($in('ft'))) {
            // Bot: same answer as success, nothing stored.
            return $result('accepted');
        }

        $errors = [];
        $name = SiteContents::line($in('name'), 120);
        $email = strtolower(trim($in('email')));
        $phone = trim($in('phone'));
        $company = SiteContents::line($in('company'), 160);
        $message = SiteContents::text($in('message'), 4000);
        $kind = array_key_exists($in('kind'), self::KINDS) ? $in('kind') : 'teklif';
        $interest = $this->interest($in('interest'));
        if (mb_strlen($name) < 2) {
            $errors['name'] = 'Adınızı yazın.';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            $errors['email'] = 'Geçerli bir e-posta adresi yazın.';
        }
        if ($phone !== '' && preg_match('/^\+?[0-9 ()-]{7,24}$/', $phone) !== 1) {
            $errors['phone'] = 'Telefon numarası geçersiz.';
        }
        if (mb_strlen($message) < 10) {
            $errors['message'] = 'Kısaca ihtiyacınızı yazın (en az 10 karakter).';
        }
        if (mb_strlen($in('message')) > 4000) {
            $errors['message'] = 'Mesaj en fazla 4000 karakter olabilir.';
        }
        if ($errors !== []) {
            return $result('invalid', $errors);
        }

        $tenantId = $this->owner->tenantId();
        $form = $this->leadForm();
        if ($tenantId === null || $form === null) {
            return $result('unavailable');
        }

        $first = Attribution::touch((array) json_decode($in('first_touch') ?: '[]', true));
        $last = Attribution::touch(array_replace(['landing' => $in('landing'), 'referrer' => $in('referrer')], (array) json_decode($in('last_touch') ?: '[]', true)));
        $sid = Str::isUuid($in('sid')) ? strtolower($in('sid')) : (string) Str::uuid();

        try {
            $outcome = $this->context->run($tenantId, function () use ($tenantId, $form, $email, $sid, $request, $last, $first, $kind, $name, $phone, $company, $interest, $message) {
                $lead = $this->capture->submit($form, array_filter([
                    'email' => $email, 'consent' => $request->boolean('marketing_consent'), 'submission_id' => $sid,
                    'page_url' => isset($last['landing']) ? SiteSeo::absolute($last['landing']) : null, 'locale' => 'tr',
                    'utm_source' => $last['source'] ?? null, 'utm_medium' => $last['medium'] ?? null, 'utm_campaign' => $last['campaign'] ?? null,
                ], fn ($v) => $v !== null), self::origin(), $request->ip());
                if (($lead['submission_id'] ?? null) !== null) {
                    DB::table('lead_inquiries')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'submission_id' => $lead['submission_id'],
                        'recipient_id' => $lead['recipient_id'] ?? null, 'kind' => $kind, 'name' => $name, 'phone' => $phone === '' ? null : $phone,
                        'company' => $company === '' ? null : $company, 'interest' => $interest, 'message' => $message,
                        'attribution' => json_encode(['first' => $first, 'last' => $last]), 'status' => 'new', 'created_at' => now()]);
                }

                return $lead['outcome'];
            });
        } catch (LeadError) {
            return $result('unavailable');
        }
        if ($outcome === 'invalid') {
            return $result('invalid', ['email' => 'Geçerli bir e-posta adresi yazın.']);
        }
        if ($outcome === 'origin_refused') {
            return $result('unavailable');
        }
        $this->events->record('lead_submit', $last['landing'] ?? '/iletisim');

        return $result('accepted');
    }

    public function setStatus(string $id, string $status, Actor $actor): void
    {
        if (! in_array($status, ['new', 'contacted', 'closed'], true)) {
            throw new SiteError('Geçersiz durum.');
        }
        if (DB::table('lead_inquiries')->where('tenant_id', $this->context->requireTenant())->where('id', $id)->update(['status' => $status, 'updated_at' => now()]) !== 1) {
            throw new SiteError('Talep bulunamadı.');
        }
        $this->audit->record(AuditAction::SiteInquiryChanged, 'lead_inquiry', $id, ['status' => $status], $actor);
    }

    /**
     * Published services and solutions a visitor can pick (title stored).
     *
     * @return array<string, string> slug key => title
     */
    public function interests(): array
    {
        return DB::table('site_contents')->whereIn('type', ['service', 'solution'])->where('status', ContentStatus::Published->value)
            ->orderBy('type')->orderBy('sort_order')->orderBy('title')->get(['type', 'slug', 'title'])
            ->mapWithKeys(fn ($r) => [$r->type.':'.$r->slug => (string) $r->title])->all();
    }

    private function interest(string $key): ?string
    {
        return $key === '' ? null : ($this->interests()[$key] ?? null);
    }

    private function leadForm(): ?string
    {
        $id = config('site.lead_form') ?: (json_decode((string) DB::table('site_settings')->where('key', 'lead_form')->value('value'), true)['public_id'] ?? null);

        return is_string($id) && preg_match('/^lf_[a-z0-9]{26}$/', $id) === 1 ? $id : null;
    }

    private function stampValid(string $stamp): bool
    {
        if (preg_match('/^(\d{10})\.([0-9a-f]{32})$/', $stamp, $m) !== 1 || ! hash_equals(self::stamp((int) $m[1]), $stamp)) {
            return false;
        }
        $age = time() - (int) $m[1];

        return $age >= (int) config('site.inquiry.min_seconds') && $age <= (int) config('site.inquiry.max_age_hours') * 3600;
    }

    private function sameOrigin(Request $request): bool
    {
        // Site pages send a real Origin (strict-origin-when-cross-origin);
        // "null" is never same-origin. No Origin at all: fall back to Referer.
        $origin = (string) $request->headers->get('Origin');
        if ($origin !== '') {
            return strtolower(rtrim($origin, '/')) === self::origin();
        }
        $referer = (string) $request->headers->get('Referer');

        return $referer !== '' && str_starts_with(strtolower($referer), self::origin().'/');
    }

    public static function origin(): string
    {
        $u = parse_url((string) config('app.url'));

        return strtolower(($u['scheme'] ?? 'https').'://'.($u['host'] ?? '').(isset($u['port']) ? ':'.$u['port'] : ''));
    }
}
