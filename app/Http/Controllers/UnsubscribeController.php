<?php

namespace App\Http\Controllers;

use App\Email\InvalidUnsubscribeToken;
use App\Email\Unsubscribes;
use App\Email\UnsubscribeTokens;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public, stateless unsubscribe endpoint (/u/{token}).
 *
 * GET only shows a confirmation form, so link scanners and prefetchers
 * cannot unsubscribe anyone. POST applies it; a POST with body
 * "List-Unsubscribe=One-Click" is the RFC 8058 one-click request from the
 * mailbox provider. The token itself authorizes the request (no session,
 * no CSRF cookie on this stateless route).
 */
final class UnsubscribeController extends Controller
{
    public function show(string $token, UnsubscribeTokens $tokens): Response
    {
        try {
            $tokens->verify($token);
        } catch (InvalidUnsubscribeToken) {
            return $this->page('Bağlantı geçersiz', 'Bu abonelikten çıkma bağlantısı geçersiz veya süresi dolmuş.', 410);
        }

        $action = e(url('/u/'.$token));

        return $this->page('Abonelikten çık', 'Pazarlama e-postalarını artık almak istemiyorsanız onaylayın.',
            200, "<form method=\"post\" action=\"{$action}\"><button type=\"submit\">Abonelikten çık</button></form>");
    }

    public function apply(Request $request, string $token, Unsubscribes $unsubscribes): Response
    {
        $oneClick = trim($request->getContent()) === 'List-Unsubscribe=One-Click' || $request->input('List-Unsubscribe') === 'One-Click';

        try {
            $unsubscribes->apply($token, $oneClick ? 'one_click' : 'link');
        } catch (InvalidUnsubscribeToken) {
            return $this->page('Bağlantı geçersiz', 'Bu abonelikten çıkma bağlantısı geçersiz veya süresi dolmuş.', 410);
        }

        return $this->page('Abonelikten çıkıldı', 'Pazarlama e-postaları listesinden çıkarıldınız.', 200);
    }

    private function page(string $title, string $text, int $status, string $extra = ''): Response
    {
        $html = '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
            .'<title>'.e($title).'</title></head><body style="font-family:system-ui,sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem">'
            .'<h1>'.e($title).'</h1><p>'.e($text).'</p>'.$extra.'</body></html>';

        return response($html, $status, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
