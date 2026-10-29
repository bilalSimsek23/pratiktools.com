<?php

namespace App\Site;

/**
 * Public website content types. fields() describes the type-specific,
 * structured fields (site_contents.data) for the admin form and for
 * validation; nothing in them is executable.
 *
 * kinds: text (one line), textarea (plain text), list (one item per line),
 * faq (blocks separated by a blank line: first line question, rest answer),
 * steps (one "Title | text" per line), cards (one "Title | text | /path"
 * per line; site-internal paths only), bool, select.
 */
enum ContentType: string
{
    case Service = 'service';
    case Solution = 'solution';
    case Project = 'project';
    case Article = 'article';
    case Page = 'page';

    public function routePrefix(): ?string
    {
        return match ($this) {
            self::Service => 'hizmetler',
            self::Solution => 'cozumler',
            self::Project => 'projeler',
            self::Article => 'blog',
            self::Page => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Service => 'Hizmet',
            self::Solution => 'Çözüm (landing page)',
            self::Project => 'Proje',
            self::Article => 'Blog yazısı',
            self::Page => 'Sayfa',
        };
    }

    public function plural(): string
    {
        return match ($this) {
            self::Service => 'Hizmetler',
            self::Solution => 'Çözümler',
            self::Project => 'Projeler',
            self::Article => 'Blog',
            self::Page => 'Sayfalar',
        };
    }

    public function categoryKind(): ?string
    {
        return match ($this) {
            self::Service => 'service',
            self::Article => 'article',
            default => null,
        };
    }

    /**
     * @return list<array{key: string, label: string, kind: string, max?: int, options?: array<string, string>, help?: string}>
     */
    public function fields(): array
    {
        $offer = [
            ['key' => 'hero_title', 'label' => 'Hero başlığı', 'kind' => 'text', 'max' => 160],
            ['key' => 'hero_text', 'label' => 'Hero metni', 'kind' => 'textarea', 'max' => 600],
            ['key' => 'problem', 'label' => 'Problem (hangi sorunu çözüyor)', 'kind' => 'textarea', 'max' => 2000],
            ['key' => 'approach', 'label' => 'Teknik yaklaşım', 'kind' => 'textarea', 'max' => 3000],
            ['key' => 'benefits', 'label' => 'Faydalar', 'kind' => 'list', 'max' => 300],
            ['key' => 'features', 'label' => 'Kapsam / özellikler', 'kind' => 'list', 'max' => 300],
            ['key' => 'systems', 'label' => 'Entegre olunan sistemler', 'kind' => 'list', 'max' => 120],
            ['key' => 'use_cases', 'label' => 'Örnek kullanım senaryoları', 'kind' => 'list', 'max' => 400],
            ['key' => 'faq', 'label' => 'Sık sorulan sorular', 'kind' => 'faq', 'max' => 1500, 'help' => 'Her soru-cevap bir blok: ilk satır soru, alt satırlar cevap; bloklar arasında boş satır.'],
            ['key' => 'cta_label', 'label' => 'CTA buton metni', 'kind' => 'text', 'max' => 60],
            ['key' => 'cta_text', 'label' => 'CTA açıklaması', 'kind' => 'textarea', 'max' => 400],
        ];

        return match ($this) {
            self::Service => $offer,
            self::Solution => [...$offer,
                ['key' => 'examples', 'label' => 'Örnekler', 'kind' => 'list', 'max' => 400],
                ['key' => 'form_kind', 'label' => 'Form tipi', 'kind' => 'select', 'options' => SiteInquiries::KINDS],
            ],
            self::Project => [
                ['key' => 'client_display', 'label' => 'Yayında görünecek müşteri tanımı (anonim)', 'kind' => 'text', 'max' => 160, 'help' => 'Örn. "Türkiye\'de büyük erkek giyim markası".'],
                ['key' => 'client_name', 'label' => 'Gerçek müşteri adı (yalnızca onaylıysa yayınlanır)', 'kind' => 'text', 'max' => 160],
                ['key' => 'client_named', 'label' => 'Müşteri adı yayınlansın', 'kind' => 'bool', 'help' => 'Yayın için müşteri onayı kaydı gerekir.'],
                ['key' => 'industry', 'label' => 'Sektör', 'kind' => 'text', 'max' => 120],
                ['key' => 'challenge', 'label' => 'Problem', 'kind' => 'textarea', 'max' => 3000],
                ['key' => 'solution_text', 'label' => 'Çözüm', 'kind' => 'textarea', 'max' => 4000],
                ['key' => 'technologies', 'label' => 'Teknolojiler', 'kind' => 'list', 'max' => 120],
                ['key' => 'integrations', 'label' => 'Entegrasyonlar', 'kind' => 'list', 'max' => 120],
                ['key' => 'outcome', 'label' => 'Ölçülebilir sonuç (yalnız doğrulanmış)', 'kind' => 'textarea', 'max' => 2000],
            ],
            self::Article => [
                ['key' => 'author', 'label' => 'Yazar', 'kind' => 'text', 'max' => 120],
            ],
            self::Page => [
                ['key' => 'hero_title', 'label' => 'Hero başlığı', 'kind' => 'text', 'max' => 160],
                ['key' => 'hero_text', 'label' => 'Hero metni', 'kind' => 'textarea', 'max' => 600],
                ['key' => 'capabilities_intro', 'label' => 'Yetkinlikler girişi (ana sayfa)', 'kind' => 'textarea', 'max' => 600],
                ['key' => 'capability_cards', 'label' => 'Ana hizmet kartları (ana sayfa)', 'kind' => 'cards', 'max' => 300, 'help' => 'Her satır: Başlık | kısa açıklama | /bağlantı (site içi adres).'],
                ['key' => 'integrations', 'label' => 'Çalışılan platformlar (ana sayfa)', 'kind' => 'list', 'max' => 80],
                ['key' => 'how_we_work', 'label' => 'Nasıl çalışıyoruz (ana sayfa)', 'kind' => 'steps', 'max' => 400, 'help' => 'Her satır: Başlık | açıklama'],
                ['key' => 'cta_title', 'label' => 'CTA başlığı', 'kind' => 'text', 'max' => 160],
                ['key' => 'cta_text', 'label' => 'CTA metni', 'kind' => 'textarea', 'max' => 400],
            ],
        };
    }
}
