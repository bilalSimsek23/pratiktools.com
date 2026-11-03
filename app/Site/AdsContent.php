<?php

namespace App\Site;

use App\Audit\Actor;
use Illuminate\Support\Facades\DB;

/**
 * Google Ads readiness content (November 2026): two new landing pages, three
 * case studies, five blog drafts, and corrections/strengthening of existing
 * pages. Applied with `site:ads-content --apply` (dry run by default),
 * through SiteContents (validated, audited, cache bump).
 *
 * Panel edits win: an existing field changes only while it still holds its
 * starting value (InitialContent / MediaCatalog) or is empty; otherwise it
 * is reported as kept. Existing slugs are never recreated or overwritten.
 * Relations are only added. Blog posts stay drafts.
 *
 * Text rules: no client name or logo; the case-study client is anonymous.
 * No figure that is not verified (no savings, rates or growth). MotionCurate
 * is PratikTools' own product, never a client. Marketing automation is
 * described as development work, not as a ready SaaS product; consent uses
 * İYS's ViA Frame, never a direct İYS write integration.
 */
final class AdsContent
{
    private const CLIENT = 'Türkiye\'de faaliyet gösteren çok kanallı bir perakende markası';

    public function __construct(private readonly SiteContents $contents) {}

    /**
     * @return list<array{item: string, field: string, action: string}>
     *                                                                  action: create | publish | update | add | swap | already | kept | missing
     */
    public function run(bool $apply, Actor $actor): array
    {
        $report = [];
        $log = function (string $item, string $field, string $action) use (&$report) {
            $report[] = ['item' => $item, 'field' => $field, 'action' => $action];
        };

        DB::transaction(function () use ($apply, $actor, $log) {
            foreach (self::newItems() as $c) {
                $this->create($c, $apply, $actor, $log);
            }
            foreach (self::updates() as $key => $set) {
                $this->update($key, $set, $apply, $actor, $log);
            }
            foreach (self::relations() as $from => $targets) {
                $this->relate($from, $targets, $apply, $actor, $log);
            }
            $this->promote('solution:render-farm-kurulumu', 'solution:email-marketing-otomasyonu', $apply, $log);
        });

        return $report;
    }

    /**
     * @param  array<string, mixed>  $c
     */
    private function create(array $c, bool $apply, Actor $actor, \Closure $log): void
    {
        $key = "{$c['type']}:{$c['slug']}";
        if (DB::table('site_contents')->where('type', $c['type'])->where('slug', $c['slug'])->exists()) {
            $log($key, '*', 'already');

            return;
        }
        $type = ContentType::from($c['type']);
        $log($key, '*', 'create');
        if (! $apply) {
            $c['publish'] ? $log($key, 'status', 'publish') : null;

            return;
        }
        $id = $this->contents->create($type, $c['title'], $actor, $c['slug']);
        $category = isset($c['category']) ? DB::table('site_categories')->where('kind', (string) $type->categoryKind())->where('slug', $c['category'])->value('id') : null;
        $this->contents->update($id, self::input($type, $c['data']) + [
            'excerpt' => $c['excerpt'], 'body' => $c['body'] ?? '', 'seo_title' => $c['seo_title'] ?? '', 'seo_description' => $c['seo_description'] ?? '',
            'category_id' => $category ?? '', 'featured' => $c['featured'] ?? false, 'sort_order' => $c['sort_order'] ?? 0,
        ], $actor);
        if ($c['publish']) {
            $this->contents->publish($id, $actor);
            $log($key, 'status', 'publish');
        }
    }

    /**
     * @param  array<string, mixed>  $set  column (excerpt, seo_title, seo_description) or data key => new value
     */
    private function update(string $key, array $set, bool $apply, Actor $actor, \Closure $log): void
    {
        [$typeValue, $slug] = explode(':', $key, 2);
        $row = DB::table('site_contents')->where('type', $typeValue)->where('slug', $slug)->first();
        if ($row === null) {
            $log($key, '*', 'missing');

            return;
        }
        $type = ContentType::from($typeValue);
        $start = InitialContent::starting($typeValue, $slug) ?? [];
        $data = (array) json_decode((string) $row->data, true);
        $input = [];
        foreach ($set as $field => $new) {
            $column = in_array($field, ['excerpt', 'seo_title', 'seo_description'], true);
            $current = $column ? $row->{$field} : ($data[$field] ?? null);
            $original = $column ? ($start[$field] ?? null) : ($start['data'][$field] ?? null);
            $action = match (true) {
                self::same($current, $new) => 'already',
                self::blank($current) || ($original !== null && self::same($current, $original)) => 'update',
                default => 'kept',
            };
            $log($key, $field, $action);
            if ($action === 'update') {
                $input[$field] = $new;
            }
        }
        if ($apply && $input !== []) {
            $columns = array_intersect_key($input, array_flip(['excerpt', 'seo_title', 'seo_description']));
            $this->contents->update((string) $row->id, self::input($type, array_diff_key($input, $columns)) + $columns, $actor);
        }
    }

    /**
     * @param  list<string>  $targets
     */
    private function relate(string $from, array $targets, bool $apply, Actor $actor, \Closure $log): void
    {
        $id = self::id($from);
        if ($id === null) {
            $log($from, 'related', 'missing');

            return;
        }
        $existing = DB::table('site_content_relations')->where('content_id', $id)->pluck('related_id')->all();
        $add = [];
        foreach ($targets as $to) {
            $target = self::id($to);
            if ($target === null && $apply) {
                $log($from, "related {$to}", 'missing');
            } elseif ($target !== null && in_array($target, $existing, true)) {
                $log($from, "related {$to}", 'already');
            } else {
                $log($from, "related {$to}", 'add');
                $target !== null ? $add[] = $target : null;
            }
        }
        if ($apply && $add !== []) {
            $this->contents->update($id, ['related' => [...$existing, ...$add]], $actor);
        }
    }

    /**
     * Home shows the first six solutions (sort order). Moves $up into the
     * place of $down while $down is shown and $up is not; a panel reorder
     * that already changed this is left alone.
     */
    private function promote(string $up, string $down, bool $apply, \Closure $log): void
    {
        $shown = DB::table('site_contents')->where('type', 'solution')->where('status', ContentStatus::Published->value)
            ->orderBy('sort_order')->orderByDesc('published_at')->limit(6)->pluck('id')->all();
        [$upId, $downId] = [self::id($up), self::id($down)];
        if ($upId === null || $downId === null) {
            $log($up, 'sort_order', 'missing');

            return;
        }
        if (in_array($upId, $shown, true) || ! in_array($downId, $shown, true)) {
            $log($up, 'sort_order (ana sayfa)', 'already');

            return;
        }
        $log($up, "sort_order (ana sayfada {$down} yerine)", 'swap');
        if ($apply) {
            $orders = DB::table('site_contents')->whereIn('id', [$upId, $downId])->pluck('sort_order', 'id')->all();
            DB::table('site_contents')->where('id', $upId)->update(['sort_order' => $orders[$downId], 'updated_at' => now()]);
            DB::table('site_contents')->where('id', $downId)->update(['sort_order' => $orders[$upId], 'updated_at' => now()]);
            SiteContents::bump();
        }
    }

    /**
     * Stored structured values → the panel's form text (SiteContents parses it back).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function input(ContentType $type, array $data): array
    {
        $fields = collect($type->fields())->keyBy('key');

        return collect($data)->mapWithKeys(fn ($v, $k) => [$k => isset($fields[$k]) ? SiteContents::formValue($fields[$k], $v) : $v])->all();
    }

    private static function id(string $key): ?string
    {
        [$type, $slug] = explode(':', $key, 2);

        return DB::table('site_contents')->where('type', $type)->where('slug', $slug)->value('id');
    }

    private static function blank(mixed $v): bool
    {
        return $v === null || $v === '' || $v === [];
    }

    /**
     * Equal values; object key order does not count (data is jsonb, which
     * reorders keys).
     */
    private static function same(mixed $a, mixed $b): bool
    {
        $canonical = function (mixed $v) use (&$canonical): mixed {
            if (! is_array($v)) {
                return $v;
            }
            $v = array_map($canonical, $v);
            array_is_list($v) ?: ksort($v);

            return $v;
        };

        return json_encode($canonical($a), JSON_UNESCAPED_UNICODE) === json_encode($canonical($b), JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function newItems(): array
    {
        return [
            ['type' => 'service', 'slug' => 'pazaryeri-entegrasyonlari', 'title' => 'Pazaryeri Entegrasyonları', 'category' => 'pazaryeri-entegrasyonlari', 'publish' => true,
                'seo_title' => 'Pazaryeri Entegrasyonu — Trendyol, Hepsiburada, N11 ve Boyner',
                'seo_description' => 'Pazaryerlerinde stok, fiyat, sipariş ve ürün kapatmalarını ERP ve e-ticaret sitenizle tutarlı tutan, yazdığını doğrulayan özel entegrasyonlar.',
                'excerpt' => 'Pazaryerlerindeki stok, fiyat, sipariş ve ürün durumlarını Nebim V3 ve Ticimax ile tutarlı tutan, yazdığını doğrulayan entegrasyonlar geliştiriyoruz.',
                'data' => [
                    'hero_title' => 'Pazaryerlerinde yanlış stokla satış yapmayın',
                    'hero_text' => 'Pazaryerlerindeki stok, fiyat ve ürün durumlarını ERP ve e-ticaret sitenizle aynı kaynaktan, kontrollü ve doğrulanarak güncelliyoruz.',
                    'problem' => 'Her pazaryerinin API\'si, ürün kodu yapısı ve işleme süresi farklıdır. Stok ve fiyat elle ya da dosyayla güncellendiğinde kanallar birbirinden kopar: e-ticaret sitesinde kapanan bir ürün pazaryerinde satılmaya devam eder, gönderilen bir değerin pazaryerinde gerçekten işlenip işlenmediği bilinmez.',
                    'approach' => 'Kaynak sistemi (genellikle ERP) tek doğru kabul eder, her pazaryeri için ayrı bir bağlantı katmanı kurarız. Ürünleri pazaryeri kodu, barkod ve stok kodu üzerinden eşleştirir; gönderimden sonra pazaryerinin işlem sonucunu kontrol ederiz. Negatif stok gibi riskli değerler gönderilmez, başarısız güncellemeler sebebiyle birlikte raporlanır.',
                    'how_it_works' => [
                        ['title' => 'Eşleştirme', 'text' => 'Pazaryeri ürün kodları barkod ve stok kodu üzerinden kaynak sistemdeki ürünlere bağlanır; eşleşmeyenler ayrı listelenir.'],
                        ['title' => 'Karşılaştırma', 'text' => 'Kaynak sistemdeki stok ve fiyat, pazaryerindeki değerlerle düzenli aralıklarla karşılaştırılır.'],
                        ['title' => 'Güncelleme', 'text' => 'Yalnızca farklı olan kayıtlar, pazaryerinin kurallarına uygun şekilde gönderilir.'],
                        ['title' => 'Doğrulama', 'text' => 'Pazaryerinin işlem sonucu okunur; başarısız kayıtlar sebebiyle raporlanır ve uygun olanlar tekrar denenir.'],
                    ],
                    'benefits' => ['Kanallar arasında tutarlı stok ve fiyat', 'E-ticaret sitesinde kapanan ürünün pazaryerlerinde de kapanması', 'Gönderilen değerin gerçekten işlendiğinin kontrol edilmesi', 'Hataların sebebiyle birlikte görünür olması'],
                    'features' => ['Stok ve fiyat senkronizasyonu', 'Ürün ve varyant kapatma / açma', 'Sipariş aktarımı', 'Pazaryeri hesap ekstrelerinin içe aktarımı ve mutabakatı', 'Kargo maliyeti ve desi kontrolleri', 'Çalışma kayıtları ve hata raporları'],
                    'systems' => ['Trendyol', 'Hepsiburada', 'N11', 'Boyner', 'Pazarama', 'Nebim V3', 'Ticimax'],
                    'use_cases' => ['Nebim V3 stoklarının pazaryerlerine düzenli aktarılması', 'Ticimax\'te pasife alınan varyantların pazaryerlerinde de satışa kapatılması', 'Pazaryeri ekstrelerindeki kesinti ve masrafların ERP tarafında raporlanması'],
                    'faq' => [
                        ['q' => 'Hangi pazaryerleriyle çalıştınız?', 'a' => 'Hepsiburada, N11 ve Boyner için stok ve fiyat; Trendyol ve Hepsiburada için sipariş; Trendyol, Hepsiburada, N11, Boyner ve Pazarama için hesap ekstresi aktarımı geliştirdik. Resmi API\'si olan diğer pazaryerleri için de aynı yapıyı kurabiliyoruz.'],
                        ['q' => 'Hazır bir entegratör yazılımı mı satıyorsunuz?', 'a' => 'Hayır. Mevcut ERP, e-ticaret sitesi ve pazaryeri yapınıza göre size özel entegrasyon geliştiriyoruz; kurallar ve raporlar sizin süreçlerinize göre şekillenir.'],
                        ['q' => 'Bir güncelleme başarısız olursa ne olur?', 'a' => 'Sessizce atlanmaz. Pazaryerinin döndürdüğü sonuç kaydedilir, başarısız kayıtlar sebebiyle birlikte raporlanır ve uygun olanlar tekrar denenir.'],
                        ['q' => 'Negatif veya hatalı stok pazaryerine gidebilir mi?', 'a' => 'Negatif stok gibi riskli değerler gönderilmeden önce durdurulur; bu kurallar entegrasyonun içinde tanımlıdır.'],
                    ],
                    'cta_label' => 'Pazaryeri entegrasyonunuzu değerlendirelim',
                    'cta_text' => 'Hangi pazaryerlerinde satış yaptığınızı ve stok/fiyatın hangi sistemden geldiğini kısaca yazın.',
                ]],
            ['type' => 'service', 'slug' => 'ozel-yazilim-ve-operasyon-araclari', 'title' => 'Özel Yazılım ve Operasyon Araçları', 'category' => 'ozel-yazilim', 'publish' => true,
                'seo_title' => 'Özel Yazılım ve Operasyon Araçları — Panel, Rapor ve Otomasyon',
                'seo_description' => 'Hazır yazılımın karşılamadığı operasyon işleri için özel paneller, kontrol raporları, uyarılar ve otomasyonlar: sistemlerinize bağlı, kayıtlı ve güvenli.',
                'excerpt' => 'Ekiplerinizin her gün elle yaptığı kontrolleri, raporları ve düzeltmeleri; mevcut sistemlerinize bağlı özel paneller ve otomasyonlarla yazılıma dönüştürüyoruz.',
                'data' => [
                    'hero_title' => 'Tablolar ve elle kontrolle yürüyen operasyonu yazılıma taşıyın',
                    'hero_text' => 'Hazır yazılımın karşılamadığı işler için; ERP, e-ticaret ve pazaryeri verinizi okuyan, kontrol eden, raporlayan ve gerektiğinde onayla düzelten özel araçlar geliştiriyoruz.',
                    'problem' => 'Büyüyen operasyonlarda birçok kontrol hâlâ tablolar, dışa aktarılan dosyalar ve kişilerin hafızasıyla yürür. Hangi ürünün stoğu tutarsız, hangi fiyat hatalı, hangi sipariş takılmış — bunlar çoğu zaman ancak bir sorun çıktığında fark edilir.',
                    'approach' => 'Önce elle yapılan işi ve kullanılan veri kaynaklarını çıkarırız. Ardından veriyi kaynağından okuyan, bulguları kayıt altına alan ve kritik düzeltmeleri onaya bağlayan bir araç kurarız. Sisteme yazan adımlar önce rapor modunda doğrulanır, sonra kontrollü olarak açılır.',
                    'how_it_works' => [
                        ['title' => 'Keşif', 'text' => 'Elle yapılan kontrolleri, kullanılan dosyaları ve karar kurallarını birlikte çıkarırız.'],
                        ['title' => 'Rapor modu', 'text' => 'Araç önce yalnızca okur ve bulguları raporlar; kurallar gerçek veriyle doğrulanır.'],
                        ['title' => 'Kontrollü otomasyon', 'text' => 'Güvenilir hale gelen düzeltmeler onaylı veya zamanlanmış otomasyona dönüşür.'],
                        ['title' => 'İzleme', 'text' => 'Her çalışma kaydedilir; hata ve uyarılar ekibe iletilir.'],
                    ],
                    'benefits' => ['Elle yapılan kontrollerin sistematik hale gelmesi', 'Sorunların daha erken görünür olması', 'Kuralların kişilere değil sisteme bağlı olması', 'Kayıtlı ve geri izlenebilir işlemler'],
                    'features' => ['Yönetim ve operasyon panelleri', 'Stok, fiyat ve ürün kontrol raporları', 'Otomatik bulgu ve uyarı üretimi', 'Zamanlanmış görevler ve e-posta uyarıları', 'Dosya içe aktarma ve mutabakat araçları', 'Yapay zeka destekli özet ve analiz adımları'],
                    'systems' => ['Nebim V3', 'Ticimax', 'Pazaryerleri', 'Kargo firmaları', 'Raporlama veritabanları'],
                    'use_cases' => ['ERP ve e-ticaret sitesi arasındaki stok farklarının her gece raporlanması', 'Benzer ürünlerin stok durumunun izlenip tükendiğinde uyarı gönderilmesi', 'Haftalık operasyon verisinden bulgu listesi ve özet rapor hazırlanması', 'Kargo desi ve maliyetlerinin kontrol edilmesi'],
                    'faq' => [
                        ['q' => 'Hazır bir ürün mü, özel geliştirme mi?', 'a' => 'Özel geliştirme. Araç sizin veri kaynaklarınıza, kurallarınıza ve ekip yapınıza göre tasarlanır.'],
                        ['q' => 'Mevcut sistemlerimize zarar verebilir mi?', 'a' => 'Araçlar önce yalnızca okuyarak çalışır. Sisteme yazan adımlar ayrı yetki, onay ve kayıtla, kontrollü olarak açılır.'],
                        ['q' => 'Yapay zeka nerede kullanılıyor?', 'a' => 'Bulguların özetlenmesi ve önceliklendirilmesi gibi adımlarda. Yapay zeka çıktıları tek başına sisteme yazılmaz; kurallarla doğrulanır veya onaya sunulur.'],
                    ],
                    'cta_label' => 'İhtiyacınızı bize anlatın',
                    'cta_text' => 'Ekibinizin her gün elle yaptığı kontrolü ve kullandığınız sistemleri kısaca yazın.',
                ]],

            ['type' => 'project', 'slug' => 'ticimax-nebim-cok-kanalli-operasyon', 'title' => 'Ticimax + Nebim V3 + Çok Kanallı Operasyon Entegrasyonu', 'publish' => true, 'featured' => true, 'sort_order' => 1,
                'seo_title' => 'Vaka: Ticimax, Nebim V3 ve Pazaryeri Entegrasyonu',
                'seo_description' => 'Nebim V3, Ticimax ve pazaryerleri arasında barkod tabanlı eşleştirme, gece stok kontrolü ve düzeltmesi, ürün kapatma ve sipariş akışları: anonim vaka.',
                'excerpt' => 'Çok kanallı bir perakende markası için Nebim V3, Ticimax ve pazaryerleri arasında stok, renk, ürün durumu ve sipariş akışlarını kontrollü şekilde bağladık.',
                'data' => [
                    'client_display' => self::CLIENT, 'client_named' => false, 'industry' => 'Perakende / e-ticaret',
                    'challenge' => 'Markanın ürün, stok ve renk bilgisi Nebim V3\'te; online satışı Ticimax mağazası ve birden fazla pazaryerinde yürüyordu. Kanallar arasındaki farklar — Ticimax\'teki renk adlarının Nebim renk kodlarıyla uyuşmaması, Ticimax\'te kapatılan varyantların pazaryerlerinde satışta kalması, stok farklarının geç fark edilmesi — elle kontrol ve düzeltme gerektiriyordu.',
                    'solution_text' => "Nebim V3'ü ürün ve stok için tek doğru kaynak kabul eden bir entegrasyon ve operasyon paneli kurduk:\n\n- Renkleri ürün adı yerine barkod ve Nebim renk koduyla çözen, öneri sunan ve düzeltmeleri parça parça uygulayan bir renk yönetimi ekranı.\n- Nebim ile Ticimax stoklarını her gece karşılaştıran; önce yalnızca raporlayan, doğrulandıktan sonra otomatik düzeltmeye geçen stok kontrolü.\n- Ticimax'te kapatılan veya pasif olan varyantların pazaryerlerinde de saatlik olarak satışa kapatılması.\n- Pazaryerlerine negatif stok gönderilmesini engelleyen ve başarısız bir düzeltmenin sebebini gösteren kurallar.\n- Ticimax, Trendyol ve Hepsiburada siparişlerinin ve Ticimax kampanyalarının senkronizasyonu.",
                    'technologies' => ['Laravel', 'Filament', 'Zamanlanmış görevler', 'REST ve SOAP servisleri'],
                    'integrations' => ['Nebim V3', 'Ticimax', 'Trendyol', 'Hepsiburada', 'N11', 'Boyner'],
                ],
                'body' => "## Neden önce rapor modu\n\nMağazaya yazan işlemleri (stok düzeltmesi gibi) doğrudan otomatik açmadık. İlk aşamada sistem yalnızca farkları raporladı. İlk canlı çalıştırmada görülen uç durumlar — örneğin Ticimax'in bekleyen siparişleri stok yazımından sonra düşmesi — kurallara eklendikten sonra otomatik düzeltmeye geçildi.\n\n## Barkod neden anahtar\n\nRenk ve ürün adları kanaldan kanala değişir; barkod değişmez. Eşleştirmeyi barkod ve Nebim kodları üzerine kurmak, isim farklarından doğan hataları ortadan kaldırdı. Ürünün Ticimax kategorisinde tanımlı olmayan renkler de sessizce gönderilmek yerine atlanıp raporlanıyor.\n\n*Müşteri adı, onayı olmadığı için paylaşılmamıştır.*"],
            ['type' => 'project', 'slug' => 'e-ticaret-operasyon-kontrol-altyapisi', 'title' => 'E-Ticaret Operasyon Kontrol ve Otomasyon Altyapısı', 'publish' => true, 'featured' => true, 'sort_order' => 2,
                'seo_title' => 'Vaka: E-Ticaret Operasyon Kontrol ve Otomasyon Altyapısı',
                'seo_description' => 'Stok ve fiyat tutarlılığı, ürün ve pazaryeri kontrolleri, otomatik bulgu ve uyarılar, AI destekli haftalık operasyon raporu: çok kanallı perakende vakası.',
                'excerpt' => 'Stok, fiyat, ürün ve pazaryeri verisini düzenli kontrol eden, bulgu ve uyarı üreten, haftalık operasyon özetini yapay zeka desteğiyle hazırlayan altyapı.',
                'data' => [
                    'client_display' => self::CLIENT, 'client_named' => false, 'industry' => 'Perakende / e-ticaret',
                    'challenge' => 'Çok kanallı satışta sorunların bir kısmı ancak bir müşteri şikâyetiyle ya da ay sonu mutabakatında fark ediliyordu: kanallar arasında farklı stok veya fiyat, eksik görseller, hatalı başlık ve açıklamalar, beklenmeyen pazaryeri kesintileri. Kontroller farklı kişilerin elinde, farklı dosyalarda yapılıyordu.',
                    'solution_text' => "Kontrolleri tek bir operasyon altyapısında topladık:\n\n- Stok ve fiyat uyuşmazlıklarını başlık ve açıklama sorunlarından ayıran bir stok genel bakış ekranı.\n- Benzer ürünlerin stok durumunu izleyen ve stok bittiğinde e-posta uyarısı gönderen liste.\n- Hepsiburada fiyat ve stok gönderimlerinin gerçekten işlendiğini doğrulayan günlük senkronizasyon.\n- Trendyol, Hepsiburada, N11, Boyner ve Pazarama hesap ekstrelerinin içe aktarımı; pazaryeri masraf ve gelirlerinin Nebim tarafına aktarımı.\n- Kargo desi raporu ve kargo maliyeti mutabakatı.\n- Haftalık Operasyon Merkezi: önceki haftanın verisini yalnızca okuyarak inceleyen, bulgular üreten ve yapay zeka destekli özet hazırlayan Pazartesi operasyonu. Bu adım hiçbir pazaryerine yazmaz.",
                    'technologies' => ['Laravel', 'Filament', 'Büyük dil modeli API\'si', 'Zamanlanmış görevler'],
                    'integrations' => ['Nebim V3', 'Ticimax', 'Trendyol', 'Hepsiburada', 'N11', 'Boyner', 'Pazarama', 'Yurtiçi Kargo'],
                ],
                'body' => "## Tasarım ilkesi: önce oku, sonra yaz\n\nKontrol katmanı varsayılan olarak yalnızca okur. Bir bulgunun düzeltmeye dönüşmesi ayrı bir adımdır ve kayıt altına alınır. Bu sayede ekip, yeni bir kontrolün ürettiği bulguları canlı sisteme dokunmadan inceleyebiliyor.\n\n## Yapay zekanın rolü\n\nYapay zeka, sistemin hesapladığı haftalık verileri özetlemek ve önceliklendirmek için kullanılıyor. Özetin hesaplanmış verilerle tutarlı olması için ek doğrulamalar eklendi; yapay zeka çıktısı tek başına hiçbir sisteme yazmaz.\n\n*Müşteri adı, onayı olmadığı için paylaşılmamıştır. Bu vakada doğrulanmış bir verim veya tasarruf rakamı olmadığı için sonuç rakamı verilmemiştir.*"],
            ['type' => 'project', 'slug' => 'motioncurate-toplu-video-ve-render-otomasyonu', 'title' => 'MotionCurate: Toplu Video ve Render Otomasyonu', 'publish' => true, 'featured' => true, 'sort_order' => 3,
                'seo_title' => 'MotionCurate — After Effects Toplu Video ve Render Otomasyonu',
                'seo_description' => 'PratikTools\'un kendi ürünü MotionCurate: After Effects şablon değişkenleri, render node\'ları, dağıtık iş kuyruğu ve izleme ile toplu video üretim altyapısı.',
                'excerpt' => 'PratikTools\'un kendi geliştirdiği MotionCurate bulut render platformu: After Effects şablonlarını veriyle dolduran, işleri render node\'larına dağıtan ve süreci izleyen altyapı.',
                'data' => [
                    'client_display' => 'PratikTools\'un kendi ürün geliştirme projesi', 'client_named' => false, 'industry' => 'Medya teknolojileri',
                    'challenge' => 'Şablon tabanlı video üretiminde aynı After Effects projesi yüzlerce farklı metin, görsel ve renk varyasyonuyla tekrar tekrar render edilir. Bunu elle yapmak zaman alır ve hata üretir; tek bir makinede render almak ise hacim arttıkça darboğaza dönüşür. Ayrıca After Effects otomasyonunda ancak gerçek testlerde görünen platform sınırları vardır.',
                    'solution_text' => "MotionCurate'i PratikTools olarak kendi ürünümüz olarak geliştiriyoruz. Platform üç parçadan oluşuyor:\n\n- Tarayıcı: After Effects projesini tarar, Essential Graphics'te yayınlanmış değişkenleri bulur ve bir manifest olarak çıkarır.\n- Laravel API: manifesti kaydeder, bir render talebinde gerçek değerleri toplar ve işi render node'una gönderir.\n- Render node (macOS ve Windows): projeyi açar, değişkenleri After Effects özelliklerine uygular, After Effects'in kendi render kuyruğuyla çıktı alır ve durumu raporlar.\n\nİşleri node'lara dağıtan broker; iş durum makinesi, kiralama (lease), heartbeat takibi, node puanlama, yeniden deneme ve takılan işlerin kurtarılmasını yönetir.",
                    'technologies' => ['TypeScript', 'Node.js', 'ExtendScript (JSX)', 'Laravel', 'Cloudflare Tunnel'],
                    'integrations' => ['Adobe After Effects', 'After Effects render kuyruğu'],
                ],
                'body' => "## Gerçek testlerde öğrendiklerimiz\n\nAfter Effects otomasyonunda dokümantasyonda görünmeyen sınırlar gerçek çalıştırmada ortaya çıkar. Örneğin Essential Graphics özellikleri yazılabilir ama her durumda geri okunamaz; Media Encoder kuyruğuna göndermek yerine After Effects'in kendi render kuyruğunu kullanmak daha güvenilir sonuç verdi. Bir projenin gerçekten açıldığını doğrulamak için de ayrı kontroller gerekti.\n\nBu deneyim, After Effects otomasyonu, toplu video üretimi ve render farm hizmetlerimizin teknik temelidir.\n\n*MotionCurate, PratikTools tarafından geliştirilen kendi ürünüdür; bir müşteri projesi değildir.*".MediaCatalog::ADOBE_NOTE],

            ...self::blogDrafts(),
        ];
    }

    /**
     * Content plans only (drafts, never published here).
     *
     * @return list<array<string, mixed>>
     */
    private static function blogDrafts(): array
    {
        $draft = fn (string $slug, string $title, string $category, string $excerpt, string $intent, string $audience, array $outline, string $link, string $cta) => [
            'type' => 'article', 'slug' => $slug, 'title' => $title, 'category' => $category, 'publish' => false, 'excerpt' => $excerpt, 'seo_title' => $title,
            'data' => ['author' => 'PratikTools'],
            'body' => "> **Taslak — yayınlanmadan önce yazılacak.** Arama niyeti: {$intent}. Hedef kitle: {$audience}. İlgili sayfa: {$link}. CTA: {$cta}.\n\n"
                .implode("\n\n", array_map(fn ($h) => "## {$h}\n\n_(yazılacak)_", $outline)),
        ];

        return [
            $draft('ticimax-nebim-entegrasyonu-nasil-yapilir', 'Ticimax Nebim Entegrasyonu Nasıl Yapılır?', 'nebim',
                'Ticimax ve Nebim V3 entegrasyonunda veri modeli, barkod eşleştirme, stok/fiyat akışı ve sık yapılan hatalar.',
                'ticari + bilgi', 'Nebim V3 kullanan e-ticaret yöneticileri ve IT ekipleri',
                ['Ticimax ile Nebim V3 neden kolay eşleşmez?', 'Barkod, renk ve beden: eşleştirme anahtarı seçimi', 'Stok ve fiyat akışı: yön, sıklık ve riskli değerler', 'Sipariş aktarımı', 'Önce rapor modu: canlıya kontrollü geçiş', 'Sık yapılan hatalar', 'Ne zaman özel entegrasyon gerekir?', 'Sık sorulan sorular'],
                '/cozumler/ticimax-nebim-entegrasyonu', 'Entegrasyonunuzu Değerlendirelim'),
            $draft('ticimax-api-ile-otomasyon', 'Ticimax API ile Hangi İşlemler Otomatikleştirilebilir?', 'ticimax',
                'Ticimax servisleriyle ürün, stok, sipariş, kampanya ve müşteri işlemlerinde neler otomatikleştirilebilir, nelere dikkat edilmeli?',
                'bilgi', 'Ticimax mağaza sahipleri ve teknik ekipler',
                ['Ticimax servislerine genel bakış', 'Ürün ve varyant işlemleri', 'Stok ve fiyat güncellemeleri', 'Sipariş ve müşteri verisi', 'Kampanya senkronizasyonu', 'Sınırlar: sayfalama, zaman dilimi, bekleyen siparişler', 'Güvenli otomasyon için kontrol listesi', 'Sık sorulan sorular'],
                '/hizmetler/ticimax-entegrasyonlari', 'İhtiyacınızı Bize Anlatın'),
            $draft('e-ticarette-stok-ve-fiyat-senkronizasyonu', 'E-Ticarette Stok ve Fiyat Senkronizasyonu Nasıl Otomatikleştirilir?', 'e-ticaret',
                'ERP, e-ticaret sitesi ve pazaryerleri arasında stok ve fiyatı tutarlı tutmanın mimarisi: tek kaynak, karşılaştırma, doğrulama ve uyarılar.',
                'problem çözme', 'Çok kanallı satış yapan operasyon ekipleri',
                ['Stok ve fiyat neden kanallar arasında kayar?', 'Tek doğru kaynak (source of truth) seçimi', 'Karşılaştır, sonra yaz: fark tabanlı güncelleme', 'Yazdığını doğrula: pazaryeri işlem sonuçları', 'Negatif stok ve riskli değerler', 'Gece kontrolleri ve uyarılar', 'Sık sorulan sorular'],
                '/cozumler/e-ticaret-otomasyonu', 'Teknik Görüşme Planlayın'),
            $draft('after-effects-ile-toplu-video-uretimi', 'After Effects ile Toplu Video Üretimi Nasıl Otomatikleştirilir?', 'otomasyon',
                'Şablon, veri ve render kuyruğuyla After Effects\'te yüzlerce video varyasyonu üretmenin teknik yolu ve platform sınırları.',
                'bilgi + ticari', 'Ajanslar, post-prodüksiyon ve içerik ekipleri',
                ['Toplu video üretimi ne zaman mantıklı?', 'Şablon tasarımı: Essential Graphics ve değişkenler', 'Veri kaynağı: tablo, CSV, API', 'Render: After Effects render kuyruğu ve Media Encoder', 'Gerçek testlerde görülen sınırlar', 'Ölçeklenme: tek makineden render node\'lara', 'Sık sorulan sorular'],
                '/cozumler/after-effects-otomasyonu', 'Projenizi Anlatalım'),
            $draft('render-farm-nedir', 'Render Farm Nedir ve Ne Zaman Gerekir?', 'otomasyon',
                'Render farm\'ın bileşenleri (node, kuyruk, broker, izleme), ne zaman gerektiği ve kurulumda dikkat edilmesi gerekenler.',
                'bilgi', 'Stüdyolar, prodüksiyon şirketleri ve teknik yöneticiler',
                ['Render farm nedir?', 'Ne zaman tek makine yetmez?', 'Bileşenler: render node, iş kuyruğu, broker, izleme', 'İş dağıtımı: kiralama, heartbeat, yeniden deneme', 'On-prem, uzak ve hibrit seçenekler', 'Lisans ve platform kısıtları', 'Sık sorulan sorular'],
                '/cozumler/render-farm-kurulumu', 'Teknik Görüşme Planlayın'),
        ];
    }

    /**
     * Changes to existing pages ("type:slug" => [field => new value]).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function updates(): array
    {
        return [
            'solution:ticimax-nebim-entegrasyonu' => [
                'seo_description' => 'Ticimax ve Nebim V3 entegrasyonu: barkod tabanlı ürün ve renk eşleştirme, stok ve fiyat aktarımı, sipariş akışı, gece stok kontrolü ve hata raporları.',
                'how_it_works' => [
                    ['title' => 'Veri analizi', 'text' => 'Nebim V3 ürün kartları, barkodlar ve renk/beden yapısı ile Ticimax ürünleri incelenir; eşleşme oranı ve sorunlu kayıtlar çıkarılır.'],
                    ['title' => 'Eşleştirme', 'text' => 'Ürünler barkod ve Nebim kodları üzerinden eşleştirilir; renk eşleştirmeleri yönetilebilir tablolarda tutulur.'],
                    ['title' => 'Rapor modu', 'text' => 'Stok ve fiyat farkları önce yalnızca raporlanır; kurallar gerçek veriyle doğrulanır.'],
                    ['title' => 'Otomatik senkronizasyon', 'text' => 'Doğrulanan akışlar zamanlanmış ve kayıtlı çalışır; hatalar sebebiyle raporlanır.'],
                ],
                'benefits' => ['Doğru stokla satış', 'Elle aktarımın ortadan kalkması', 'Renk ve varyant tutarlılığı', 'Hataların sebebiyle birlikte raporlanması'],
                'features' => ['Barkod tabanlı ürün eşleştirme', 'Stok ve fiyat aktarımı', 'Sipariş aktarımı', 'Renk / beden eşleştirme yönetimi', 'Gece stok karşılaştırması ve kontrollü otomatik düzeltme', 'Ticimax\'te kapanan varyantların pazaryerlerinde de kapatılması', 'Çalışma ve hata raporları'],
                'systems' => ['Ticimax', 'Nebim V3', 'Pazaryerleri'],
                'examples' => ['Nebim\'de değişen stokların belirli aralıklarla mağazaya yansıması', 'Yeni ürünlerin barkodla eşleşip eşleşmediğinin raporlanması', 'Ticimax renk adlarının Nebim renk kodlarına göre düzeltilmesi', 'Negatif stok değerlerinin kanallara gönderilmeden durdurulması'],
                'faq' => [
                    ['q' => 'Entegrasyon ne kadar sürede devreye alınır?', 'a' => 'Kapsama ve mevcut veri kalitesine göre değişir; ilk görüşmede veri yapınızı inceleyip bir plan çıkarıyoruz.'],
                    ['q' => 'Eşleşmeyen ürünler ne olur?', 'a' => 'Sessizce atlanmaz; ayrı bir raporda listelenir ve düzeltilene kadar mağazaya gönderilmez.'],
                    ['q' => 'Hazır bir entegrasyon paketi mi kuruyorsunuz?', 'a' => 'Hayır. Nebim V3 kurulumunuza, ürün yapınıza ve satış kanallarınıza göre size özel bir entegrasyon geliştiriyoruz.'],
                    ['q' => 'Pazaryerleri de bu akışa dahil edilebilir mi?', 'a' => 'Evet. Nebim V3 kaynaklı stok ve ürün durumları pazaryerlerine de aktarılabilir; Ticimax\'te kapanan bir varyantın pazaryerlerinde de kapatılması gibi kurallar eklenebilir.'],
                    ['q' => 'Mağazaya yanlış stok yazılma riski nasıl yönetiliyor?', 'a' => 'Yazma işlemlerinden önce rapor modunda farklar incelenir; negatif stok gibi riskli değerler gönderilmez ve her çalışma kaydedilir.'],
                ],
                'cta_label' => 'Entegrasyonunuzu değerlendirelim',
            ],
            'solution:e-ticaret-otomasyonu' => [
                'seo_title' => 'E-Ticaret Operasyon Otomasyonu — Stok, Fiyat ve Pazaryeri',
                'seo_description' => 'E-ticaret operasyonunda stok/fiyat kontrolleri, pazaryeri senkronizasyonları, uyarılar ve raporlarla tekrar eden işleri izlenebilir otomasyona dönüştürün.',
                'problem' => 'Büyüyen e-ticaret operasyonlarında ekipler zamanının önemli kısmını sistemler arası veri taşıma ve kontrol işlerine harcar. Stok ve fiyat farkları, kapanması gereken ama satışta kalan ürünler, takılan siparişler ve pazaryeri kesintileri çoğu zaman bir sorun çıktığında fark edilir.',
                'approach' => 'Önce süreçleri ve veri akışlarını çıkarır, en çok zaman alan ve en riskli adımlardan başlarız. Her otomasyon önce yalnızca okuyup raporlar; kurallar doğrulandıktan sonra sisteme yazan adımlar kontrollü açılır. Her çalışma kaydedilir, hata durumunda uyarı verir ve gerektiğinde durdurulabilir.',
                'how_it_works' => [
                    ['title' => 'Süreç haritası', 'text' => 'Elle yapılan kontroller, kullanılan sistemler ve veri akışları çıkarılır.'],
                    ['title' => 'Kontrol ve rapor', 'text' => 'Stok, fiyat, ürün ve sipariş verisi düzenli karşılaştırılır; bulgular raporlanır.'],
                    ['title' => 'Otomasyon', 'text' => 'Doğrulanan düzeltmeler zamanlanmış ve kayıtlı otomasyonlara dönüşür.'],
                    ['title' => 'Uyarı ve özet', 'text' => 'Hatalar ve bulgular ekibe iletilir; haftalık özetler hazırlanır.'],
                ],
                'features' => ['Stok ve ürün senkronizasyonları', 'Stok ve fiyat tutarlılık kontrolleri', 'Sipariş ve iade akışları', 'Pazaryeri entegrasyonları ve ekstre mutabakatı', 'Otomatik bulgu ve uyarılar', 'Operasyon raporları ve yapay zeka destekli özetler'],
                'examples' => ['ERP ile e-ticaret sitesi arasındaki stok farklarının her gece raporlanması', 'Pazaryeri masraflarının ERP\'ye aktarılması', 'Stoğu biten benzer ürünler için e-posta uyarısı', 'Haftalık operasyon verisinden bulgu listesi hazırlanması'],
                'faq' => [
                    ['q' => 'Hangi süreçlerden başlamalıyız?', 'a' => 'En sık tekrarlanan ve hata maliyeti en yüksek olan süreçlerden. Genellikle stok ve fiyat tutarlılığı ilk adımdır.'],
                    ['q' => 'Otomasyon yanlış bir değer yazarsa ne olur?', 'a' => 'Sisteme yazan adımlar önce rapor modunda doğrulanır; riskli değerler engellenir ve her işlem kaydedildiği için geri izlenebilir.'],
                    ['q' => 'Mevcut entegratör yazılımımızla birlikte çalışabilir mi?', 'a' => 'Evet. Mevcut araçların kapsamadığı kontrol ve otomasyonları, onların yanında çalışacak şekilde kurabiliyoruz.'],
                ],
            ],
            'solution:yapay-zeka-otomasyonu' => [
                'seo_description' => 'Yapay zekayı şirket verinize bağlı, insan onaylı ve denetlenebilir iş akışlarına dönüştürün: sınıflandırma, özetleme, raporlama ve içerik taslağı.',
                'systems' => ['E-ticaret ve ERP verileri', 'Raporlama veritabanları', 'Büyük dil modeli API\'leri'],
                'faq' => [
                    ['q' => 'Verilerimiz yapay zeka modeline gönderiliyor mu?', 'a' => 'Yalnızca görevin gerektirdiği veri, gerekli olduğu kadar gönderilir; kişisel veriler mümkün olduğunca ayıklanır veya maskelenir.'],
                    ['q' => 'Yapay zeka sistemlerimize doğrudan yazar mı?', 'a' => 'Hayır. Yapay zeka çıktıları kurallarla doğrulanır; kritik adımlarda sisteme yazmadan önce insan onayı alınır.'],
                    ['q' => 'Hangi işlerle başlamak mantıklı?', 'a' => 'Tekrarlanan, kuralı tarif edilebilen ve sonucu kontrol edilebilen işlerle: sınıflandırma, özetleme, rapor taslağı gibi.'],
                ],
            ],
            'solution:email-marketing-otomasyonu' => [
                'cta_label' => 'E-posta altyapınızı konuşalım',
                'form_kind' => 'gorusme',
                'faq' => [
                    ['q' => 'Hazır bir e-posta pazarlama yazılımı mı sunuyorsunuz?', 'a' => 'Hayır. Segment, akış ve izin kontrollerini mevcut e-ticaret verinize ve e-posta altyapınıza göre kuruyoruz. Canlı gönderim; alan adı doğrulaması ve test gönderimlerinden sonra kontrollü açılır.'],
                ],
            ],
            'service:pazarlama-otomasyonu' => [
                'faq' => [
                    ['q' => 'İYS izinleri nasıl dikkate alınıyor?', 'a' => 'Ticari elektronik ileti gönderiminden hemen önce alıcının izin durumu kontrol edilir; izni doğrulanamayan alıcıya gönderim yapılmaz.'],
                    ['q' => 'Abonelikten çıkan kişi tekrar ileti alabilir mi?', 'a' => 'Hayır. Abonelikten çıkma anında yerel engelleme uygulanır ve kalkması ayrı, kayıtlı bir işlem gerektirir.'],
                    ['q' => 'Bu hazır bir pazarlama otomasyonu yazılımı mı?', 'a' => 'Hayır. Segment, akış, izin ve gönderim kurallarını sizin verinize ve altyapınıza göre geliştiriyoruz. E-posta ve push gönderimleri test listesiyle denendikten sonra kontrollü olarak canlıya alınır.'],
                ],
            ],
            'service:push-notification-altyapisi' => [
                'excerpt' => 'OneSignal ile web ve mobil push aboneliklerini müşteri verinize güvenli şekilde eşleştiren altyapıyı kuruyoruz; kişiselleştirilmiş bildirimler yalnızca doğrulanmış eşleşmelerle ve testten sonra açılır.',
            ],
            'service:iys-via-entegrasyonu' => [
                'excerpt' => 'Ticari elektronik ileti izinlerini İYS ile uyumlu yönetiyoruz: İYS\'nin ViA Frame bileşeniyle izin alma akışı, abonelikten çıkma ve İYS kayıtlarıyla mutabakat.',
                'approach' => 'İzin alma adımında İYS\'nin sağladığı ViA Frame bileşenini kullanırız: alıcının doğrulaması ve iznin kaydı İYS tarafında gerçekleşir. Bizim tarafımızda izinler kaynağıyla birlikte (İYS kayıtları, ViA, yerel abonelikten çıkma) tutulur ve İYS kayıtlarıyla mutabakat yapılır. RET her zaman ONAY\'dan önce gelir; izni doğrulanamayan alıcıya gönderim yapılmaz.',
                'features' => ['İYS ViA Frame ile sitede izin alma akışının kurgulanması', 'Abonelikten çıkma ve RET süreçleri', 'İYS kayıtlarıyla mutabakat', 'Gönderim anında izin kontrolü'],
                'systems' => ['İYS', 'İYS ViA Frame', 'E-posta ve SMS altyapıları'],
                'faq' => [
                    ['q' => 'İYS\'ye doğrudan API ile izin mi yazıyorsunuz?', 'a' => 'İzin alma adımında İYS\'nin kendi ViA Frame bileşenini kullanıyoruz; alıcının doğrulaması ve iznin kaydı İYS tarafında yapılır. Biz bu akışın sitenizdeki kurgusunu ve sonrasında izin kayıtlarınızla mutabakatı kuruyoruz.'],
                    ['q' => 'İzin durumu değişirse ne olur?', 'a' => 'RET her zaman önceliklidir. Abonelikten çıkma anında uygulanır; izni doğrulanamayan alıcıya ticari ileti gönderilmez.'],
                ],
            ],
            'page:home' => [
                // The "Veri & Raporlama" card pointed at a category with no page (hidden now).
                'capability_cards' => array_map(fn ($c) => $c['title'] === 'Veri & Raporlama' ? array_replace($c, ['link' => '/hizmetler/ozel-yazilim-ve-operasyon-araclari']) : $c, InitialContent::HOME['capability_cards']),
            ],
            'service:premiere-pro-otomasyonlari' => [
                'seo_title' => 'Premiere Pro Script ve Panel Geliştirme',
                'seo_description' => 'Premiere Pro için özel script, panel ve araç geliştirme: proje ve klasör hazırlama, medya içe aktarma, tekrar eden kurgu adımları ve toplu export.',
            ],
            'solution:after-effects-otomasyonu' => [
                'seo_title' => 'After Effects Otomasyonu — Şablon, Veri ve Toplu Render',
            ],
            'service:render-farm-sistemleri' => [
                'seo_title' => 'Render Farm Sistemleri — Node, Kuyruk ve Sağlık Takibi',
                'seo_description' => 'Yüksek hacimli medya üretimi için birden fazla render node, iş dağıtımı, node sağlık takibi, yeniden deneme ve merkezi izleme ile render farm altyapısı.',
            ],
            'service:render-otomasyonu' => [
                'seo_description' => 'Render kuyruğu, otomatik render, hata ve yeniden deneme yönetimi, çıktı isimlendirme ve teslim akışlarıyla medya üretiminde render işlerini otomatikleştirin.',
            ],
            'service:video-ve-ses-produksiyonu' => [
                'seo_description' => 'Video, kurgu, post-prodüksiyon ve ses işleme süreçlerini tekrar eden adımları otomatikleştirilmiş, düzenli bir teknik iş akışıyla yürütüyoruz.',
            ],
            'service:toplu-video-ve-icerik-uretimi' => [
                'seo_description' => 'Şablon ve veri kaynaklarından yüksek hacimli video ve görsel varyasyonları üretin: dinamik metin ve görsel, toplu render, yerelleştirme ve çıktı yönetimi.',
            ],
            'service:ai-destekli-medya-is-akislari' => [
                'seo_description' => 'Transkript, altyazı, etiketleme ve metin taslakları gibi medya işlerinde yapay zekâyı kurallı doğrulama ve insan onayıyla iş akışına bağlıyoruz.',
            ],
            'service:ozel-produksiyon-araclari' => [
                'seo_description' => 'Hazır prodüksiyon yazılımlarının karşılamadığı ihtiyaçlar için özel script, panel, web tabanlı takip ve masaüstü araçları geliştiriyoruz.',
            ],
        ];
    }

    /**
     * Internal links to add ("type:slug" => targets).
     *
     * @return array<string, list<string>>
     */
    public static function relations(): array
    {
        $commerce = ['service:ticimax-entegrasyonlari', 'service:nebim-v3-entegrasyonlari', 'solution:ticimax-nebim-entegrasyonu', 'service:pazaryeri-entegrasyonlari',
            'solution:e-ticaret-otomasyonu', 'service:api-ozel-entegrasyon', 'service:ozel-yazilim-ve-operasyon-araclari'];
        $others = fn (string $self, array $list) => array_values(array_diff($list, [$self]));

        return [
            'service:ticimax-entegrasyonlari' => [...$others('service:ticimax-entegrasyonlari', $commerce), 'project:ticimax-nebim-cok-kanalli-operasyon'],
            'service:nebim-v3-entegrasyonlari' => [...$others('service:nebim-v3-entegrasyonlari', $commerce), 'project:ticimax-nebim-cok-kanalli-operasyon'],
            'solution:ticimax-nebim-entegrasyonu' => [...$others('solution:ticimax-nebim-entegrasyonu', $commerce), 'project:ticimax-nebim-cok-kanalli-operasyon'],
            'service:pazaryeri-entegrasyonlari' => [...$others('service:pazaryeri-entegrasyonlari', $commerce), 'project:ticimax-nebim-cok-kanalli-operasyon', 'project:e-ticaret-operasyon-kontrol-altyapisi'],
            'solution:e-ticaret-otomasyonu' => [...$others('solution:e-ticaret-otomasyonu', $commerce), 'project:e-ticaret-operasyon-kontrol-altyapisi'],
            'service:api-ozel-entegrasyon' => [...$others('service:api-ozel-entegrasyon', $commerce), 'project:ticimax-nebim-cok-kanalli-operasyon'],
            'service:ozel-yazilim-ve-operasyon-araclari' => [...$others('service:ozel-yazilim-ve-operasyon-araclari', $commerce), 'service:yapay-zeka-ve-is-sureci-otomasyonu', 'project:e-ticaret-operasyon-kontrol-altyapisi'],
            'service:yapay-zeka-ve-is-sureci-otomasyonu' => ['solution:yapay-zeka-otomasyonu', 'service:ozel-yazilim-ve-operasyon-araclari', 'project:e-ticaret-operasyon-kontrol-altyapisi'],
            'solution:yapay-zeka-otomasyonu' => ['service:yapay-zeka-ve-is-sureci-otomasyonu', 'project:e-ticaret-operasyon-kontrol-altyapisi'],
            'solution:after-effects-otomasyonu' => ['project:motioncurate-toplu-video-ve-render-otomasyonu'],
            'solution:render-farm-kurulumu' => ['project:motioncurate-toplu-video-ve-render-otomasyonu'],
            'service:toplu-video-ve-icerik-uretimi' => ['project:motioncurate-toplu-video-ve-render-otomasyonu'],
            'service:render-farm-sistemleri' => ['project:motioncurate-toplu-video-ve-render-otomasyonu'],
            'project:ticimax-nebim-cok-kanalli-operasyon' => ['solution:ticimax-nebim-entegrasyonu', 'service:pazaryeri-entegrasyonlari', 'project:e-ticaret-operasyon-kontrol-altyapisi'],
            'project:e-ticaret-operasyon-kontrol-altyapisi' => ['solution:e-ticaret-otomasyonu', 'service:ozel-yazilim-ve-operasyon-araclari', 'project:ticimax-nebim-cok-kanalli-operasyon'],
            'project:motioncurate-toplu-video-ve-render-otomasyonu' => ['solution:after-effects-otomasyonu', 'service:toplu-video-ve-icerik-uretimi', 'solution:render-farm-kurulumu'],
        ];
    }
}
