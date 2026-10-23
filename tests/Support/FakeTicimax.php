<?php

namespace Tests\Support;

use App\Connectors\Ticimax\Soap\SoapTransport;
use Closure;
use Throwable;

/**
 * In-memory Ticimax that answers the read operations the connector uses,
 * in the shape PhpSoapTransport produces: a list with exactly one element
 * comes back as that element (not wrapped in a list), as SOAP decoding
 * does. Records are raw Ticimax arrays.
 */
final class FakeTicimax implements SoapTransport
{
    /** @var list<array<string, mixed>> */
    public array $members = [];

    /** @var list<array<string, mixed>> */
    public array $subscribers = [];

    /** @var list<array<string, mixed>> */
    public array $categories = [];

    /** @var list<array<string, mixed>> */
    public array $products = [];

    /** @var list<array<string, mixed>> */
    public array $orders = [];

    /** @var list<array<string, mixed>> */
    public array $carts = [];

    /** @var list<array{operation: string, parameters: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<array{operation: string, error: Throwable, times: int, after: int}> */
    private array $failures = [];

    /** @var (Closure(string, int): void)|null */
    public ?Closure $beforeCall = null;

    public int $cartPageSize = 100;

    public function __construct(public string $validKey = 'valid-member-code') {}

    /**
     * Throw $error on the next $times calls of $operation, after letting
     * $after calls of it succeed.
     */
    public function failOn(string $operation, Throwable $error, int $times = 1, int $after = 0): void
    {
        $this->failures[] = ['operation' => $operation, 'error' => $error, 'times' => $times, 'after' => $after];
    }

    public function callsOf(string $operation): int
    {
        return count(array_filter($this->calls, fn ($c) => $c['operation'] === $operation));
    }

    public function call(string $service, string $operation, array $parameters): array
    {
        $key = $parameters['UyeKodu'] ?? null;
        unset($parameters['UyeKodu']);
        $this->calls[] = ['operation' => $operation, 'parameters' => $parameters];
        $n = $this->callsOf($operation);

        if ($this->beforeCall !== null) {
            ($this->beforeCall)($operation, $n);
        }

        foreach ($this->failures as $i => $f) {
            if ($f['operation'] === $operation && $n > $f['after'] && $f['times'] > 0) {
                $this->failures[$i]['times']--;

                throw $f['error'];
            }
        }

        $authorized = $key === $this->validKey;

        return match ($operation) {
            'SelectSiparisDurumlari' => ['SelectSiparisDurumlariResult' => $authorized
                ? ['IsError' => false, 'Durumlar' => []]
                : ['IsError' => true, 'ErrorMessage' => 'Yetki kodu hatalı.']],
            'SelectUyeler' => ['SelectUyelerResult' => $authorized ? $this->wrap($this->page(
                $this->filterById($this->members, $parameters['filtre']['UyeID'] ?? -1),
                ($parameters['sayfalama']['SayfaNo'] - 1) * $parameters['sayfalama']['KayitSayisi'],
                $parameters['sayfalama']['KayitSayisi'],
            ), 'Uye') : []],
            'SelectMailList' => ['SelectMailListResult' => $authorized ? $this->wrap($this->subscribers, 'MailList') : []],
            'SelectKategori' => ['SelectKategoriResult' => $authorized ? $this->wrap($this->categories, 'Kategori') : []],
            'SelectUrunCount' => ['SelectUrunCountResult' => $authorized ? count($this->products) : 0],
            'SelectUrun' => ['SelectUrunResult' => $authorized ? $this->wrap($this->page(
                $this->filterById($this->products, $parameters['f']['UrunKartiID'] ?? 0),
                $parameters['s']['BaslangicIndex'],
                $parameters['s']['KayitSayisi'],
            ), 'UrunKarti') : []],
            'SelectSiparis' => ['SelectSiparisResult' => $authorized ? $this->wrap($this->page(
                $this->filterById($this->orders, $parameters['f']['SiparisID'] ?? -1),
                $parameters['s']['BaslangicIndex'],
                $parameters['s']['KayitSayisi'],
            ), 'WebSiparis') : []],
            'SelectSepet' => ['SelectSepetResult' => $authorized ? [
                'Sepetler' => $this->wrap($this->page($this->carts, ($parameters['sayfaSayisi'] - 1) * $this->cartPageSize, $this->cartPageSize), 'WebSepet'),
                'Next' => count($this->carts) > $parameters['sayfaSayisi'] * $this->cartPageSize,
            ] : ['Sepetler' => [], 'Next' => false]],
            default => throw new \LogicException("FakeTicimax does not implement {$operation}."),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function filterById(array $rows, mixed $id): array
    {
        return (int) $id > 0 ? array_values(array_filter($rows, fn ($r) => (int) $r['ID'] === (int) $id)) : $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function page(array $rows, int $offset, int $size): array
    {
        return array_slice($rows, $offset, $size);
    }

    /**
     * @param  list<mixed>  $rows
     * @return array<string, mixed>
     */
    public static function wrap(array $rows, string $child): array
    {
        return match (count($rows)) {
            0 => [],
            1 => [$child => $rows[0]],
            default => [$child => $rows],
        };
    }

    // Raw record builders (Ticimax field names).

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function member(int $id, ?string $email, array $overrides = []): array
    {
        return $overrides + [
            'ID' => $id, 'Mail' => $email, 'CepTelefonu' => '0532 111 22 '.str_pad((string) ($id % 100), 2, '0', STR_PAD_LEFT),
            'Isim' => "Ad{$id}", 'Soyisim' => "Soyad{$id}", 'DogumTarihi' => '1990-05-01T00:00:00', 'CinsiyetID' => 1,
            'Il' => 'İstanbul', 'Ilce' => 'Kadıköy', 'UyelikTarihi' => '2025-01-10T12:00:00', 'Aktif' => true,
            'DuzenlemeTarihi' => '2000-01-01T00:00:00', 'MailIzin' => true, 'SmsIzin' => false,
            'IzinGuncellemeTarihi' => '2025-01-10T12:00:00', 'UyeTuru' => 'Standart',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function subscriber(int $id, string $email, bool $mail = true): array
    {
        return ['ID' => $id, 'Mail' => $email, 'Isim' => null, 'Soyisim' => null, 'EklemeTarihi' => '2025-03-01T09:00:00', 'MailIzin' => $mail, 'SmsIzın' => false];
    }

    /**
     * @return array<string, mixed>
     */
    public static function category(int $id, int $parent, string $name): array
    {
        return ['ID' => $id, 'PID' => $parent, 'Tanim' => $name, 'Kod' => "K{$id}", 'Url' => "/{$name}", 'Aktif' => true, 'Sira' => $id];
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     * @param  list<int>  $categories
     * @return array<string, mixed>
     */
    public static function product(int $id, array $variants, array $categories = [], array $overrides = []): array
    {
        return $overrides + [
            'ID' => $id, 'UrunAdi' => "Ürün {$id}", 'Marka' => 'IGS', 'MarkaID' => 1, 'AnaKategoriID' => $categories[0] ?? 0,
            'Kategoriler' => ['int' => count($categories) === 1 ? $categories[0] : $categories],
            'UrunSayfaAdresi' => "/urun-{$id}", 'Aktif' => true,
            'ToplamStokAdedi' => array_sum(array_map(fn ($v) => $v['StokAdedi'], $variants)),
            'EklemeTarihi' => '2024-06-01T10:00:00', 'Varyasyonlar' => self::wrap($variants, 'Varyasyon'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function variant(int $id, string $color, string $size, float $stock = 5, float $price = 1000): array
    {
        return [
            'ID' => $id, 'StokKodu' => "SKU{$id}", 'Barkod' => "869{$id}", 'SatisFiyati' => $price, 'IndirimliFiyati' => 0,
            'ParaBirimi' => 'TL', 'StokAdedi' => $stock, 'Aktif' => true,
            'Ozellikler' => ['VaryasyonOzellik' => [
                ['Tur' => 'Renk', 'Tanim' => 'Renk', 'Deger' => $color],
                ['Tur' => 'Beden', 'Tanim' => 'Beden', 'Deger' => $size],
            ]],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function order(int $id, int $memberId, ?string $email, array $items, array $overrides = []): array
    {
        $net = array_sum(array_map(fn ($i) => $i['Tutar'], $items));
        $tax = array_sum(array_map(fn ($i) => $i['KdvTutari'], $items));

        return $overrides + [
            'ID' => $id, 'SiparisNo' => "SP{$id}", 'UyeID' => $memberId, 'Mail' => $email, 'SiparisKaynagi' => 'TicimaxWeb',
            'IsMarketplace' => false, 'Durum' => 1, 'SiparisDurumu' => 'Onaylandı',
            'SiparisTarihi' => '2026-10-20T14:30:00', 'DuzenlemeTarihi' => '2026-10-20T14:30:00', 'ParaBirimi' => 'TL',
            'ToplamTutar' => $net, 'SiparisToplamTutari' => $net + $tax, 'IndirimTutari' => 0, 'KargoTutari' => 0,
            'HediyeCeki' => null, 'HediyeCekiTutari' => 0, 'Urunler' => self::wrap($items, 'WebSiparisUrun'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function orderItem(int $id, int $productId, int $variantId, float $qty = 1, float $net = 1000, float $tax = 100): array
    {
        return [
            'ID' => $id, 'UrunKartiID' => $productId, 'UrunID' => $variantId, 'StokKodu' => "SKU{$variantId}", 'Barkod' => null,
            'UrunAdi' => "Ürün {$productId}", 'Adet' => $qty, 'SatisAniSatisFiyat' => ($net + $tax) / $qty, 'SatisAniIndirimliFiyat' => ($net + $tax) / $qty,
            'Tutar' => $net, 'KdvTutari' => $tax, 'ToplamIndirim' => 0, 'Durum' => 1, 'DurumAd' => 'Onaylandı',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public static function cart(string $guid, int $memberId, ?string $email, array $items, string $date = '2026-10-23T10:00:00'): array
    {
        return ['ID' => 0, 'GuidSepetID' => $guid, 'UyeID' => $memberId, 'UyeMail' => $email, 'SepetTarihi' => $date, 'Urunler' => self::wrap($items, 'WebSepetUrun')];
    }

    /**
     * @return array<string, mixed>
     */
    public static function cartItem(string $guid, int $productId, int $variantId, float $qty = 1, float $price = 1100): array
    {
        return [
            'GuidSepetUrunID' => $guid, 'UrunKartiID' => $productId, 'UrunID' => $variantId, 'StokKodu' => "SKU{$variantId}",
            'UrunAdi' => "Ürün {$productId}", 'Adet' => $qty, 'UrunSepetFiyati' => round($price / 1.1, 2), 'UrunSepetFiyatiKDV' => $price,
            'ParaBirimi' => 'TL', 'SpotResim' => "/img/{$variantId}.jpg",
        ];
    }
}
