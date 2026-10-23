<?php

namespace App\Connectors\Ticimax;

use App\Commerce\Data\CartItemRecord;
use App\Commerce\Data\CartRecord;
use App\Commerce\Data\CategoryRecord;
use App\Commerce\Data\CustomerRecord;
use App\Commerce\Data\OrderItemRecord;
use App\Commerce\Data\OrderRecord;
use App\Commerce\Data\ProductRecord;
use App\Commerce\Data\VariantRecord;
use App\Connectors\Ticimax\TicimaxResponse as R;
use App\Integrations\Errors\ProviderError;

/**
 * Ticimax payload -> provider-independent records. Field names follow the
 * live WSDLs (UyeServis.Uye, CustomServis.MailList, UrunServis.UrunKarti /
 * Varyasyon / Kategori, SiparisServis.WebSiparis / WebSiparisUrun /
 * WebSepet / WebSepetUrun).
 */
final class TicimaxMapper
{
    public function __construct(
        private readonly string $timezone,
        private readonly string $storefrontUrl,
        /** @var list<string> */
        private readonly array $marketplaceSources,
    ) {}

    /**
     * @param  array<string, mixed>  $u
     */
    public function member(array $u): CustomerRecord
    {
        $id = R::id($u['ID'] ?? null) ?? throw ProviderError::schema('Member without ID.', 'SelectUyeler');

        return new CustomerRecord(
            kind: CustomerRecord::KIND_MEMBER,
            externalId: $id,
            email: R::string($u['Mail'] ?? null),
            phone: R::string($u['CepTelefonu'] ?? null) ?? R::string($u['Telefon'] ?? null),
            firstName: R::string($u['Isim'] ?? null),
            lastName: R::string($u['Soyisim'] ?? null),
            birthDate: R::date($u['DogumTarihi'] ?? null, $this->timezone),
            // Ticimax CinsiyetID is stored as the raw code; its labels are not
            // documented in the WSDL, so no male/female mapping is guessed.
            gender: ($g = R::id($u['CinsiyetID'] ?? null)) !== null ? 'ticimax:'.$g : null,
            city: R::string($u['Il'] ?? null),
            district: R::string($u['Ilce'] ?? null),
            memberSince: R::date($u['UyelikTarihi'] ?? null, $this->timezone),
            isActive: is_bool($u['Aktif'] ?? null) ? $u['Aktif'] : null,
            updatedAt: R::date($u['DuzenlemeTarihi'] ?? null, $this->timezone),
            emailOptIn: is_bool($u['MailIzin'] ?? null) ? $u['MailIzin'] : null,
            smsOptIn: is_bool($u['SmsIzin'] ?? null) ? $u['SmsIzin'] : null,
            permissionUpdatedAt: R::date($u['IzinGuncellemeTarihi'] ?? null, $this->timezone),
            groups: array_filter([R::string($u['UyeTuru'] ?? null)]),
            attributes: array_filter([
                'customer_group_id' => R::id($u['UyeTuruID'] ?? null),
                'membership_source' => is_numeric($u['UyelikKaynagi'] ?? null) ? (int) $u['UyelikKaynagi'] : null,
                'approved' => is_bool($u['Onay'] ?? null) ? $u['Onay'] : null,
                'kvkk_accepted' => is_bool($u['KVKKSozlesmeOnay'] ?? null) ? $u['KVKKSozlesmeOnay'] : null,
                'last_login_at' => R::date($u['SonGirisTarihi'] ?? null, $this->timezone)?->toIso8601String(),
                'city_id' => R::id($u['IlID'] ?? null),
            ], fn ($v) => $v !== null),
        );
    }

    /**
     * Newsletter-only subscriber (CustomServis.MailList).
     *
     * @param  array<string, mixed>  $m
     */
    public function subscriber(array $m): CustomerRecord
    {
        $id = R::id($m['ID'] ?? null) ?? throw ProviderError::schema('Subscriber without ID.', 'SelectMailList');
        $added = R::date($m['EklemeTarihi'] ?? null, $this->timezone);

        return new CustomerRecord(
            kind: CustomerRecord::KIND_SUBSCRIBER,
            externalId: $id,
            email: R::string($m['Mail'] ?? null),
            phone: R::string($m['Telefon'] ?? null),
            firstName: R::string($m['Isim'] ?? null),
            lastName: R::string($m['Soyisim'] ?? null),
            updatedAt: $added,
            emailOptIn: is_bool($m['MailIzin'] ?? null) ? $m['MailIzin'] : null,
            // The WSDL field name contains a dotless i: "SmsIzın".
            smsOptIn: is_bool($m['SmsIzın'] ?? $m['SmsIzin'] ?? null) ? ($m['SmsIzın'] ?? $m['SmsIzin']) : null,
            attributes: ['subscribed_at' => $added?->toIso8601String()],
        );
    }

    /**
     * @param  array<string, mixed>  $k
     */
    public function category(array $k): CategoryRecord
    {
        $id = R::id($k['ID'] ?? null) ?? throw ProviderError::schema('Category without ID.', 'SelectKategori');

        return new CategoryRecord(
            externalId: $id,
            parentExternalId: R::id($k['PID'] ?? null),
            name: R::string($k['Tanim'] ?? null) ?? $id,
            code: R::string($k['Kod'] ?? null),
            url: $this->absoluteUrl(R::string($k['Url'] ?? null)),
            isActive: ($k['Aktif'] ?? true) === true,
            sort: (int) ($k['Sira'] ?? 0),
        );
    }

    /**
     * @param  array<string, mixed>  $p
     */
    public function product(array $p): ProductRecord
    {
        $id = R::id($p['ID'] ?? null) ?? throw ProviderError::schema('Product without ID.', 'SelectUrun');
        $images = R::scalars($p['Resimler'] ?? null, 'string');

        $variants = array_map(fn (array $v) => $this->variant($v), R::list($p['Varyasyonlar'] ?? null, 'Varyasyon'));

        return new ProductRecord(
            externalId: $id,
            name: R::string($p['UrunAdi'] ?? null) ?? $id,
            brand: R::string($p['Marka'] ?? null),
            brandExternalId: R::id($p['MarkaID'] ?? null),
            mainCategoryExternalId: R::id($p['AnaKategoriID'] ?? null),
            categoryExternalIds: array_values(array_map('strval', array_filter(R::scalars($p['Kategoriler'] ?? null, 'int'), fn ($c) => (int) $c > 0))),
            url: $this->absoluteUrl(R::string($p['UrunSayfaAdresi'] ?? null)),
            imageUrl: isset($images[0]) ? $this->absoluteUrl((string) $images[0]) : ($variants[0]->imageUrl ?? null),
            isActive: ($p['Aktif'] ?? false) === true,
            totalStock: R::float($p['ToplamStokAdedi'] ?? 0),
            createdAt: R::date($p['EklemeTarihi'] ?? null, $this->timezone),
            variants: $variants,
            attributes: array_filter([
                'main_category' => R::string($p['AnaKategori'] ?? null),
                'color_code' => R::string($p['RenkKodu'] ?? null),
                'published_at' => R::date($p['YayinTarihi'] ?? null, $this->timezone)?->toIso8601String(),
            ], fn ($v) => $v !== null),
        );
    }

    /**
     * @param  array<string, mixed>  $v
     */
    public function variant(array $v): VariantRecord
    {
        $id = R::id($v['ID'] ?? null) ?? throw ProviderError::schema('Variant without ID.', 'SelectUrun');

        $options = array_map(fn (array $o) => [
            'type' => (string) (R::string($o['Tur'] ?? null) ?? ''),
            'name' => (string) (R::string($o['Tanim'] ?? null) ?? ''),
            'value' => (string) (R::string($o['Deger'] ?? null) ?? ''),
        ], R::list($v['Ozellikler'] ?? null, 'VaryasyonOzellik'));

        $sale = R::nullableFloat($v['IndirimliFiyati'] ?? null);
        $images = R::scalars($v['Resimler'] ?? null, 'string');

        return new VariantRecord(
            externalId: $id,
            sku: R::string($v['StokKodu'] ?? null),
            barcode: R::string($v['Barkod'] ?? null),
            price: R::nullableFloat($v['SatisFiyati'] ?? null),
            // 0 means "no discounted price" in Ticimax.
            salePrice: $sale !== null && $sale > 0 ? $sale : null,
            currency: $this->currency(R::string($v['ParaBirimi'] ?? null)),
            stock: R::float($v['StokAdedi'] ?? 0),
            isActive: ($v['Aktif'] ?? false) === true,
            color: $this->option($options, ['renk', 'color']),
            size: $this->option($options, ['beden', 'size', 'numara']),
            imageUrl: isset($images[0]) ? $this->absoluteUrl((string) $images[0]) : null,
            options: $options,
            stockUpdatedAt: R::date($v['StokGuncellemeTarihi'] ?? null, $this->timezone),
        );
    }

    /**
     * @param  array<string, mixed>  $o
     */
    public function order(array $o): OrderRecord
    {
        $id = R::id($o['ID'] ?? null) ?? throw ProviderError::schema('Order without ID.', 'SelectSiparis');
        $placedAt = R::date($o['SiparisTarihi'] ?? null, $this->timezone) ?? throw ProviderError::schema('Order without date.', 'SelectSiparis');
        $source = R::string($o['SiparisKaynagi'] ?? null);
        $isMarketplace = ($o['IsMarketplace'] ?? false) === true || in_array(strtoupper((string) $source), $this->marketplaceSources, true);
        $statusLabel = R::string($o['SiparisDurumu'] ?? null);

        return new OrderRecord(
            externalId: $id,
            number: R::string($o['SiparisNo'] ?? null),
            memberExternalId: R::id($o['UyeID'] ?? null),
            email: R::string($o['Mail'] ?? null),
            channel: $this->channel($source, $isMarketplace),
            source: $source,
            isMarketplace: $isMarketplace,
            statusCode: is_numeric($o['Durum'] ?? null) ? (int) $o['Durum'] : null,
            statusLabel: $statusLabel,
            countsAsRevenue: ! $this->isCancelled($statusLabel),
            placedAt: $placedAt,
            updatedAt: R::date($o['DuzenlemeTarihi'] ?? null, $this->timezone),
            currency: $this->currency(R::string($o['ParaBirimi'] ?? null)),
            // ToplamTutar is before tax, SiparisToplamTutari is what the
            // customer paid (verified on live orders: 3181.82 + 10% = 3500).
            netTotal: R::float($o['ToplamTutar'] ?? 0),
            grandTotal: R::float($o['SiparisToplamTutari'] ?? 0),
            discountTotal: R::float($o['IndirimTutari'] ?? 0),
            shippingTotal: R::float($o['KargoTutari'] ?? 0),
            couponCode: R::string($o['HediyeCeki'] ?? null),
            couponAmount: R::float($o['HediyeCekiTutari'] ?? 0),
            items: array_map(fn (array $i) => $this->orderItem($i), R::list($o['Urunler'] ?? null, 'WebSiparisUrun')),
            attributes: array_filter([
                'campaigns' => array_map(
                    fn (array $c) => ['id' => R::id($c['KampanyaID'] ?? null), 'name' => R::string($c['KampanyaTanim'] ?? null)],
                    R::list($o['Kampanyalar'] ?? null, 'WebSiparisKampanya'),
                ),
                'member_type_id' => R::id($o['UyeTuruId'] ?? null),
            ], fn ($v) => $v !== null && $v !== []),
        );
    }

    /**
     * @param  array<string, mixed>  $i
     */
    public function orderItem(array $i): OrderItemRecord
    {
        $id = R::id($i['ID'] ?? null) ?? throw ProviderError::schema('Order item without ID.', 'SelectSiparis');
        $net = R::float($i['Tutar'] ?? 0);
        $tax = R::float($i['KdvTutari'] ?? 0);

        return new OrderItemRecord(
            externalId: $id,
            productExternalId: R::id($i['UrunKartiID'] ?? null),
            variantExternalId: R::id($i['UrunID'] ?? null),
            sku: R::string($i['StokKodu'] ?? null),
            barcode: R::string($i['Barkod'] ?? null),
            name: R::string($i['UrunAdi'] ?? null),
            quantity: R::float($i['Adet'] ?? 0),
            unitListPrice: R::nullableFloat($i['SatisAniSatisFiyat'] ?? null),
            unitPrice: R::nullableFloat($i['SatisAniIndirimliFiyat'] ?? null),
            taxAmount: $tax,
            discountAmount: R::float($i['ToplamIndirim'] ?? 0),
            rowTotal: round($net + $tax, 4),
            statusCode: is_numeric($i['Durum'] ?? null) ? (int) $i['Durum'] : null,
            statusLabel: R::string($i['DurumAd'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $c
     */
    public function cart(array $c): CartRecord
    {
        $id = R::string($c['GuidSepetID'] ?? null) ?? throw ProviderError::schema('Cart without GuidSepetID.', 'SelectSepet');
        $createdAt = R::date($c['SepetTarihi'] ?? null, $this->timezone) ?? throw ProviderError::schema('Cart without date.', 'SelectSepet');
        $items = R::list($c['Urunler'] ?? null, 'WebSepetUrun');

        return new CartRecord(
            externalId: $id,
            memberExternalId: R::id($c['UyeID'] ?? null),
            email: R::string($c['UyeMail'] ?? null),
            createdAt: $createdAt,
            currency: $this->currency(R::string($items[0]['ParaBirimi'] ?? null)),
            items: array_map(fn (array $i) => new CartItemRecord(
                externalId: R::string($i['GuidSepetUrunID'] ?? null) ?? $id.':'.((string) ($i['UrunID'] ?? '0')),
                productExternalId: R::id($i['UrunKartiID'] ?? null),
                variantExternalId: R::id($i['UrunID'] ?? null),
                sku: R::string($i['StokKodu'] ?? null),
                name: R::string($i['UrunAdi'] ?? null),
                quantity: R::float($i['Adet'] ?? 0),
                unitPrice: R::nullableFloat($i['UrunSepetFiyati'] ?? null),
                unitPriceWithTax: R::nullableFloat($i['UrunSepetFiyatiKDV'] ?? null),
                imageUrl: $this->absoluteUrl(R::string($i['SpotResim'] ?? null)),
            ), $items),
        );
    }

    private function channel(?string $source, bool $isMarketplace): string
    {
        return match (true) {
            $isMarketplace => OrderRecord::CHANNEL_MARKETPLACE,
            $source === 'TicimaxWeb' => OrderRecord::CHANNEL_WEB,
            in_array($source, ['NativeAndroid', 'NativeIOS', 'MobilApp', 'Mobil'], true) => OrderRecord::CHANNEL_MOBILE_APP,
            default => OrderRecord::CHANNEL_OTHER,
        };
    }

    private function isCancelled(?string $label): bool
    {
        // Turkish dotted capital I lowercases to "i" + combining dot in
        // mb_strtolower, so normalize it first ("İptal", "İade").
        $label = mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], (string) $label));

        foreach (['iptal', 'iade', 'silin', 'cüzdana'] as $word) {
            if (str_contains($label, $word)) {
                return true;
            }
        }

        return false;
    }

    private function currency(?string $code): ?string
    {
        return match ($code === null ? null : strtoupper($code)) {
            null => null,
            'TL', 'YTL' => 'TRY',
            default => strlen($code) === 3 ? strtoupper($code) : null,
        };
    }

    /**
     * @param  list<array{type: string, name: string, value: string}>  $options
     * @param  list<string>  $types
     */
    private function option(array $options, array $types): ?string
    {
        foreach ($options as $option) {
            if (in_array(mb_strtolower($option['type']), $types, true) && $option['value'] !== '') {
                return $option['value'];
            }
        }

        return null;
    }

    private function absoluteUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        return preg_match('#^https?://#i', $url) === 1 ? $url : rtrim($this->storefrontUrl, '/').'/'.ltrim($url, '/');
    }
}
