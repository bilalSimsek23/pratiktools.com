<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Leads\LeadCapture;
use App\Leads\LeadError;
use App\Leads\LeadMetrics;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Public newsletter/lead endpoint for tenant websites:
 *
 *   POST /public/v1/forms/{publicId}/subscribe   (application/json)
 *   { "email": "...", "consent": true, "submission_id": "<uuid>", "page_url": "...",
 *     "locale": "tr", "utm_source": "...", "utm_medium": "...", "utm_campaign": "...",
 *     "website": "" (honeypot, must stay empty), "bot_token": "..." (optional) }
 *
 * Stateless, no cookies, no CSRF token: the form's public id is not a
 * secret and grants only "submit to this form"; the browser Origin must be
 * one of the form's allowed origins (CORS answers only those). Every
 * accepted, duplicate or suspected-bot request gets the same 202 answer
 * (no enumeration); malformed addresses get 422; limits give 413/429.
 */
final class PublicLeadController extends Controller
{
    public function preflight(Request $request, string $publicId, LeadCapture $capture, TenantContext $context): Response
    {
        $origin = (string) $request->headers->get('Origin');

        return response('', 204, $this->cors($publicId, $origin, $capture, $context) + ['Access-Control-Allow-Methods' => 'POST', 'Access-Control-Allow-Headers' => 'Content-Type', 'Access-Control-Max-Age' => '600']);
    }

    public function subscribe(Request $request, string $publicId, LeadCapture $capture, LeadMetrics $metrics, TenantContext $context): JsonResponse
    {
        $origin = (string) $request->headers->get('Origin');
        $unavailable = response()->json(['status' => 'unavailable', 'message' => 'This form is not accepting submissions.'], 404);
        $tenantId = $capture->tenantOf($publicId);
        if ($tenantId === null) {
            return $unavailable;
        }
        $cors = $this->cors($publicId, $origin, $capture, $context);
        if (strlen($request->getContent()) > (int) config('leads.max_payload_bytes')) {
            return response()->json(['status' => 'too_large'], 413, $cors);
        }

        $ipKey = 'lead:ip:'.$publicId.':'.hash('sha256', (string) $request->ip());
        $formKey = 'lead:form:'.$publicId;
        $limits = (array) config('leads.rate_limits');
        if (RateLimiter::tooManyAttempts($ipKey, (int) $limits['per_ip']) || RateLimiter::tooManyAttempts($formKey, (int) $limits['per_form'])) {
            $context->run($tenantId, fn () => ($id = DB::table('lead_forms')->where('public_id', $publicId)->value('id')) === null ? null : $metrics->add((string) $id, ['rate_limited' => 1]));

            return response()->json(['status' => 'rate_limited', 'message' => 'Please try again later.'], 429, $cors);
        }
        RateLimiter::hit($ipKey, 60);
        RateLimiter::hit($formKey, 60);

        $input = json_decode($request->getContent(), true);
        if (! is_array($input)) {
            return response()->json(['status' => 'invalid', 'message' => 'Please enter a valid e-mail address.'], 422, $cors);
        }

        try {
            $result = $context->run($tenantId, fn () => $capture->submit($publicId, $input, $origin, $request->ip()));
        } catch (LeadError) {
            return $unavailable;
        }

        return match ($result['outcome']) {
            'origin_refused' => response()->json(['status' => 'forbidden'], 403),
            'invalid' => response()->json(['status' => 'invalid', 'message' => 'Please enter a valid e-mail address.'], 422, $cors),
            default => response()->json(['status' => 'received', 'message' => $result['message']], 202, $cors),
        };
    }

    /**
     * @return array<string, string> CORS headers for an allowed origin only
     */
    private function cors(string $publicId, string $origin, LeadCapture $capture, TenantContext $context): array
    {
        $tenantId = $capture->tenantOf($publicId);
        $allowed = $tenantId === null ? [] : (array) json_decode((string) $context->run($tenantId, fn () => DB::table('lead_forms')->where('public_id', $publicId)->value('allowed_origins')), true);

        return $origin !== '' && in_array(strtolower($origin), $allowed, true) ? ['Access-Control-Allow-Origin' => strtolower($origin), 'Vary' => 'Origin'] : ['Vary' => 'Origin'];
    }
}
