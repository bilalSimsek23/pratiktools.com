<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Site\ContentType;
use App\Site\InitialContent;
use App\Site\SiteInquiries;
use App\Site\SiteQuery;
use App\Site\SiteSeo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /iletisim/talep — stateless inquiry endpoint. With JavaScript the
 * page posts with fetch and gets JSON; without it, a normal form post is
 * redirected to the thank-you page or the contact page is shown again with
 * the errors.
 */
final class SiteInquiryController extends Controller
{
    public function __invoke(Request $request, SiteInquiries $inquiries, PublicSiteController $site, SiteQuery $query, SiteSeo $seo): Response
    {
        $r = $inquiries->submit($request);
        $json = $request->expectsJson();
        $status = ['accepted' => 200, 'invalid' => 422, 'forbidden' => 403, 'rate_limited' => 429, 'unavailable' => 503][$r['outcome']] ?? 400;
        $message = match ($r['outcome']) {
            'accepted' => 'Teşekkürler, talebiniz alındı. En kısa sürede size dönüş yapacağız.',
            'invalid' => 'Lütfen işaretli alanları kontrol edin.',
            'rate_limited' => 'Çok fazla deneme yapıldı; lütfen biraz sonra tekrar deneyin.',
            'unavailable' => 'Form şu anda talep kabul etmiyor. Lütfen daha sonra tekrar deneyin.',
            default => 'Talep gönderilemedi.',
        };
        if ($json) {
            return new JsonResponse(['status' => $r['outcome'], 'message' => $message, 'errors' => $r['errors']], $status, ['Cache-Control' => 'no-store']);
        }
        if ($r['outcome'] === 'accepted') {
            return redirect('/iletisim/tesekkurler', 303);
        }
        $item = $query->find(ContentType::Page, 'iletisim') ?? InitialContent::fallbackPage('iletisim');

        return $site->render('site.contact', ['item' => $item, 'html' => '', 'crumbs' => [['Ana Sayfa', '/'], ['İletişim', '/iletisim']], 'formErrors' => $r['errors'] + ['form' => $message],
            'old' => $request->only(['name', 'email', 'phone', 'company', 'message', 'kind', 'interest'])],
            $seo->meta('İletişim', 'İletişim', '/iletisim', index: false), [], null, $status);
    }
}
