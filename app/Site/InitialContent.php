<?php

namespace App\Site;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starting content of the public website, installed once (missing slugs
 * only; never overwrites what the panel changed).
 *
 * Rules for this text: it describes what PratikTools builds and how. No
 * client, reference, success rate, revenue or other result is claimed;
 * case studies are drafts with no client data until an operator writes
 * and publishes them.
 */
final class InitialContent
{
    public const SERVICE_CATEGORIES = [
        'e-ticaret-entegrasyonlari' => 'E-Ticaret Entegrasyonları',
        'ticimax-entegrasyonlari' => 'Ticimax Entegrasyonları',
        'pazaryeri-entegrasyonlari' => 'Pazaryeri Entegrasyonları',
        'erp-nebim-v3-entegrasyonlari' => 'ERP / Nebim V3 Entegrasyonları',
        'api-ve-otomasyon' => 'API ve Otomasyon',
        'pazarlama-otomasyonu' => 'Pazarlama Otomasyonu',
        'e-posta-otomasyonu' => 'E-posta Otomasyonu',
        'push-notification' => 'Push Notification',
        'iys-via-entegrasyonu' => 'İYS / ViA Entegrasyonu',
        'yapay-zeka-cozumleri' => 'Yapay Zeka Çözümleri',
        'ai-agent-is-akisi-otomasyonu' => 'AI Agent / İş Akışı Otomasyonu',
        'ozel-yazilim' => 'Özel Yazılım',
        'veri-ve-raporlama' => 'Veri ve Raporlama',
        'seo-teknik-seo' => 'SEO / Teknik SEO',
        'web-uygulamalari' => 'Web Uygulamaları',
    ];

    public const ARTICLE_CATEGORIES = [
        'ticimax' => 'Ticimax', 'nebim' => 'Nebim', 'e-ticaret' => 'E-Ticaret', 'otomasyon' => 'Otomasyon', 'yapay-zeka' => 'Yapay Zeka',
        'marketing-automation' => 'Marketing Automation', 'iys' => 'İYS', 'api' => 'API', 'teknik-seo' => 'Teknik SEO',
    ];

    public const CASE_STUDY_DRAFTS = [
        'ticimax-nebim-v3-entegrasyonu' => 'Ticimax + Nebim V3 entegrasyonu',
        'stok-ve-urun-senkronizasyonu' => 'Stok ve ürün senkronizasyonu',
        'pazaryeri-entegrasyonlari' => 'Pazaryeri entegrasyonları',
        'marketing-automation-saas' => 'Marketing automation SaaS',
        'amazon-ses-e-posta-altyapisi' => 'Amazon SES e-posta altyapısı',
        'onesignal-push-altyapisi' => 'OneSignal push altyapısı',
        'iys-via-consent-entegrasyonu' => 'İYS ViA consent entegrasyonu',
        'seo-search-console-migration' => 'SEO / Search Console migration',
        'veri-raporlama-ve-operasyon-dashboardlari' => 'Veri raporlama ve operasyon dashboard\'ları',
        'ai-destekli-analiz-ve-operasyon' => 'AI destekli analiz / operasyon sistemleri',
    ];

    /**
     * Installs whatever is missing. Returns the number of rows created.
     */
    public static function install(): int
    {
        $created = 0;
        $now = now();
        $cat = [];
        foreach (['service' => self::SERVICE_CATEGORIES, 'article' => self::ARTICLE_CATEGORIES] as $kind => $list) {
            $i = 0;
            foreach ($list as $slug => $name) {
                $id = DB::table('site_categories')->where('kind', $kind)->where('slug', $slug)->value('id');
                if ($id === null) {
                    $id = (string) Str::uuid7();
                    DB::table('site_categories')->insert(['id' => $id, 'kind' => $kind, 'name' => $name, 'slug' => $slug, 'sort_order' => $i, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
                    $created++;
                }
                $cat[$kind.':'.$slug] = (string) $id;
                $i++;
            }
        }

        $order = 0;
        foreach ([...self::pages(), ...self::services(), ...self::solutions()] as $c) {
            if (DB::table('site_contents')->where('type', $c['type'])->where('slug', $c['slug'])->exists()) {
                continue;
            }
            DB::table('site_contents')->insert(['id' => (string) Str::uuid7(), 'type' => $c['type'], 'slug' => $c['slug'], 'title' => $c['title'], 'excerpt' => $c['excerpt'],
                'body' => $c['body'] ?? '', 'data' => json_encode($c['data'] ?? [], JSON_UNESCAPED_UNICODE), 'category_id' => isset($c['category']) ? ($cat['service:'.$c['category']] ?? null) : null,
                'status' => ContentStatus::Published->value, 'seo_title' => $c['seo_title'] ?? null, 'seo_description' => $c['seo_description'] ?? null,
                'sort_order' => $order++, 'published_at' => $now, 'created_by' => 'system:initial-content', 'updated_by' => 'system:initial-content', 'published_by' => 'system:initial-content',
                'created_at' => $now, 'updated_at' => $now]);
            $created++;
        }

        foreach (self::CASE_STUDY_DRAFTS as $slug => $title) {
            if (DB::table('site_contents')->where('type', 'project')->where('slug', $slug)->exists()) {
                continue;
            }
            DB::table('site_contents')->insert(['id' => (string) Str::uuid7(), 'type' => 'project', 'slug' => $slug, 'title' => $title, 'excerpt' => '',
                'body' => 'Taslak. Gerçek proje bilgileriyle doldurulmalı; müşteri adı, metrik veya sonuç yalnızca doğrulandıktan ve izin alındıktan sonra yazılır.',
                'data' => json_encode(['client_display' => '', 'client_named' => false], JSON_UNESCAPED_UNICODE), 'status' => ContentStatus::Draft->value,
                'created_by' => 'system:initial-content', 'updated_by' => 'system:initial-content', 'created_at' => $now, 'updated_at' => $now]);
            $created++;
        }
        SiteContents::bump();

        return $created;
    }

    /**
     * The home page when its record is missing (never empty).
     *
     * @return array<string, mixed>
     */
    public static function fallbackHome(): array
    {
        $p = self::pages()[0];

        return ['id' => '', 'type' => 'page', 'slug' => 'home', 'title' => $p['title'], 'excerpt' => $p['excerpt'], 'data' => $p['data'], 'url' => '/',
            'body' => '', 'seo_title' => $p['seo_title'], 'seo_description' => $p['seo_description'], 'image' => null, 'related' => [], 'referenced_by' => []];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function fallbackPage(string $slug): ?array
    {
        foreach (self::pages() as $p) {
            if ($p['slug'] === $slug && $slug !== 'home') {
                return ['id' => '', 'type' => 'page', 'slug' => $slug, 'title' => $p['title'], 'excerpt' => $p['excerpt'], 'data' => $p['data'], 'url' => SiteQuery::PAGE_PATHS[$slug],
                    'body' => $p['body'] ?? '', 'seo_title' => $p['seo_title'] ?? null, 'seo_description' => $p['seo_description'] ?? null, 'image' => null, 'related' => [], 'referenced_by' => []];
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function pages(): array
    {
        return [
            ['type' => 'page', 'slug' => 'home', 'title' => 'PratikTools',
                'seo_title' => 'PratikTools — E-Ticaret Entegrasyonu, Otomasyon ve Yapay Zeka',
                'seo_description' => 'Ticimax, Nebim V3, pazaryerleri, e-posta, push ve İYS için entegrasyon ve otomasyon; yapay zeka ile iş süreçleri. İşletmenizin gerçek sistemlerine bağlı çözümler.',
                'excerpt' => 'E-ticaret, otomasyon ve yapay zekayı işletmenizin gerçek sistemlerine bağlıyoruz.',
                'data' => [
                    'hero_title' => 'E-ticaret, otomasyon ve yapay zekayı işletmenizin gerçek sistemlerine bağlıyoruz.',
                    'hero_text' => 'Ticimax, Nebim V3, pazaryerleri, e-posta ve push altyapıları arasında veriyi doğru, izlenebilir ve güvenli şekilde taşıyan entegrasyonlar; elle yapılan işleri azaltan otomasyonlar ve gerçek veriye dayanan yapay zeka uygulamaları geliştiriyoruz.',
                    'capabilities_intro' => 'Tek bir ürün satmıyoruz; sistemleriniz arasındaki boşlukları kapatan yazılımlar geliştiriyoruz. Başlıca çalışma alanlarımız:',
                    'integrations' => ['Ticimax', 'Nebim V3', 'Trendyol ve diğer pazaryerleri', 'Amazon Web Services (SES)', 'OneSignal', 'İYS / ViA', 'Google Search Console', 'Google Analytics', 'Meta', 'PostgreSQL', 'Laravel'],
                    'how_we_work' => [
                        ['title' => 'Analiz', 'text' => 'Mevcut sistemleri, veri akışlarını ve elle yapılan işleri birlikte çıkarıyoruz; neyin otomatikleşeceğini ve riskleri netleştiriyoruz.'],
                        ['title' => 'Entegrasyon', 'text' => 'Sistemleri resmi API\'leri üzerinden, hata ve tekrar senaryolarını düşünerek bağlıyoruz; veriyi doğrulayarak taşıyoruz.'],
                        ['title' => 'Otomasyon', 'text' => 'Tekrarlayan işleri zamanlanmış, izlenebilir ve geri alınabilir iş akışlarına çeviriyoruz.'],
                        ['title' => 'Ölçüm', 'text' => 'Her akış için kayıt, uyarı ve rapor kuruyoruz; neyin çalıştığını ve neyin iyileşmesi gerektiğini görünür kılıyoruz.'],
                    ],
                    'cta_title' => 'Projenizi anlatın',
                    'cta_text' => 'Hangi sistemleri kullandığınızı ve neyi çözmek istediğinizi kısaca yazın; size uygulanabilir bir yol haritasıyla dönelim.',
                ]],
            ['type' => 'page', 'slug' => 'hakkimizda', 'title' => 'Hakkımızda',
                'seo_description' => 'PratikTools; e-ticaret entegrasyonları, iş süreci otomasyonu ve yapay zeka uygulamaları geliştiren bir yazılım ve entegrasyon ekibidir.',
                'excerpt' => 'PratikTools, işletmelerin kullandığı gerçek sistemleri birbirine bağlayan yazılımlar geliştirir.',
                'data' => ['hero_title' => 'Hakkımızda', 'hero_text' => 'Entegrasyon, otomasyon ve yapay zekayı, işletmenin gerçekten kullandığı sistemlerin üzerine kuruyoruz.'],
                'body' => "PratikTools, Bilal Şimşek'in yürüttüğü e-ticaret entegrasyonu, otomasyon ve yapay zeka çalışmalarının çatısıdır. Odak noktamız; e-ticaret altyapıları, ERP sistemleri, pazaryerleri ve pazarlama kanalları arasında veriyi doğru ve izlenebilir şekilde taşımak ve tekrarlayan işleri otomatikleştirmektir.\n\n## Nasıl çalışırız\n\n- **Gerçek sistemlerle başlarız.** Kullandığınız platformların resmi API'lerini ve kısıtlarını inceleyerek tasarlarız.\n- **Güvenliği baştan ele alırız.** Kimlik bilgileri şifreli saklanır, kişisel veriler ayrıştırılır, her kritik işlem kayıt altına alınır.\n- **Önce test, sonra canlı.** Değişiklikler test ortamında ve sınırlı kapsamda denenir; canlıya kontrollü geçilir.\n- **Ölçülebilir olanı yaparız.** Her entegrasyon ve otomasyon için izleme ve raporlama kurarız.\n\n## Neler yapıyoruz\n\nE-ticaret ve ERP entegrasyonları, pazaryeri bağlantıları, pazarlama ve e-posta otomasyonu, push bildirim altyapısı, İYS uyumlu izin yönetimi, veri ve raporlama, teknik SEO ve yapay zeka destekli iş akışları."],
            ['type' => 'page', 'slug' => 'iletisim', 'title' => 'İletişim',
                'seo_description' => 'PratikTools ile iletişime geçin: teklif alın, görüşme talep edin veya projenizi anlatın.',
                'excerpt' => 'Projenizi anlatın, teklif alın veya bir görüşme planlayalım.',
                'data' => ['hero_title' => 'Projenizi anlatın', 'hero_text' => 'Kullandığınız sistemleri ve çözmek istediğiniz sorunu kısaca yazın. Talebinizi inceleyip size dönüş yapacağız.'],
                'body' => ''],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function services(): array
    {
        return [
            ['type' => 'service', 'category' => 'ticimax-entegrasyonlari', 'slug' => 'ticimax-entegrasyonlari', 'title' => 'Ticimax Entegrasyonları',
                'seo_title' => 'Ticimax Entegrasyonu — ERP, Pazaryeri ve Otomasyon',
                'excerpt' => 'Ticimax mağazanızı ERP, pazaryeri, e-posta ve raporlama sistemlerinize Ticimax\'in resmi servisleri üzerinden bağlıyoruz.',
                'data' => [
                    'hero_title' => 'Ticimax mağazanızı diğer sistemlerinize güvenle bağlayın',
                    'hero_text' => 'Ürün, stok, fiyat, sipariş, müşteri ve kampanya verisini Ticimax ile diğer sistemleriniz arasında doğru ve izlenebilir şekilde taşıyoruz.',
                    'problem' => 'Ticimax üzerindeki ürün, stok ve sipariş verisi çoğu zaman ERP, pazaryeri ve pazarlama araçlarıyla elle veya dosya aktarımıyla eşitlenir. Bu da gecikmeye, stok hatalarına ve tekrarlayan iş yüküne yol açar.',
                    'approach' => 'Ticimax\'in resmi web servislerini kullanırız. Senkronizasyonları parçalı (sayfalı) ve tekrar denenebilir kurgularız; her çalışmanın kaydını tutar, hatalı kayıtları ayırır ve raporlarız. Yazma işlemleri (stok, fiyat, ürün güncellemesi) önce sınırlı kapsamda test edilir.',
                    'benefits' => ['Elle veri aktarımının azalması', 'Stok ve fiyat tutarlılığı', 'Hataların görünür ve izlenebilir olması', 'Tekrar çalıştırılabilir, güvenli senkronizasyon'],
                    'features' => ['Ürün, varyant, stok ve fiyat senkronizasyonu', 'Sipariş ve müşteri verisinin aktarımı', 'Sepet ve davranış verisinin pazarlama otomasyonuna aktarımı', 'Çalışma kayıtları, hata raporları ve uyarılar'],
                    'systems' => ['Ticimax', 'Nebim V3', 'Pazaryerleri', 'E-posta ve push altyapıları', 'Raporlama veritabanları'],
                    'use_cases' => ['ERP\'deki stok değişikliklerinin Ticimax\'e düzenli aktarılması', 'Ticimax siparişlerinin ERP\'ye ve raporlama veritabanına alınması', 'Terk edilen sepet verisinin e-posta otomasyonunda kullanılması'],
                    'faq' => [
                        ['q' => 'Ticimax ile hangi yöntemle entegre oluyorsunuz?', 'a' => 'Ticimax\'in sunduğu resmi web servislerini kullanıyoruz; ekran kazıma gibi kırılgan yöntemler kullanmıyoruz.'],
                        ['q' => 'Mevcut mağazamı etkiler mi?', 'a' => 'Okuma işlemleriyle başlıyoruz. Mağazaya yazan işlemler önce sınırlı kapsamda test edilir ve sizin onayınızla devreye alınır.'],
                        ['q' => 'Senkronizasyon ne sıklıkla çalışır?', 'a' => 'İhtiyaca göre belirlenir; stok gibi kritik veriler daha sık, raporlama verileri daha seyrek çalışabilir. Her çalışma kaydedilir.'],
                    ],
                    'cta_label' => 'Ticimax projenizi anlatın', 'cta_text' => 'Hangi verinin hangi sisteme taşınması gerektiğini yazın; uygulanabilir bir plan önerelim.',
                ],
                'body' => "## Ticimax entegrasyonunda nelere dikkat ediyoruz\n\nTicimax mağazaları genellikle bir ERP, bir veya birden fazla pazaryeri ve pazarlama araçlarıyla birlikte çalışır. Bu sistemler arasında veri taşırken üç şeye dikkat ediyoruz: **doğruluk**, **tekrar edilebilirlik** ve **izlenebilirlik**.\n\n- Her senkronizasyon kaldığı yerden devam edebilir; yarıda kalan bir çalışma veriyi bozmaz.\n- Eşleşmeyen veya hatalı kayıtlar sessizce atlanmaz, raporlanır.\n- Mağazaya yazan işlemler (stok, fiyat, ürün) ayrı yetki ve kontrollerle çalışır."],
            ['type' => 'service', 'category' => 'erp-nebim-v3-entegrasyonlari', 'slug' => 'nebim-v3-entegrasyonlari', 'title' => 'Nebim V3 Entegrasyonları',
                'seo_title' => 'Nebim V3 Entegrasyonu — E-Ticaret ve Pazaryeri Bağlantıları',
                'excerpt' => 'Nebim V3 ERP\'nizi e-ticaret sitenize, pazaryerlerine ve raporlama sistemlerinize bağlıyoruz: ürün, stok, fiyat, sipariş ve muhasebe akışları.',
                'data' => [
                    'hero_title' => 'Nebim V3 verinizi e-ticarette doğru kullanın',
                    'hero_text' => 'Ürün kartları, barkodlar, renk-beden varyantları, stok ve fiyatları Nebim V3 ile satış kanallarınız arasında tutarlı tutuyoruz.',
                    'problem' => 'Nebim V3\'teki ürün ve stok yapısı (barkod, renk, beden) satış kanallarındaki yapıyla birebir örtüşmez. Eşleştirme hataları, yanlış stok ve elle düzeltme ihtiyacı doğurur.',
                    'approach' => 'Eşleştirmeyi barkod gibi kararlı anahtarlar üzerinden kurarız; renk ve beden eşleştirmelerini tablolarla yönetilebilir yaparız. Negatif stok gibi riskli değerleri kanallara göndermeyiz, hatalı kayıtları ve sebeplerini raporlarız.',
                    'benefits' => ['Barkod tabanlı, kararlı ürün eşleştirme', 'Satış kanallarında doğru stok', 'Renk/beden eşleştirmelerinin yönetilebilir olması', 'Hata sebeplerinin görünür olması'],
                    'features' => ['Ürün, varyant ve barkod eşleştirme', 'Stok ve fiyat aktarımı', 'Sipariş aktarımı', 'Pazaryeri giderlerinin içe aktarımı ve raporlama'],
                    'systems' => ['Nebim V3', 'Ticimax', 'Pazaryerleri', 'Raporlama veritabanları'],
                    'use_cases' => ['Nebim\'deki stokların e-ticaret sitesine ve pazaryerlerine aktarılması', 'Renk kodlarının satış kanallarındaki renk seçenekleriyle eşleştirilmesi', 'Pazaryeri masraflarının Nebim tarafında raporlanabilir hale getirilmesi'],
                    'faq' => [
                        ['q' => 'Nebim V3 ile nasıl bağlantı kuruyorsunuz?', 'a' => 'Kurulumunuzun sunduğu entegrasyon yöntemine göre (servis veya veritabanı erişimi) çalışırız; yetkileri en az gereken seviyede tutarız.'],
                        ['q' => 'Renk ve beden eşleştirmesi nasıl yönetiliyor?', 'a' => 'Eşleştirmeler kod içinde değil, yönetilebilir tablolarda tutulur; eşleşmeyen kayıtlar raporlanır.'],
                    ],
                    'cta_label' => 'Nebim entegrasyonunu konuşalım',
                ],
                'body' => "## Neden barkod tabanlı eşleştirme\n\nÜrün adları ve renk isimleri kanaldan kanala değişir; barkod ise değişmez. Eşleştirmeyi barkod üzerine kurmak, isim farklarından doğan hataları ortadan kaldırır. Eşleşmeyen kayıtlar ayrı bir listede raporlanır ve düzeltilene kadar kanala gönderilmez."],
            ['type' => 'service', 'category' => 'pazarlama-otomasyonu', 'slug' => 'pazarlama-otomasyonu', 'title' => 'Pazarlama Otomasyonu',
                'seo_title' => 'Pazarlama Otomasyonu — E-posta, Push, Segment ve Kampanya',
                'excerpt' => 'Müşteri ve sipariş verisine dayalı segmentler, otomatik akışlar, e-posta ve push kampanyaları; izin (İYS) ve gönderim güvenliği baştan tasarlanmış şekilde.',
                'data' => [
                    'hero_title' => 'Doğru kişiye, doğru zamanda, izinli iletişim',
                    'hero_text' => 'E-ticaret verinizi segmentlere ve otomatik akışlara dönüştürüyor; e-posta ve push gönderimlerini izin ve güvenlik kurallarıyla birlikte kuruyoruz.',
                    'problem' => 'Pazarlama araçları çoğu zaman e-ticaret verisinden kopuktur; segmentler elle hazırlanır, izin durumları (İYS) güncel değildir ve gönderimler kontrolsüz olabilir.',
                    'approach' => 'Müşteri, sipariş ve sepet verisini tek modelde birleştirir; segmentleri kurallarla tanımlarız. Her gönderimden hemen önce izin, engelleme listesi ve sıklık sınırı yeniden kontrol edilir. Kampanyalar onay adımlarından geçer; canlı gönderim kontrollü açılır.',
                    'benefits' => ['Veriye dayalı segmentler', 'İzin ve engelleme kurallarının gönderim anında uygulanması', 'Onay akışlı kampanya yönetimi', 'Tekrarlayan kampanyaların otomatikleşmesi'],
                    'features' => ['Segmentler ve kitle önizleme', 'Terk edilen sepet, yeni üye, tekrar satın alma gibi otomatik akışlar', 'E-posta ve push kampanyaları', 'İYS uyumlu izin yönetimi ve abonelikten çıkma', 'Test listesiyle güvenli deneme gönderimleri'],
                    'systems' => ['Ticimax', 'Amazon SES', 'OneSignal', 'İYS / ViA'],
                    'use_cases' => ['Sepetini terk eden üyeye hatırlatma akışı', 'Belirli kategoriden alışveriş yapanlara yeni ürün duyurusu', 'Bülten kaydı yapan ziyaretçiye karşılama akışı'],
                    'faq' => [
                        ['q' => 'İYS izinleri nasıl dikkate alınıyor?', 'a' => 'Ticari elektronik ileti gönderiminden hemen önce alıcının izin durumu kontrol edilir; izni doğrulanamayan alıcıya gönderim yapılmaz.'],
                        ['q' => 'Abonelikten çıkan kişi tekrar ileti alabilir mi?', 'a' => 'Hayır. Abonelikten çıkma anında yerel engelleme uygulanır ve kalkması ayrı, kayıtlı bir işlem gerektirir.'],
                    ],
                    'cta_label' => 'Pazarlama otomasyonunu konuşalım',
                ],
                'body' => "## Güvenli gönderim prensibi\n\nBir kampanyanın hazırlandığı an ile gönderildiği an arasında alıcıların izin durumu değişebilir. Bu yüzden izin, engelleme ve sıklık kontrollerini kitle hazırlanırken değil, **her gönderimden hemen önce** yeniden yapıyoruz."],
            ['type' => 'service', 'category' => 'yapay-zeka-cozumleri', 'slug' => 'yapay-zeka-ve-is-sureci-otomasyonu', 'title' => 'Yapay Zeka ve İş Süreci Otomasyonu',
                'seo_title' => 'Yapay Zeka ve İş Süreci Otomasyonu — AI Agent ve İş Akışları',
                'excerpt' => 'Yapay zekayı genel sohbet aracı olarak değil, şirket verinize ve sistemlerinize bağlı, denetlenebilir iş akışları olarak kuruyoruz.',
                'data' => [
                    'hero_title' => 'Yapay zekayı gerçek iş akışlarınıza bağlayın',
                    'hero_text' => 'Rapor hazırlama, veri sınıflandırma, içerik üretimi ve operasyon takibi gibi işleri; sistemlerinizden veri okuyan ve insan onayıyla çalışan yapay zeka akışlarına dönüştürüyoruz.',
                    'problem' => 'Yapay zeka araçları çoğu zaman şirket verisinden kopuk kullanılır; sonuçlar kontrol edilemez, tekrar üretilemez ve süreçlere bağlanamaz.',
                    'approach' => 'Yapay zeka adımlarını belirli girdiler, açık talimatlar ve doğrulanabilir çıktılarla tasarlarız. Kritik adımlarda insan onayı bulunur; her çalışma kaydedilir. Kişisel ve gizli veriler gereksiz yere modele gönderilmez.',
                    'benefits' => ['Tekrarlayan analiz ve raporlama işlerinin hızlanması', 'Denetlenebilir ve tekrar üretilebilir sonuçlar', 'Mevcut sistemlerle entegre çalışma', 'Veri gizliliğinin korunması'],
                    'features' => ['Veri sınıflandırma ve özetleme akışları', 'Operasyon ve satış verisi üzerinde analiz', 'İçerik taslağı üretimi ve kontrol akışları', 'AI agent ile çok adımlı iş akışları'],
                    'systems' => ['E-ticaret ve ERP verileri', 'Raporlama veritabanları', 'Büyük dil modeli API\'leri'],
                    'use_cases' => ['Ürün açıklaması taslaklarının hazırlanıp editör onayına sunulması', 'Müşteri yorumlarının konu ve duyguya göre sınıflandırılması', 'Günlük operasyon verisinden özet rapor çıkarılması'],
                    'faq' => [
                        ['q' => 'Verilerimiz yapay zeka modeline gönderiliyor mu?', 'a' => 'Yalnızca görevin gerektirdiği veri, gerekli olduğu kadar gönderilir; kişisel veriler mümkün olduğunca ayıklanır veya maskelenir.'],
                        ['q' => 'Sonuçların doğruluğu nasıl kontrol ediliyor?', 'a' => 'Çıktılar tanımlı kurallarla doğrulanır; kritik adımlarda yayına veya sisteme yazmadan önce insan onayı alınır.'],
                    ],
                    'cta_label' => 'Yapay zeka fikrinizi anlatın',
                ]],
            ['type' => 'service', 'category' => 'api-ve-otomasyon', 'slug' => 'api-ozel-entegrasyon', 'title' => 'API / Özel Entegrasyon',
                'seo_title' => 'API ve Özel Entegrasyon Geliştirme',
                'excerpt' => 'Hazır bağlantısı olmayan sistemleri resmi API\'leri üzerinden birbirine bağlıyor; güvenli, izlenebilir ve tekrar denenebilir entegrasyonlar geliştiriyoruz.',
                'data' => [
                    'hero_title' => 'Hazır entegrasyonu olmayan sistemleri bağlıyoruz',
                    'hero_text' => 'İki sistem arasında hazır bir bağlantı yoksa, resmi API\'leri üzerinden size özel, bakımı yapılabilir bir entegrasyon geliştiriyoruz.',
                    'problem' => 'Hazır entegrasyon eklentileri çoğu zaman ihtiyacın bir kısmını karşılar; hata durumlarını, yeniden denemeyi ve veri doğrulamayı ele almaz.',
                    'approach' => 'Her entegrasyonu kimlik bilgilerinin güvenli saklanması, hız sınırlarına uyum, yeniden deneme, idempotency (aynı işlemin iki kez uygulanmaması) ve ayrıntılı kayıt ile tasarlarız.',
                    'benefits' => ['İhtiyaca birebir uyan entegrasyon', 'Hata durumlarında veri kaybı yaşanmaması', 'Bakımı yapılabilir, belgelenmiş kod', 'Güvenli kimlik bilgisi yönetimi'],
                    'features' => ['REST, SOAP ve webhook entegrasyonları', 'Zamanlanmış ve olay tabanlı senkronizasyon', 'Kuyruk ile ölçeklenebilir işleme', 'Webhook doğrulama ve imza kontrolü'],
                    'systems' => ['E-ticaret platformları', 'ERP sistemleri', 'Pazaryerleri', 'Bulut servisleri (AWS vb.)'],
                    'use_cases' => ['İki sistem arasında sipariş akışının kurulması', 'Bir servisten gelen olayların (webhook) güvenle işlenmesi', 'Toplu veri aktarımlarının kuyruk ile parçalara bölünmesi'],
                    'faq' => [
                        ['q' => 'Hangi teknolojilerle çalışıyorsunuz?', 'a' => 'Ağırlıklı olarak PHP/Laravel, PostgreSQL, Redis ve bulut servisleriyle çalışıyoruz; mevcut altyapınıza uyum sağlarız.'],
                        ['q' => 'Entegrasyonun bakımı nasıl yapılıyor?', 'a' => 'Kod belgelenir, testlerle korunur ve izleme/uyarı ile birlikte teslim edilir.'],
                    ],
                ]],
            ['type' => 'service', 'category' => 'seo-teknik-seo', 'slug' => 'teknik-seo', 'title' => 'Teknik SEO',
                'seo_title' => 'Teknik SEO — Site Taşıma, Search Console ve Yapısal Veri',
                'excerpt' => 'Site taşıma, yönlendirme, indekslenme, yapısal veri ve sayfa hızı gibi teknik SEO işlerini veriye dayalı ve kontrollü şekilde yürütüyoruz.',
                'data' => [
                    'hero_title' => 'Arama motorlarının sitenizi doğru anlamasını sağlayın',
                    'hero_text' => 'Teknik SEO; sitenizin taranabilir, indekslenebilir ve hızlı olmasıyla ilgilidir. Bu işleri Search Console verisiyle ölçerek yürütüyoruz.',
                    'problem' => 'Site taşıma, alan adı değişikliği veya platform geçişi sırasında yanlış yönlendirmeler ve indekslenme sorunları trafik kaybına yol açabilir.',
                    'approach' => 'Mevcut URL envanterini çıkarır, yönlendirme haritasını hazırlar, geçişi Search Console üzerinden takip ederiz. Yapısal veriyi (schema.org) yalnızca sayfada gerçekten görünen içerikle uyumlu üretiriz.',
                    'benefits' => ['Taşıma sırasında trafik kaybı riskinin azalması', 'İndekslenme sorunlarının görünür olması', 'Doğru yapısal veri', 'Daha hızlı sayfalar'],
                    'features' => ['URL envanteri ve yönlendirme haritası', 'Search Console takibi', 'robots.txt ve sitemap yönetimi', 'Yapısal veri (schema.org)', 'Core Web Vitals iyileştirmeleri'],
                    'systems' => ['Google Search Console', 'Google Analytics', 'E-ticaret platformları'],
                    'use_cases' => ['Alan adı veya platform değişikliğinde yönlendirme planı', 'Ürün sayfalarına doğru yapısal veri eklenmesi', 'Yavaş sayfaların tespiti ve iyileştirilmesi'],
                    'faq' => [
                        ['q' => 'Sıralama garantisi veriyor musunuz?', 'a' => 'Hayır. Arama sonuçlarını arama motorları belirler; biz teknik engelleri kaldırır ve sonuçları veriyle izleriz.'],
                        ['q' => 'Yapay zeka arama motorları için ne yapıyorsunuz?', 'a' => 'İçeriğin sunucu tarafında, okunabilir HTML olarak sunulmasını ve tarayıcıların erişim kurallarının açıkça yönetilmesini sağlıyoruz.'],
                    ],
                ]],
            ['type' => 'service', 'category' => 'e-posta-otomasyonu', 'slug' => 'e-posta-altyapisi-ve-otomasyonu', 'title' => 'E-posta Altyapısı ve Otomasyonu',
                'excerpt' => 'Amazon SES üzerinde alan adı doğrulaması (DKIM), geri dönme ve şikayet yönetimi, tek tıkla abonelikten çıkma ve güvenli şablonlarla e-posta altyapısı kuruyoruz.',
                'data' => [
                    'hero_title' => 'Teslim edilebilir, kontrollü e-posta altyapısı',
                    'hero_text' => 'Kendi alan adınızdan, doğrulanmış ve izlenen bir e-posta altyapısı ile işlem ve pazarlama e-postaları gönderin.',
                    'problem' => 'Yanlış yapılandırılmış e-posta altyapısı spam klasörüne düşme, alan adı itibarının zarar görmesi ve abonelikten çıkma taleplerinin kaçırılmasına yol açar.',
                    'approach' => 'Alan adı doğrulaması (DKIM), geri dönme ve şikayet olaylarının otomatik işlenmesi, tek tıkla abonelikten çıkma ve gönderim modları (kapalı / test / canlı) ile kontrollü bir altyapı kuruyoruz.',
                    'benefits' => ['Daha iyi teslim edilebilirlik', 'Geri dönen ve şikayet eden adreslerin otomatik engellenmesi', 'Yasal gerekliliklere uygun abonelikten çıkma', 'Kontrollü canlıya geçiş'],
                    'features' => ['Amazon SES kurulumu ve alan adı doğrulama', 'Bounce / complaint olaylarının işlenmesi', 'Tek tıkla abonelikten çıkma (RFC 8058)', 'Güvenli HTML şablonları'],
                    'systems' => ['Amazon SES', 'Amazon SNS', 'İYS'],
                    'use_cases' => ['Sipariş ve bilgilendirme e-postalarının kendi alan adınızdan gönderilmesi', 'Kampanya e-postalarının izin kontrolüyle gönderilmesi'],
                ]],
            ['type' => 'service', 'category' => 'push-notification', 'slug' => 'push-notification-altyapisi', 'title' => 'Push Notification Altyapısı',
                'excerpt' => 'OneSignal ile web ve mobil push aboneliklerini müşteri verinize güvenli şekilde eşleştiriyor, kişiselleştirilmiş bildirimleri kontrollü gönderiyoruz.',
                'data' => [
                    'hero_title' => 'Push bildirimlerini müşteri verinizle birleştirin',
                    'hero_text' => 'Abone cihazları doğru müşteriyle eşleştirilmeden kişiselleştirilmiş bildirim gönderilmez; bu eşleştirmeyi güvenli şekilde kuruyoruz.',
                    'problem' => 'Push abonelikleri çoğu zaman anonimdir; yanlış eşleştirme yanlış kişiye kişisel içerik gönderilmesine yol açabilir.',
                    'approach' => 'Yalnızca güvenilir kimlik eşleştirmelerini kullanır, eşleşmeyen abonelikleri kişiselleştirilmiş gönderimlerden hariç tutarız. Gönderimler test listesi ve gönderim modlarıyla kontrollü açılır.',
                    'benefits' => ['Yanlış kişiye kişisel bildirim riskinin önlenmesi', 'Segment bazlı bildirimler', 'Kontrollü gönderim'],
                    'features' => ['OneSignal abonelik senkronizasyonu', 'Güvenli kimlik eşleştirme', 'Segment bazlı push kampanyaları', 'Test listesiyle deneme gönderimleri'],
                    'systems' => ['OneSignal', 'Ticimax', 'Mobil uygulamalar'],
                ]],
            ['type' => 'service', 'category' => 'iys-via-entegrasyonu', 'slug' => 'iys-via-entegrasyonu', 'title' => 'İYS / ViA Entegrasyonu',
                'excerpt' => 'Ticari elektronik ileti izinlerini İYS ile uyumlu yönetiyoruz: ViA ile izin alma, abonelikten çıkma ve günlük mutabakat süreçleri.',
                'data' => [
                    'hero_title' => 'İzin yönetimini İYS ile uyumlu kurun',
                    'hero_text' => 'İzni doğrulanamayan alıcıya ticari ileti gönderilmez; abonelikten çıkma anında uygulanır ve kayıt altına alınır.',
                    'problem' => 'İzin durumları farklı sistemlerde dağınık tutulduğunda, izni olmayan alıcıya ileti gönderme riski doğar.',
                    'approach' => 'İzinleri kaynağıyla birlikte (İYS, ViA, mutabakat raporu, yerel abonelikten çıkma) kayıt altında tutarız. RET her zaman ONAY\'dan önce gelir; şüpheli durumda gönderim yapılmaz.',
                    'benefits' => ['İzin durumlarının tek yerden yönetilmesi', 'Abonelikten çıkmanın anında uygulanması', 'Kaynağı belli, denetlenebilir izin kayıtları'],
                    'features' => ['İYS ViA ile izin alma akışına hazırlık', 'Abonelikten çıkma ve RET süreçleri', 'İYS değişiklik raporlarıyla mutabakat', 'Gönderim anında izin kontrolü'],
                    'systems' => ['İYS', 'ViA', 'E-posta ve SMS altyapıları'],
                ]],
        ];
    }

    /**
     * Landing pages for ad campaigns.
     *
     * @return list<array<string, mixed>>
     */
    private static function solutions(): array
    {
        $base = fn (string $slug, string $title, string $excerpt, array $data, string $body = '') => ['type' => 'solution', 'slug' => $slug, 'title' => $title, 'excerpt' => $excerpt,
            'data' => $data + ['form_kind' => 'teklif'], 'body' => $body];

        return [
            $base('ticimax-nebim-entegrasyonu', 'Ticimax ve Nebim V3 Entegrasyonu', 'Ticimax mağazanızı Nebim V3 ile bağlayın: barkod tabanlı ürün eşleştirme, stok ve fiyat aktarımı, sipariş akışı.', [
                'hero_title' => 'Ticimax ve Nebim V3 arasında stok ve ürünleri elle eşitlemeyi bırakın',
                'hero_text' => 'Nebim V3\'teki ürün, stok ve fiyatlarınızı Ticimax mağazanıza; Ticimax siparişlerinizi Nebim\'e düzenli ve izlenebilir şekilde aktaran bir entegrasyon kuruyoruz.',
                'problem' => 'Ürün ve stok bilgisinin iki sistem arasında elle veya dosyayla taşınması, yanlış stokla satış yapılmasına ve ekiplerin zamanının tekrarlayan işlere gitmesine yol açar.',
                'approach' => 'Ürünleri barkod üzerinden eşleştirir, renk ve beden eşleştirmelerini yönetilebilir tablolarda tutar, senkronizasyonu tekrar denenebilir ve kayıtlı şekilde çalıştırırız. Riskli değerler (ör. negatif stok) gönderilmez.',
                'benefits' => ['Doğru stokla satış', 'Elle aktarımın ortadan kalkması', 'Hataların raporlanması'],
                'features' => ['Barkod tabanlı ürün eşleştirme', 'Stok ve fiyat aktarımı', 'Sipariş aktarımı', 'Renk / beden eşleştirme yönetimi', 'Çalışma ve hata raporları'],
                'systems' => ['Ticimax', 'Nebim V3'],
                'examples' => ['Nebim\'de değişen stokların belirli aralıklarla mağazaya yansıması', 'Yeni ürünlerin barkodla eşleşip eşleşmediğinin raporlanması'],
                'faq' => [
                    ['q' => 'Entegrasyon ne kadar sürede devreye alınır?', 'a' => 'Kapsama ve mevcut veri kalitesine göre değişir; ilk görüşmede veri yapınızı inceleyip bir plan çıkarıyoruz.'],
                    ['q' => 'Eşleşmeyen ürünler ne olur?', 'a' => 'Sessizce atlanmaz; ayrı bir raporda listelenir ve düzeltilene kadar mağazaya gönderilmez.'],
                ],
                'cta_label' => 'Teklif alın', 'cta_text' => 'Kullandığınız Ticimax ve Nebim yapısını kısaca anlatın.',
            ]),
            $base('e-ticaret-otomasyonu', 'E-Ticaret Otomasyonu', 'Sipariş, stok, ürün ve müşteri süreçlerindeki tekrarlayan işleri izlenebilir otomasyonlara dönüştürün.', [
                'hero_title' => 'E-ticaret operasyonunuzdaki tekrarlayan işleri otomatikleştirin',
                'hero_text' => 'Stok güncelleme, sipariş aktarımı, raporlama ve müşteri iletişimi gibi işleri zamanlanmış ve kayıtlı otomasyonlara çeviriyoruz.',
                'problem' => 'Büyüyen e-ticaret operasyonlarında ekipler zamanının önemli kısmını sistemler arası veri taşıma ve kontrol işlerine harcar.',
                'approach' => 'Önce süreçleri ve veri akışlarını çıkarır, en çok zaman alan ve en riskli adımlardan başlarız. Her otomasyon izlenebilir, hata durumunda uyarı veren ve gerektiğinde durdurulabilir şekilde kurulur.',
                'benefits' => ['Ekip zamanının değerli işlere kalması', 'Daha az insan hatası', 'Süreçlerin görünür olması'],
                'features' => ['Stok ve ürün senkronizasyonları', 'Sipariş ve iade akışları', 'Operasyon raporları ve uyarılar', 'Pazaryeri entegrasyonları'],
                'systems' => ['Ticimax', 'Nebim V3', 'Pazaryerleri'],
                'examples' => ['Günlük operasyon raporunun otomatik hazırlanması', 'Pazaryeri masraflarının ERP\'ye aktarılması'],
                'cta_label' => 'Projenizi anlatın',
            ]),
            $base('yapay-zeka-otomasyonu', 'Yapay Zeka Otomasyonu', 'Yapay zekayı şirket verinize bağlı, denetlenebilir iş akışlarına dönüştürün.', [
                'hero_title' => 'Yapay zekayı işletmenizin verisiyle çalıştırın',
                'hero_text' => 'Analiz, sınıflandırma, özetleme ve içerik taslağı gibi işleri; sistemlerinize bağlı ve insan onaylı yapay zeka akışlarıyla hızlandırıyoruz.',
                'problem' => 'Genel amaçlı yapay zeka araçları şirket verisine erişemez, sonuçları süreçlere bağlanamaz ve tekrar üretilemez.',
                'approach' => 'Yapay zeka adımlarını açık girdi/çıktılarla tasarlar, kritik adımlarda insan onayı ekler, her çalışmayı kaydederiz. Gizli ve kişisel veriler gereksiz yere modele gönderilmez.',
                'benefits' => ['Tekrarlayan analiz işlerinin hızlanması', 'Denetlenebilir sonuçlar', 'Veri gizliliği'],
                'features' => ['Veri sınıflandırma ve özetleme', 'Rapor ve içerik taslağı üretimi', 'Çok adımlı AI agent akışları'],
                'examples' => ['Müşteri yorumlarının konulara ayrılması', 'Ürün açıklaması taslaklarının editör onayına sunulması'],
                'cta_label' => 'Görüşme talep edin', 'form_kind' => 'gorusme',
            ]),
            $base('email-marketing-otomasyonu', 'E-posta Marketing Otomasyonu', 'Segmentler, otomatik akışlar ve izin kontrollü e-posta kampanyalarıyla e-ticaret verinizi iletişime dönüştürün.', [
                'hero_title' => 'E-posta pazarlamasını verinize ve izin kurallarına bağlayın',
                'hero_text' => 'Terk edilen sepet, karşılama ve tekrar satın alma akışlarını; İYS izinleri ve abonelikten çıkma kurallarıyla birlikte kuruyoruz.',
                'problem' => 'E-posta kampanyaları çoğu zaman e-ticaret verisinden kopuktur ve izin durumları gönderim anında kontrol edilmez.',
                'approach' => 'Segmentleri sipariş ve davranış verisinden oluşturur, her gönderimden hemen önce izin ve engelleme kontrolü yaparız. Kampanyalar onay adımlarından geçer, gönderim kontrollü açılır.',
                'benefits' => ['Veriye dayalı kampanyalar', 'İzin uyumlu gönderim', 'Otomatik akışlarla süreklilik'],
                'features' => ['Segmentler', 'Otomatik akışlar', 'Amazon SES altyapısı', 'Tek tıkla abonelikten çıkma', 'Onay akışlı kampanyalar'],
                'systems' => ['Amazon SES', 'Ticimax', 'İYS'],
                'examples' => ['Terk edilen sepet hatırlatması', 'Yeni bülten abonesine karşılama serisi'],
                'cta_label' => 'Demo talep edin', 'form_kind' => 'demo',
            ]),
        ];
    }
}
