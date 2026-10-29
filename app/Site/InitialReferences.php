<?php

namespace App\Site;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starting references, installed once (missing slugs only). They are
 * drafts with no client-name or logo approval: nothing becomes public
 * until an operator records the approvals and publishes in the panel.
 * The texts describe the work only; no results or claims.
 */
final class InitialReferences
{
    private const REFERENCES = [
        ['slug' => 'igs', 'name' => 'IGS', 'website' => null,
            'short' => 'E-Ticaret, ERP ve Pazarlama Otomasyonu',
            'body' => 'Ticimax ve Nebim V3 entegrasyonları, özel Ticimax geliştirmeleri, stok, sipariş ve veri akışları ile pazarlama otomasyon altyapılarının geliştirilmesi.',
            'capabilities' => ['E-Ticaret Otomasyonu', 'Nebim V3 Entegrasyonu', 'Ticimax Entegrasyonları', 'Özel Ticimax Geliştirmeleri', 'Veri Entegrasyonu', 'Pazarlama Otomasyonu'],
            'contents' => ['project:ticimax-nebim-v3-entegrasyonu', 'solution:e-ticaret-otomasyonu', 'service:nebim-v3-entegrasyonlari', 'service:ticimax-entegrasyonlari', 'service:pazarlama-otomasyonu']],
        ['slug' => 'dost-tv', 'name' => 'Dost TV', 'website' => null,
            'short' => 'Dijital Platform ve Kurum İçi Otomasyonlar',
            'body' => 'Kurumsal web altyapısı, interaktif online yarışma sistemleri ve kurum içi operasyonları dijitalleştiren özel yazılım ve otomasyon çözümleri.',
            'capabilities' => ['Web Platformu', 'Online Yarışma Sistemleri', 'Kurum İçi Otomasyon', 'Özel Yazılım'], 'contents' => []],
        ['slug' => 'dkm', 'name' => 'DKM', 'website' => null,
            'short' => 'Kurumsal Web Platformu',
            'body' => 'Kurumsal içeriklerin modern, yönetilebilir ve sürdürülebilir bir dijital platform üzerinde sunulmasına yönelik web uygulaması.',
            'capabilities' => ['Kurumsal Web', 'İçerik Yönetimi', 'Web Uygulaması'], 'contents' => []],
        ['slug' => 'n-extent', 'name' => 'N-Extent', 'website' => 'https://n-extent.com',
            'short' => 'İçerik ve Medya Web Uygulaması',
            'body' => 'İçerik yayınlama, video ve dijital medya süreçleri için geliştirilen modern web uygulaması.',
            'capabilities' => ['Web Uygulaması', 'İçerik Yönetimi', 'Video / Dijital Medya', 'Teknik SEO'], 'contents' => ['service:teknik-seo']],
        ['slug' => 'motioncurate', 'name' => 'MotionCurate', 'website' => 'https://motioncurate.com',
            'short' => 'Web ve Mobil Uygulama Platformu',
            'body' => 'Web ve mobil deneyimi birlikte sunan, çok platformlu özel yazılım ve uygulama geliştirme projesi.',
            'capabilities' => ['Web Uygulaması', 'Mobil Uygulama', 'Özel Yazılım', 'Cloud Sistemleri'], 'contents' => []],
        ['slug' => 'pazarradar', 'name' => 'PazarRadar', 'website' => 'https://pazarradar.com',
            'short' => 'E-Ticaret Veri ve Analiz Platformu',
            'body' => 'E-ticaret ve pazaryeri verilerinin izlenmesi, analiz edilmesi ve operasyonel aksiyona dönüştürülmesi için geliştirilen web uygulaması.',
            'capabilities' => ['E-Ticaret Veri Analizi', 'Pazaryeri Verileri', 'Web Uygulaması', 'Raporlama', 'Otomasyon'], 'contents' => []],
    ];

    public static function install(): int
    {
        $created = 0;
        foreach (self::REFERENCES as $i => $r) {
            if (DB::table('site_references')->where('slug', $r['slug'])->exists()) {
                continue;
            }
            $id = (string) Str::uuid7();
            DB::table('site_references')->insert(['id' => $id, 'slug' => $r['slug'], 'name' => $r['name'], 'website_url' => $r['website'], 'short_description' => $r['short'],
                'body' => $r['body'], 'capabilities' => json_encode($r['capabilities'], JSON_UNESCAPED_UNICODE), 'homepage_featured' => true, 'featured_order' => $i + 1,
                'active' => true, 'status' => ContentStatus::Draft->value, 'created_by' => 'system:initial-content', 'updated_by' => 'system:initial-content',
                'created_at' => now(), 'updated_at' => now()]);
            foreach ($r['contents'] as $p => $key) {
                [$type, $slug] = explode(':', $key, 2);
                $content = DB::table('site_contents')->where('type', $type)->where('slug', $slug)->value('id');
                if ($content !== null) {
                    DB::table('site_reference_contents')->insert(['reference_id' => $id, 'content_id' => $content, 'position' => $p]);
                }
            }
            $created++;
        }
        SiteContents::bump();

        return $created;
    }
}
