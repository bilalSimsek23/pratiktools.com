<?php

namespace App\Site;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starting content for media technologies & production automation: one
 * service category, service pages and ad/search landing pages. Inserted
 * only where the slug does not exist yet (never overwrites or restores
 * panel changes); everything stays editable in Web Sitesi.
 *
 * Text rules: production is described as an automatable technical
 * workflow; no team, studio, client, performance or AI-autonomy claims.
 * Adobe product names are used only for compatibility/automation context.
 */
final class MediaCatalog
{
    public const CATEGORY = ['slug' => 'medya-teknolojileri-ve-produksiyon-otomasyonu', 'name' => 'Medya Teknolojileri & Prodüksiyon Otomasyonu',
        'description' => 'Video, ses ve dijital içerik üretim süreçlerini özel yazılımlar, scriptler, paneller ve dağıtık render altyapılarıyla hızlandıran medya teknolojileri çözümleri.'];

    public const ADOBE_NOTE = "\n\n---\n\n*Adobe, After Effects ve Premiere Pro, Adobe Inc.'in ticari markalarıdır. PratikTools Adobe'nin iş ortağı veya yetkili temsilcisi değildir; bu ürünler burada yalnızca uyumluluk ve otomasyon bağlamında anılır.*";

    /**
     * Content-to-content links (internal linking), by slug.
     */
    private const RELATIONS = [
        'solution:after-effects-otomasyonu' => ['service:after-effects-script-ve-panel-gelistirme', 'service:toplu-video-ve-icerik-uretimi', 'service:render-otomasyonu'],
        'solution:premiere-pro-otomasyonu' => ['service:premiere-pro-otomasyonlari', 'service:ozel-produksiyon-araclari', 'service:video-ve-ses-produksiyonu'],
        'solution:render-farm-kurulumu' => ['service:render-farm-sistemleri', 'service:render-otomasyonu', 'service:toplu-video-ve-icerik-uretimi'],
        'solution:video-produksiyon-otomasyonu' => ['service:video-ve-ses-produksiyonu', 'service:after-effects-script-ve-panel-gelistirme', 'service:premiere-pro-otomasyonlari',
            'service:render-otomasyonu', 'service:toplu-video-ve-icerik-uretimi', 'service:ai-destekli-medya-is-akislari'],
        'service:after-effects-script-ve-panel-gelistirme' => ['solution:after-effects-otomasyonu', 'service:toplu-video-ve-icerik-uretimi'],
        'service:premiere-pro-otomasyonlari' => ['solution:premiere-pro-otomasyonu', 'service:ozel-produksiyon-araclari'],
        'service:render-otomasyonu' => ['service:render-farm-sistemleri', 'solution:render-farm-kurulumu'],
        'service:render-farm-sistemleri' => ['solution:render-farm-kurulumu', 'service:render-otomasyonu'],
        'service:toplu-video-ve-icerik-uretimi' => ['solution:after-effects-otomasyonu', 'service:render-otomasyonu'],
        'service:video-ve-ses-produksiyonu' => ['solution:video-produksiyon-otomasyonu', 'service:ozel-produksiyon-araclari'],
        'service:ozel-produksiyon-araclari' => ['service:after-effects-script-ve-panel-gelistirme', 'service:premiere-pro-otomasyonlari'],
        'service:ai-destekli-medya-is-akislari' => ['service:yapay-zeka-ve-is-sureci-otomasyonu', 'solution:video-produksiyon-otomasyonu'],
    ];

    /**
     * Inserts what is missing. Returns the number of rows created.
     */
    public static function install(): int
    {
        $created = 0;
        $now = now();
        $category = DB::table('site_categories')->where('kind', 'service')->where('slug', self::CATEGORY['slug'])->value('id');
        if ($category === null) {
            $category = (string) Str::uuid7();
            DB::table('site_categories')->insert(['id' => $category, 'kind' => 'service', 'name' => self::CATEGORY['name'], 'slug' => self::CATEGORY['slug'],
                'description' => self::CATEGORY['description'], 'sort_order' => (int) DB::table('site_categories')->where('kind', 'service')->max('sort_order') + 1,
                'active' => true, 'created_at' => $now, 'updated_at' => $now]);
            $created++;
        }

        $order = (int) DB::table('site_contents')->max('sort_order') + 1;
        $new = [];
        foreach ([...self::services(), ...self::solutions()] as $c) {
            if (DB::table('site_contents')->where('type', $c['type'])->where('slug', $c['slug'])->exists()) {
                continue;
            }
            $id = (string) Str::uuid7();
            DB::table('site_contents')->insert(['id' => $id, 'type' => $c['type'], 'slug' => $c['slug'], 'title' => $c['title'], 'excerpt' => $c['excerpt'], 'body' => $c['body'] ?? '',
                'data' => json_encode($c['data'], JSON_UNESCAPED_UNICODE), 'category_id' => $c['type'] === 'service' ? $category : null, 'status' => ContentStatus::Published->value,
                'seo_title' => $c['seo_title'] ?? null, 'seo_description' => $c['seo_description'] ?? null, 'sort_order' => $order++, 'published_at' => $now,
                'created_by' => 'system:initial-content', 'updated_by' => 'system:initial-content', 'published_by' => 'system:initial-content', 'created_at' => $now, 'updated_at' => $now]);
            $new["{$c['type']}:{$c['slug']}"] = $id;
            $created++;
        }

        // Links only from pages created now (never edits existing relations).
        foreach (self::RELATIONS as $from => $targets) {
            if (! isset($new[$from])) {
                continue;
            }
            foreach ($targets as $to) {
                [$type, $slug] = explode(':', $to, 2);
                $target = DB::table('site_contents')->where('type', $type)->where('slug', $slug)->value('id');
                if ($target !== null) {
                    DB::table('site_content_relations')->insertOrIgnore(['content_id' => $new[$from], 'related_id' => $target]);
                }
            }
        }
        SiteContents::bump();

        return $created;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function services(): array
    {
        return [
            ['type' => 'service', 'slug' => 'video-ve-ses-produksiyonu', 'title' => 'Video & Ses Prodüksiyonu',
                'seo_title' => 'Video ve Ses Prodüksiyonu — Teknik İş Akışıyla',
                'excerpt' => 'Dijital içerikler için video, kurgu, post-prodüksiyon ve ses işleme süreçleri.',
                'data' => [
                    'hero_title' => 'Prodüksiyonu tekrar eden işlerden arındırılmış bir iş akışı olarak ele alıyoruz',
                    'hero_text' => 'Video, kurgu, post-prodüksiyon ve ses işleme süreçlerinde yaratıcı işin yanında üretimin teknik altyapısını da kuruyoruz: çıktı hazırlama, format dönüşümü, medya organizasyonu ve otomasyon.',
                    'problem' => 'Dijital içerik üretiminde zamanın önemli bir kısmı yaratıcı işe değil; dosya düzenine, aynı çıktının farklı formatlarda hazırlanmasına, isimlendirmeye ve teslim adımlarına gider. Bu işler elle yapıldığında hem yavaşlar hem de hataya açık hale gelir.',
                    'approach' => 'Prodüksiyonu adım adım bir iş akışı olarak çıkarırız: kaynak medyanın nasıl geldiği, nasıl organize edildiği, hangi çıktıların hangi formatlarda üretildiği ve nasıl teslim edildiği. Tekrarlanan adımları scriptler, özel araçlar ve otomatik çıktı hazırlama ile hızlandırır; yaratıcı kararları ekipte bırakırız.',
                    'benefits' => ['Tekrar eden teknik işlere daha az zaman', 'Tutarlı dosya düzeni ve isimlendirme', 'Farklı platformlar için hızlı çıktı hazırlama', 'Ölçeklenebilir içerik üretimi'],
                    'features' => ['Kurgu ve post-prodüksiyon süreçleri', 'Ses işleme ve düzenleme', 'Çıktı hazırlama ve format dönüşümü', 'Medya ve proje organizasyonu', 'Tekrar eden prodüksiyon adımlarının otomasyonu'],
                    'systems' => ['Adobe After Effects', 'Adobe Premiere Pro', 'Dosya depolama ve paylaşım sistemleri'],
                    'use_cases' => ['Aynı videonun farklı sosyal medya formatlarına hazırlanması', 'Düzenli yayınlanan içeriklerde açılış/kapanış ve grafiklerin otomatik eklenmesi', 'Çekim sonrası medyanın standart klasör yapısına aktarılması'],
                    'faq' => [
                        ['q' => 'Yalnızca prodüksiyon mu yapıyorsunuz, yoksa süreç otomasyonu da mı?', 'a' => 'İkisini birlikte ele alıyoruz. Prodüksiyonun tekrar eden teknik kısımlarını script, araç ve otomasyonlarla hızlandırmak yaklaşımımızın merkezinde.'],
                        ['q' => 'Mevcut ekibimizin çalışma şeklini değiştirmek zorunda mıyız?', 'a' => 'Hayır. Önce mevcut iş akışını çıkarır, en çok zaman alan adımlardan başlarız; araçlar ekibin kullandığı yazılımların içinde veya yanında çalışacak şekilde tasarlanır.'],
                    ],
                    'cta_label' => 'Prodüksiyon sürecinizi anlatın',
                ]],
            ['type' => 'service', 'slug' => 'after-effects-script-ve-panel-gelistirme', 'title' => 'After Effects Script & Panel Geliştirme',
                'seo_title' => 'After Effects Script ve Panel Geliştirme',
                'seo_description' => 'After Effects\'te tekrarlanan işleri otomatikleştiren özel script, panel ve araçlar: composition oluşturma, şablon otomasyonu, veriyle içerik ve toplu render.',
                'excerpt' => 'Adobe After Effects için tekrarlanan işleri otomatikleştiren özel script, panel ve prodüksiyon araçları.',
                'data' => [
                    'hero_title' => 'After Effects\'te her gün tekrarlanan işleri bir kez otomatikleştirin',
                    'hero_text' => 'Composition hazırlama, şablon doldurma, toplu çıktı ve proje düzeni gibi işleri; After Effects\'in desteklediği scripting ve panel altyapıları üzerinde çalışan özel araçlarla otomatikleştiriyoruz.',
                    'problem' => 'Bir ekip her gün aynı adımları tekrar ediyorsa — yeni composition açmak, metin ve görselleri değiştirmek, katmanları düzenlemek, render kuyruğuna eklemek, dosyaları isimlendirmek — bu iş hem zaman kaybettirir hem de küçük hatalara açıktır.',
                    'approach' => 'Tekrarlanan işi adım adım çıkarır, sabit kalan kısmı şablona, değişen kısmı veriye dönüştürürüz. After Effects\'in scripting arayüzünü (uygun olduğu durumlarda ExtendScript) ve panel altyapısını kullanarak bu adımları tek tıkla veya toplu çalıştıran araçlar geliştiririz. Araçlar proje yapısını bozmayacak, hata durumunu raporlayacak şekilde tasarlanır.',
                    'benefits' => ['Saatler süren tekrar eden işlerin kısalması', 'Şablonlar arasında tutarlılık', 'Daha az manuel hata', 'Ekibin yaratıcı işe daha çok zaman ayırması'],
                    'features' => ['Özel script ve panel geliştirme', 'Composition oluşturma ve düzenleme', 'Şablon (template) otomasyonu', 'Veriden (tablo, CSV, API) içerik üretimi', 'Toplu işlemler', 'Render kuyruğu otomasyonu', 'Dosya ve proje organizasyonu'],
                    'systems' => ['Adobe After Effects', 'Tablo / CSV veri kaynakları', 'Render kuyruğu'],
                    'use_cases' => ['Bir tablodaki her satır için ayrı bir video varyasyonu hazırlanması', 'Haftalık içeriklerde metin ve görsel değişimlerinin tek adımda yapılması', 'Bitmiş composition\'ların toplu olarak render kuyruğuna alınması ve çıktıların otomatik isimlendirilmesi'],
                    'faq' => [
                        ['q' => 'Hangi işler otomatikleştirilebilir?', 'a' => 'Kurala bağlanabilen her tekrar eden adım: composition oluşturma, katman ve metin değişiklikleri, görsel değiştirme, render kuyruğuna ekleme, dosya isimlendirme ve klasörleme gibi.'],
                        ['q' => 'Script ile panel arasındaki fark nedir?', 'a' => 'Script belirli bir işi çalıştırır; panel, After Effects içinde ekibin bu işleri seçenekleriyle birlikte kolayca çalıştırabileceği bir arayüz sunar. İhtiyaca göre ikisini birlikte kullanırız.'],
                        ['q' => 'Mevcut şablonlarımız kullanılabilir mi?', 'a' => 'Çoğu durumda evet. Önce şablonların yapısını inceler, otomasyona uygun hale getirmek için gereken düzenlemeleri belirleriz.'],
                    ],
                    'cta_label' => 'After Effects sürecinizi anlatın',
                ],
                'body' => '## Ne zaman script, ne zaman panel?'."\n\n".'Tek bir işi hızlıca yapmak için küçük bir script yeterli olabilir. Birden fazla kişinin farklı seçeneklerle kullanacağı, düzenli tekrarlanan işler için ise After Effects içinde çalışan bir panel daha uygun olur. Hangisinin gerektiğine iş akışınızı inceledikten sonra birlikte karar veririz.'.self::ADOBE_NOTE],
            ['type' => 'service', 'slug' => 'premiere-pro-otomasyonlari', 'title' => 'Premiere Pro Otomasyonları',
                'seo_title' => 'Premiere Pro Otomasyonu, Script ve Panel Geliştirme',
                'excerpt' => 'Adobe Premiere Pro iş akışları için özel otomasyon, script ve prodüksiyon araçları.',
                'data' => [
                    'hero_title' => 'Premiere Pro\'daki tekrar eden kurgu işlerini sistematik hale getirin',
                    'hero_text' => 'Proje hazırlama, medya düzenleme, tekrar eden kurgu adımları ve çıktı süreçleri için Premiere Pro\'nun desteklediği otomasyon ve eklenti altyapıları üzerinde çalışan araçlar geliştiriyoruz.',
                    'problem' => 'Her yeni projede aynı klasör yapısını kurmak, medyayı içe aktarıp düzenlemek, standart sekansları hazırlamak ve aynı çıktı ayarlarını tekrar tekrar seçmek kurgu ekibinin zamanını tüketir.',
                    'approach' => 'Kurgu sürecinin sabit kalan adımlarını belirler, bunları otomatik proje hazırlama, toplu medya işlemleri ve standart çıktı adımlarına dönüştürürüz. Gerekirse ekibin Premiere Pro içinde kullanacağı özel bir panel veya araç geliştiririz; kurgu kararları editörde kalır.',
                    'benefits' => ['Her projeye hazır ve tutarlı bir başlangıç', 'Tekrar eden kurgu adımlarının kısalması', 'Standart çıktı ve teslim süreci'],
                    'features' => ['Otomatik proje ve klasör hazırlama', 'Medya içe aktarma ve düzenleme', 'Tekrar eden kurgu işlemleri', 'Toplu çıktı (export) süreçleri', 'Özel panel / araç entegrasyonu'],
                    'systems' => ['Adobe Premiere Pro', 'Adobe Media Encoder', 'Dosya depolama sistemleri'],
                    'use_cases' => ['Her bölüm için aynı proje yapısının tek adımda oluşturulması', 'Farklı platformlara aynı kurgudan toplu çıktı alınması', 'Gelen medyanın kurallara göre klasörlenip projeye eklenmesi'],
                    'faq' => [
                        ['q' => 'Kurgu tamamen otomatik mi oluyor?', 'a' => 'Hayır. Yaratıcı kurgu kararları editörde kalır; biz proje hazırlama, medya düzeni ve çıktı gibi tekrar eden teknik adımları otomatikleştiririz.'],
                        ['q' => 'Ekibimiz yeni bir yazılım öğrenmek zorunda mı?', 'a' => 'Araçlar mümkün olduğunca Premiere Pro\'nun içinde veya mevcut iş akışının yanında çalışacak şekilde tasarlanır.'],
                    ],
                    'cta_label' => 'Kurgu sürecinizi otomatikleştirelim',
                ],
                'body' => self::ADOBE_NOTE],
            ['type' => 'service', 'slug' => 'render-otomasyonu', 'title' => 'Render Otomasyonu',
                'seo_title' => 'Render Otomasyonu — Kuyruk, Takip ve Teslim',
                'excerpt' => 'Render kuyruğundan çıktı organizasyonuna kadar medya üretim süreçlerini otomatikleştiren sistemler.',
                'data' => [
                    'hero_title' => 'Render işlerini elle takip etmeyi bırakın',
                    'hero_text' => 'İşlerin kuyruğa alınmasından çıktıların isimlendirilip teslim edilmesine kadar render sürecini izlenebilir ve otomatik hale getiriyoruz.',
                    'problem' => 'Render süreci çoğu zaman bir makinenin başında beklemek, hata veren işi fark edip yeniden başlatmak ve çıktıları elle taşımak demektir. İş sayısı arttıkça bu yaklaşım ölçeklenmez.',
                    'approach' => 'Render işlerini bir iş kuyruğu üzerinden yönetiriz: her iş kayıt altına alınır, sırayla veya paralel çalıştırılır, hata verdiğinde kurala göre yeniden denenir. Çıktılar standart isimlendirme ve klasör yapısına göre yerleştirilir; durum takibi ve bildirimlerle ekip yalnızca gerektiğinde müdahale eder.',
                    'benefits' => ['Beklemeden çalışan render süreci', 'Hataların fark edilmesi ve yeniden denenmesi', 'Düzenli ve bulunabilir çıktılar', 'Sürecin anlık görünür olması'],
                    'features' => ['İş kuyruğu (job queue)', 'Otomatik render', 'Hata ve yeniden deneme yönetimi', 'Çıktı isimlendirme ve klasörleme', 'Teslim iş akışı', 'Durum takibi', 'Bildirim entegrasyonu'],
                    'systems' => ['Adobe After Effects', 'Adobe Media Encoder', 'Dosya depolama', 'Bildirim kanalları (e-posta, mesajlaşma)'],
                    'use_cases' => ['Gece boyunca yüzlerce render işinin sırayla tamamlanması ve sabah raporlanması', 'Hata veren işlerin otomatik yeniden denenmesi', 'Tamamlanan çıktıların teslim klasörüne taşınması ve ekibe bildirilmesi'],
                    'faq' => [
                        ['q' => 'Tek bir bilgisayarda da işe yarar mı?', 'a' => 'Evet. Kuyruk, yeniden deneme ve çıktı düzeni tek makinede de zaman kazandırır. İhtiyaç büyüdüğünde aynı yapı birden fazla render node\'a genişletilebilir.'],
                        ['q' => 'Bir render hata verirse ne olur?', 'a' => 'Hata kaydedilir, tanımlı kurala göre yeniden denenir; tekrar başarısız olursa ilgili kişiye bildirim gider.'],
                    ],
                    'cta_label' => 'Render sürecinizi anlatın',
                ]],
            ['type' => 'service', 'slug' => 'render-farm-sistemleri', 'title' => 'Render Farm Sistemleri',
                'seo_title' => 'Render Farm Sistemleri — Dağıtık Render Altyapısı',
                'excerpt' => 'Yüksek hacimli medya üretimleri için dağıtık render node ve render farm altyapıları.',
                'data' => [
                    'hero_title' => 'Render hacminiz tek makineyi aştığında',
                    'hero_text' => 'Render işlerini birden fazla render node\'a dağıtan, kuyruğu ve node sağlığını merkezi olarak izleyen render farm altyapıları kuruyoruz.',
                    'problem' => 'Üretim hacmi arttığında tek bir iş istasyonu darboğaz olur: işler birikir, teslimler gecikir ve render sırasında bilgisayar başka iş için kullanılamaz.',
                    'approach' => 'İşleri merkezi bir kuyrukta toplar, uygun render node\'lara dağıtırız. Her node\'un durumu izlenir; erişilemeyen veya hata veren node\'daki iş başka bir node\'da yeniden denenir. Node\'lar aynı ağda, uzak konumlarda veya ikisinin karışımı olabilir; kapasite iş yüküne göre planlanır.',
                    'benefits' => ['Darboğazın tek makineden kalkması', 'İş yüküne göre büyüyebilen kapasite', 'Merkezi takip ve görünürlük', 'Node hatalarına karşı dayanıklılık'],
                    'features' => ['Birden fazla render node', 'İş dağıtımı ve kuyruk', 'Worker / node sağlık takibi', 'Yeniden deneme', 'Kapasite yönetimi', 'Uzaktan node bağlantısı', 'Merkezi takip ekranı'],
                    'systems' => ['Render node\'ları', 'İş kuyruğu', 'Paylaşımlı veya bulut depolama'],
                    'use_cases' => ['Kampanya dönemlerinde yoğunlaşan render işlerinin node\'lara dağıtılması', 'Farklı lokasyonlardaki bilgisayarların ortak render kapasitesine eklenmesi', 'Node kesintisinde işlerin başka node\'da tamamlanması'],
                    'faq' => [
                        ['q' => 'Ne kadar hızlanma sağlar?', 'a' => 'Bu; iş türüne, node sayısına, donanıma ve depolama altyapısına bağlıdır. Genel bir rakam vermek yerine mevcut iş yükünüzü inceleyip birlikte planlıyoruz.'],
                        ['q' => 'Bulut mu, kendi bilgisayarlarımız mı?', 'a' => 'Her ikisi de mümkün. Mevcut donanımı (on-prem), uzak node\'ları veya hibrit bir yapıyı ihtiyaca göre değerlendiriyoruz.'],
                    ],
                    'cta_label' => 'Render altyapınızı birlikte planlayalım',
                ]],
            ['type' => 'service', 'slug' => 'toplu-video-ve-icerik-uretimi', 'title' => 'Toplu Video ve İçerik Üretimi',
                'seo_title' => 'Toplu Video ve İçerik Üretimi — Şablon + Veri',
                'excerpt' => 'Şablon ve veri kaynaklarından yüksek hacimli video ve görsel varyasyonları üreten otomasyon sistemleri.',
                'data' => [
                    'hero_title' => 'Yüzlerce varyasyonu tek tek hazırlamayın',
                    'hero_text' => 'Şablonları veri kaynaklarıyla birleştirip video ve görsel varyasyonlarını toplu olarak üreten, render eden ve düzenleyen sistemler kuruyoruz.',
                    'problem' => 'Kampanyalar, ürünler, diller veya kanallar için yüzlerce varyasyon gerektiğinde her birini elle hazırlamak haftalar sürebilir ve tutarsızlıklara yol açar.',
                    'approach' => 'Sabit tasarımı şablona, değişen içeriği (metin, görsel, fiyat, dil, logo) veriye dönüştürürüz. Veri kaynağındaki her satır için bir varyasyon oluşturulur, render kuyruğuna alınır ve çıktılar sürüm bilgisiyle düzenlenir. Kontrol adımları hatalı varyasyonun teslime gitmesini önler.',
                    'benefits' => ['Yüksek hacimde tutarlı çıktı', 'Değişikliklerin tüm varyasyonlara hızlı yansıması', 'Yerelleştirme ve kanal uyarlamalarının kolaylaşması'],
                    'features' => ['Şablon tabanlı üretim', 'Dinamik metin, görsel ve veri', 'Toplu render', 'Kampanya varyasyonları', 'Yerelleştirme (lokalizasyon)', 'Sürüm yönetimi', 'Çıktı yönetimi'],
                    'systems' => ['Adobe After Effects', 'Tablo / CSV / API veri kaynakları', 'Render kuyruğu'],
                    'use_cases' => ['Ürün kataloğundaki her ürün için kısa tanıtım videosu', 'Aynı kampanyanın farklı dil ve ölçülerde üretilmesi', 'Fiyat veya metin değiştiğinde tüm varyasyonların yeniden üretilmesi'],
                    'faq' => [
                        ['q' => 'Veriyi nereden alıyorsunuz?', 'a' => 'Tablo, CSV dosyası veya bir API gibi kaynaklardan. Önemli olan verinin şablondaki alanlarla eşleşebilecek bir yapıda olmasıdır.'],
                        ['q' => 'Hatalı bir varyasyon nasıl yakalanır?', 'a' => 'Eksik veya kurallara uymayan veriler üretimden önce raporlanır; çıktılar teslimden önce kontrol edilebilecek şekilde düzenlenir.'],
                    ],
                    'cta_label' => 'Varyasyon ihtiyacınızı anlatın',
                ]],
            ['type' => 'service', 'slug' => 'ozel-produksiyon-araclari', 'title' => 'Özel Prodüksiyon Araçları',
                'seo_title' => 'Özel Prodüksiyon Araçları — Script, Panel, Web ve Masaüstü',
                'excerpt' => 'Hazır prodüksiyon yazılımlarının karşılamadığı ihtiyaçlar için özel script, panel, web ve masaüstü araçları.',
                'data' => [
                    'hero_title' => 'Hazır yazılımın çözemediği iş akışı için sürece özel araç',
                    'hero_text' => 'Her prodüksiyon ekibinin kendine özgü bir çalışma şekli vardır. Hazır yazılımların karşılamadığı adımlar için o sürece özel script, panel, web veya masaüstü araçları geliştiriyoruz.',
                    'problem' => 'Hazır yazılımlar genel ihtiyaçlar için tasarlanır. Ekibe özgü dosya düzeni, onay adımları, teslim formatları veya sistemler arası veri aktarımı çoğu zaman elle yapılan ara işlere dönüşür.',
                    'approach' => 'İş akışını uçtan uca çıkarır, elle yapılan ara adımları belirleriz. Doğru yeri seçeriz: kimi zaman yazılımın içinde çalışan bir script veya panel, kimi zaman ekibin tarayıcıdan kullandığı bir web aracı, kimi zaman bir masaüstü yardımcısı. Araçlar mevcut yazılımlarla birlikte çalışır, onların yerine geçmez.',
                    'benefits' => ['Sürece birebir uyan araçlar', 'Elle yapılan ara işlerin azalması', 'Mevcut yazılım yatırımının korunması'],
                    'features' => ['Prodüksiyon yazılımları için script ve paneller', 'Web tabanlı iş takip ve onay araçları', 'Masaüstü yardımcı araçlar', 'Sistemler arası veri aktarımı'],
                    'systems' => ['Adobe After Effects', 'Adobe Premiere Pro', 'Web ve masaüstü uygulamaları'],
                    'use_cases' => ['Ekibin çekim listesini, durumunu ve çıktıları tek ekrandan takip etmesi', 'Müşteri onay adımlarının bir web aracına taşınması', 'Farklı yazılımlar arasında dosya ve bilgi aktarımının otomatikleşmesi'],
                    'faq' => [
                        ['q' => 'Bu aracı neden hazır bir yazılımla çözemiyoruz?', 'a' => 'Bazen çözebilirsiniz; önce bunu birlikte değerlendiririz. Özel araç, hazır çözümlerin süreci zorladığı veya ara işleri ortadan kaldıramadığı durumlarda anlamlıdır.'],
                    ],
                    'cta_label' => 'İş akışınızı anlatın',
                ]],
            ['type' => 'service', 'slug' => 'ai-destekli-medya-is-akislari', 'title' => 'AI Destekli Medya İş Akışları',
                'seo_title' => 'AI Destekli Medya İş Akışları — Otomasyon ve İnsan Kontrolü',
                'excerpt' => 'Yapay zekâyı video, ses ve dijital içerik üretim zincirlerine entegre eden özel workflow\'lar.',
                'data' => [
                    'hero_title' => 'Yapay zekâ + kurallı otomasyon + insan kontrolü',
                    'hero_text' => 'Yapay zekâyı medya üretim zincirinin uygun adımlarına yerleştiriyor; kurallı otomasyon ve ekip onayıyla birlikte, denetlenebilir iş akışları kuruyoruz.',
                    'problem' => 'Yapay zekâ araçları tek başına kullanıldığında sonuçlar tutarsız olabilir, üretim sürecine bağlanmaz ve kalite kontrolü belirsiz kalır.',
                    'approach' => 'Yapay zekâyı yalnızca fayda sağladığı adımlarda kullanırız — örneğin taslak metin, transkript, etiketleme veya içerik önerisi. Bu adımların çıktıları kurallı otomasyonla doğrulanır ve yayına ya da teslime gitmeden önce ekip onayından geçer. Her adım kayıt altına alınır.',
                    'benefits' => ['Tekrarlayan hazırlık işlerinin hızlanması', 'Kontrol edilebilir ve tekrar üretilebilir sonuçlar', 'Mevcut üretim akışıyla uyum'],
                    'features' => ['Transkript ve altyazı taslakları', 'Medya etiketleme ve sınıflandırma', 'Metin ve açıklama taslakları', 'Kurallı doğrulama adımları', 'İnsan onaylı yayın / teslim'],
                    'systems' => ['Büyük dil modeli API\'leri', 'Konuşma tanıma servisleri', 'Medya iş akışı araçları'],
                    'use_cases' => ['Videolar için transkript taslağının hazırlanıp editör onayına sunulması', 'Arşivdeki medyanın konu ve içerik türüne göre etiketlenmesi', 'Toplu üretilen videolar için açıklama metni taslakları'],
                    'faq' => [
                        ['q' => 'Yapay zekâ işi tamamen kendi mi yapıyor?', 'a' => 'Hayır. Yapay zekâ belirli adımlarda taslak ve öneri üretir; sonuçlar kurallarla kontrol edilir ve ekip onayıyla kullanılır.'],
                        ['q' => 'Medyamız üçüncü taraf servislere gönderiliyor mu?', 'a' => 'Yalnızca görevin gerektirdiği kadar ve sizin onayınızla. Hangi verinin hangi servise gittiği iş akışı tasarımında açıkça belirlenir.'],
                    ],
                    'cta_label' => 'Medya iş akışınızı konuşalım',
                ]],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function solutions(): array
    {
        return [
            ['type' => 'solution', 'slug' => 'after-effects-otomasyonu', 'title' => 'After Effects Otomasyonu',
                'seo_title' => 'After Effects Otomasyonu — Script, Panel ve Toplu Render',
                'seo_description' => 'After Effects\'te tekrar eden işleri özel script ve panellerle otomatikleştirin: şablon üretimi, veri bazlı composition, toplu render ve iş akışı otomasyonu.',
                'excerpt' => 'Tekrar eden After Effects işlerini özel script, panel ve otomasyonlarla hızlandırın.',
                'data' => [
                    'hero_title' => 'After Effects\'te aynı işi her gün yeniden yapmayın',
                    'hero_text' => 'Composition hazırlama, şablon doldurma, veri bazlı içerik ve toplu render gibi işleri özel script ve panellerle otomatikleştiriyoruz.',
                    'problem' => 'Tekrar eden After Effects işlemleri — her içerik için composition açmak, metin ve görselleri değiştirmek, render kuyruğuna eklemek, çıktıları isimlendirmek — ekiplerin zamanının büyük bir bölümünü tüketir ve hacim arttıkça sürdürülemez hale gelir.',
                    'approach' => 'İşin sabit kalan kısmını şablona, değişen kısmını veriye dönüştürür; After Effects\'in desteklediği scripting ve panel altyapıları üzerinde çalışan özel araçlar geliştiririz. Toplu işler render kuyruğuna otomatik alınır, çıktılar standart isimlendirmeyle düzenlenir.',
                    'benefits' => ['Tekrar eden işlerin belirgin şekilde kısalması', 'Şablonlar arasında tutarlılık', 'Yüksek hacimli üretimde ölçeklenebilirlik'],
                    'features' => ['Özel script ve panel geliştirme', 'Şablon üretimi ve otomasyonu', 'Veri bazlı composition oluşturma', 'Toplu render', 'İş akışı otomasyonu'],
                    'systems' => ['Adobe After Effects', 'Tablo / CSV / API veri kaynakları'],
                    'use_cases' => ['Şablondan düzenli içerik üretimi', 'Toplu render', 'Veri bazlı composition', 'Ekip içi iş akışı otomasyonu'],
                    'examples' => ['Bir tablodaki her ürün için ayrı tanıtım videosu üretilmesi', 'Haftalık yayın grafiklerinin tek panelden hazırlanması', 'Bitmiş işlerin gece toplu render edilip sabah teslim klasöründe olması'],
                    'faq' => [
                        ['q' => 'After Effects otomasyonu için ne gerekiyor?', 'a' => 'Tekrarlanan işin adımları ve kullanılan şablonlar. İlk görüşmede bu adımları birlikte çıkarıp hangi kısmın otomatikleşebileceğini belirliyoruz.'],
                        ['q' => 'Toplu render nasıl çalışıyor?', 'a' => 'Hazırlanan composition\'lar otomatik olarak render kuyruğuna alınır; çıktılar kurallara göre isimlendirilip klasörlenir. Hacim yüksekse render farm ile birden fazla makineye dağıtılabilir.'],
                    ],
                    'cta_label' => 'After Effects sürecinizi anlatın', 'cta_text' => 'Hangi işleri tekrar tekrar yaptığınızı kısaca yazın; otomasyon için uygulanabilir bir yol önerelim.',
                    'form_kind' => 'proje',
                ],
                'body' => self::ADOBE_NOTE],
            ['type' => 'solution', 'slug' => 'premiere-pro-otomasyonu', 'title' => 'Premiere Pro Otomasyonu',
                'seo_title' => 'Premiere Pro Otomasyonu — Script, Panel ve Video İş Akışı',
                'seo_description' => 'Premiere Pro\'da proje hazırlama, medya düzenleme, tekrar eden kurgu adımları ve toplu çıktı süreçlerini özel script ve panellerle otomatikleştirin.',
                'excerpt' => 'Proje hazırlamadan toplu çıktıya, Premiere Pro iş akışlarını otomatikleştirin.',
                'data' => [
                    'hero_title' => 'Kurgu ekibiniz kurguya odaklansın',
                    'hero_text' => 'Premiere Pro\'da proje hazırlama, medya düzenleme ve çıktı süreçlerini özel script, panel ve otomasyonlarla sistematik hale getiriyoruz.',
                    'problem' => 'Her projede aynı hazırlık adımları, aynı medya düzeni ve aynı çıktı ayarları tekrarlanır. Bu teknik işler kurgu süresinden çalar ve standartların kaybolmasına yol açar.',
                    'approach' => 'Sabit adımları otomatik proje şablonlarına, kurallı medya işlemlerine ve standart çıktı adımlarına dönüştürürüz. Ekibin Premiere Pro içinde kullanacağı özel bir panel veya araçla bu adımlar tek yerden çalıştırılır.',
                    'benefits' => ['Her projeye hazır başlangıç', 'Standart ve hatasız çıktılar', 'Kurguya daha fazla zaman'],
                    'features' => ['Proje hazırlama otomasyonu', 'Medya düzenleme', 'Toplu çıktı süreçleri', 'Özel panel / araç', 'Video iş akışı otomasyonu'],
                    'systems' => ['Adobe Premiere Pro', 'Adobe Media Encoder'],
                    'examples' => ['Yeni bölüm için hazır proje yapısının tek adımda oluşması', 'Aynı kurgudan farklı platform çıktılarının toplu alınması'],
                    'faq' => [
                        ['q' => 'Hangi Premiere Pro işleri otomatikleştirilebilir?', 'a' => 'Proje ve klasör hazırlama, medya içe aktarma ve düzenleme, tekrar eden sekans işlemleri ve çıktı adımları gibi kurala bağlanabilen işler.'],
                    ],
                    'cta_label' => 'Kurgu sürecinizi otomatikleştirelim', 'form_kind' => 'proje',
                ],
                'body' => self::ADOBE_NOTE],
            ['type' => 'solution', 'slug' => 'render-farm-kurulumu', 'title' => 'Render Farm Kurulumu',
                'seo_title' => 'Render Farm Kurulumu — Dağıtık Render, Node ve Kuyruk',
                'seo_description' => 'Render farm nedir, ne zaman gerekir? Dağıtık render, render node, iş kuyruğu, izleme ve hata kurtarma ile on-prem, uzak veya hibrit render altyapısı kurulumu.',
                'excerpt' => 'Dağıtık render, render node, kuyruk, izleme ve hata kurtarma ile render altyapınızı planlayın.',
                'data' => [
                    'hero_title' => 'Render darboğazını altyapıyla çözün',
                    'hero_text' => 'Render işlerini birden fazla makineye dağıtan, merkezi kuyruk ve izleme ile çalışan render farm altyapıları planlıyor ve kuruyoruz.',
                    'problem' => 'Render farm, render işlerini birden fazla bilgisayara (render node) dağıtarak paralel çalıştıran altyapıdır. Tek iş istasyonunda işler birikiyorsa, teslim süreleri render yüzünden uzuyorsa veya render sırasında bilgisayarlar kullanılamıyorsa render farm gündeme gelir.',
                    'approach' => "İşler merkezi bir kuyrukta toplanır ve uygun render node'lara dağıtılır (dağıtık render). Her node'un sağlığı izlenir; hata veren veya bağlantısı kopan node'daki iş başka bir node'da yeniden denenir (hata kurtarma). Node'lar aynı ofiste (on-prem), uzak lokasyonlarda veya ikisinin birleşimi olan hibrit bir yapıda çalışabilir. Önce mevcut iş yükünü ve depolama altyapısını inceler, kapasiteyi buna göre planlarız.",
                    'benefits' => ['Render beklemesinin azalması', 'İş yüküne göre büyüyebilen kapasite', 'Merkezi görünürlük', 'Node hatalarına karşı dayanıklılık'],
                    'features' => ['Dağıtık render', 'Render node kurulumu', 'İş kuyruğu', 'İzleme ve durum takibi', 'Hata kurtarma ve yeniden deneme', 'On-prem, uzak veya hibrit yapı'],
                    'systems' => ['Render node\'ları', 'İş kuyruğu', 'Paylaşımlı / bulut depolama'],
                    'examples' => ['Yoğun dönemlerde birikmiş işlerin node\'lara dağıtılması', 'Ofis dışındaki bilgisayarların render kapasitesine eklenmesi', 'Kesintiye uğrayan işin başka node\'da tamamlanması'],
                    'faq' => [
                        ['q' => 'Render farm nedir?', 'a' => 'Render işlerini birden fazla bilgisayara dağıtıp paralel çalıştıran, merkezi bir kuyruk ve izleme ile yönetilen altyapıdır.'],
                        ['q' => 'Render farm ne zaman gerekir?', 'a' => 'Render işleri tek makinede birikmeye başladığında, teslim süreleri render yüzünden uzadığında veya ekip render sırasında çalışamadığında.'],
                        ['q' => 'Ne kadar hızlanma beklemeliyiz?', 'a' => 'İş türüne, node sayısına, donanıma ve depolamaya bağlı olduğu için genel bir rakam vermiyoruz; mevcut iş yükünüzü inceleyerek gerçekçi bir plan çıkarıyoruz.'],
                        ['q' => 'On-prem mi, bulut mu?', 'a' => 'Mevcut donanım, güvenlik ve maliyet ihtiyaçlarına göre on-prem, uzak veya hibrit yapıyı birlikte değerlendiriyoruz.'],
                    ],
                    'cta_label' => 'Render altyapınızı birlikte planlayalım', 'form_kind' => 'gorusme',
                ]],
            ['type' => 'solution', 'slug' => 'video-produksiyon-otomasyonu', 'title' => 'Video Prodüksiyon Otomasyonu',
                'seo_title' => 'Video Prodüksiyon Otomasyonu — Şablon, Render ve Medya İş Akışı',
                'seo_description' => 'Video üretimindeki tekrar eden işleri otomatikleştirin: toplu içerik, şablon otomasyonu, render, dosya yönetimi, medya iş akışı ve kontrollü yapay zekâ entegrasyonu.',
                'excerpt' => 'Video üretimindeki tekrar eden işleri şablon, render ve medya iş akışı otomasyonuyla hızlandırın.',
                'data' => [
                    'hero_title' => 'Video prodüksiyonunu otomatikleştirilebilir bir iş akışı olarak kurun',
                    'hero_text' => 'Prodüksiyonu yalnızca içerik üretimi olarak değil, tekrar eden adımları otomatikleştirilebilen teknik bir süreç olarak ele alıyoruz.',
                    'problem' => 'Video üretiminde aynı adımlar sürekli tekrar eder: şablon doldurma, varyasyon hazırlama, render takibi, dosya taşıma ve isimlendirme. Hacim arttıkça bu işler ekibin zamanını yaratıcı işten uzaklaştırır.',
                    'approach' => 'Süreci uçtan uca çıkarır, her darboğaza uygun yaklaşımı seçeriz: tekrar eden yazılım içi işler için script ve paneller; yüzlerce varyasyon için şablon + veri + render kuyruğu; render darboğazı için render farm; dosya düzeni ve teslim için otomatik medya iş akışı. Yapay zekâyı yalnızca fayda sağladığı adımlarda, kurallı kontrol ve insan onayıyla birlikte kullanırız.',
                    'benefits' => ['Tekrar eden işlerin azalması', 'Yüksek hacimde tutarlı üretim', 'Görünür ve izlenebilir süreç'],
                    'features' => ['Toplu içerik üretimi', 'Şablon otomasyonu', 'Render otomasyonu ve render farm', 'Dosya ve medya yönetimi', 'Medya iş akışı otomasyonu', 'Kontrollü yapay zekâ entegrasyonu'],
                    'systems' => ['Adobe After Effects', 'Adobe Premiere Pro', 'Render altyapısı', 'Depolama ve paylaşım sistemleri'],
                    'examples' => ['Kampanya varyasyonlarının veriden toplu üretilmesi', 'Render ve teslim adımlarının otomatik takibi', 'Transkript ve açıklama taslaklarının editör onayına sunulması'],
                    'faq' => [
                        ['q' => 'Nereden başlamalıyız?', 'a' => 'En çok zaman alan ve en sık tekrarlanan adımdan. İlk görüşmede süreci birlikte çıkarıp en hızlı fayda sağlayacak otomasyonu belirliyoruz.'],
                        ['q' => 'Yapay zekâ nerede kullanılıyor?', 'a' => 'Yalnızca fayda sağladığı adımlarda (ör. transkript, etiketleme, metin taslağı) ve her zaman kurallı kontrol ve insan onayıyla.'],
                    ],
                    'cta_label' => 'Prodüksiyon sürecinizi anlatın', 'form_kind' => 'proje',
                ],
                'body' => self::ADOBE_NOTE],
        ];
    }
}
