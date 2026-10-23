<?php

namespace App\Connectors\Ticimax;

use App\Commerce\Contracts\CommerceSource;
use App\Commerce\Contracts\LooksUpRecords;
use App\Commerce\Data\ConnectionCheck;
use App\Commerce\Data\CustomerRecord;
use App\Commerce\Data\OrderRecord;
use App\Commerce\Data\ProductRecord;
use App\Commerce\Data\RecordPage;
use App\Commerce\Data\SyncWindow;
use App\Connectors\Ticimax\TicimaxResponse as R;
use App\Integrations\Errors\ProviderError;
use Carbon\CarbonImmutable;

/**
 * Ticimax implementation of CommerceSource. Read-only.
 *
 * Lessons from the live API that shape the queries:
 * - Ticimax treats an omitted filter field as 0/false, not "no filter".
 *   Member filters must send Aktif, Cinsiyet, MailIzin and SmsIzin as -1,
 *   product filters Aktif, Firsat, Indirimli and Vitrin as -1, otherwise
 *   records silently disappear from results.
 * - An invalid authorization code returns an empty member list instead of
 *   a fault; SelectSiparisDurumlari reports it (IsError, "Yetki kodu
 *   hatalı."), so authentication is checked with that call.
 * - Members never edited carry DuzenlemeTarihi 2000-01-01, so a customer
 *   delta reads three windows: registration, modification and permission
 *   update date.
 * - SelectSepet pages by page number (sayfaSayisi) with 100 carts per page
 *   and identifies carts by GuidSepetID (ID is 0).
 * - SelectSiparis pages by zero-based page number and answers an empty
 *   page with a fault ("... siparişler bulunamadı."), which the client
 *   turns into an empty result.
 */
final class TicimaxCommerceConnector implements CommerceSource, LooksUpRecords
{
    private const MEMBER_FILTER = [
        'Aktif' => -1, 'AlisverisYapti' => -1, 'Cinsiyet' => -1, 'IlID' => -1, 'IlceID' => -1,
        'MailIzin' => -1, 'SmsIzin' => -1, 'Onay' => -1, 'UyeID' => -1,
        'BakiyeGetir' => false, 'IzinGuncellemeTarihiGetir' => true,
    ];

    private const PRODUCT_FILTER = [
        'Aktif' => -1, 'Firsat' => -1, 'Indirimli' => -1, 'Vitrin' => -1,
        'KategoriID' => 0, 'MarkaID' => 0, 'UrunKartiID' => 0, 'TedarikciID' => 0,
    ];

    private const ORDER_FILTER = [
        'EntegrasyonAktarildi' => -1, 'IptalEdilmisUrunler' => true, 'OdemeDurumu' => -1, 'OdemeTipi' => -1,
        'SiparisDurumu' => -1, 'SiparisID' => -1, 'TedarikciID' => -1, 'UyeID' => -1, 'KargoFirmaID' => -1,
        'PaketlemeDurumu' => -1, 'OdemeTamamlandi' => -1, 'TeslimatMagazaID' => -1,
        'UrunGetir' => true, 'KampanyaGetir' => true, 'OdemeGetir' => false,
    ];

    /** Customer delta phases: [filter from, filter to] field names. */
    private const MEMBER_DELTA_PHASES = [
        'registered' => ['UyelikTarihi1', 'UyelikTarihi2'],
        'modified' => ['DuzenlemeTarihi1', 'DuzenlemeTarihi2'],
        'permission' => ['IzinGuncellemeTarihiBas', 'IzinGuncellemeTarihiSon'],
    ];

    private const PRODUCT_DELTA_PHASES = [
        'modified' => ['DuzenlemeTarihiBaslangic', 'DuzenlemeTarihiBitis'],
        'stock' => ['StokGuncellemeTarihiBaslangic', 'StokGuncellemeTarihiBitis'],
    ];

    public function __construct(
        private readonly TicimaxClient $client,
        private readonly TicimaxMapper $mapper,
        private readonly int $memberPageSize = 500,
        private readonly int $productPageSize = 100,
        private readonly int $orderPageSize = 100,
        private readonly int $maxCartPages = 500,
    ) {}

    public function client(): TicimaxClient
    {
        return $this->client;
    }

    public function testConnection(): ConnectionCheck
    {
        $checks = [];

        try {
            $this->client->call('SiparisServis', 'SelectSiparisDurumlari');
            $checks['authorization'] = true;

            $members = $this->client->call('UyeServis', 'SelectUyeler', [
                'filtre' => self::MEMBER_FILTER,
                'sayfalama' => ['KayitSayisi' => 1, 'SayfaNo' => 1, 'SiralamaDegeri' => 'ID', 'SiralamaYonu' => 'Asc'],
            ]);
            $checks['members'] = R::list($members['SelectUyelerResult'] ?? null, 'Uye') !== [];

            $orders = $this->client->call('SiparisServis', 'SelectSiparis', [
                'f' => self::ORDER_FILTER + ['UrunGetir' => false],
                's' => ['BaslangicIndex' => 0, 'KayitSayisi' => 1, 'SiralamaDegeri' => 'ID', 'SiralamaYonu' => 'Desc'],
            ]);
            $checks['orders'] = R::list($orders['SelectSiparisResult'] ?? null, 'WebSiparis') !== [];

            return new ConnectionCheck(! in_array(false, $checks, true), $checks, null, in_array(false, $checks, true) ? 'Connected, but some sample reads returned no data.' : null);
        } catch (ProviderError $e) {
            return new ConnectionCheck(false, $checks, $e->category, $e->getMessage());
        }
    }

    public function customers(SyncWindow $window, ?string $pageToken = null): RecordPage
    {
        [$phase, $page] = $this->token($pageToken, $window->full ? 'all' : 'registered');

        if ($phase === 'subscribers') {
            return $this->subscribers($window);
        }

        $filter = self::MEMBER_FILTER;
        if ($phase !== 'all') {
            [$fromField, $toField] = self::MEMBER_DELTA_PHASES[$phase] ?? throw new \InvalidArgumentException('Unknown customer phase.');
            $filter[$fromField] = R::filterDate($window->from ?? CarbonImmutable::create(2000), $this->tz());
            $filter[$toField] = R::filterDate($window->to, $this->tz());
        }

        $result = $this->client->call('UyeServis', 'SelectUyeler', [
            'filtre' => $filter,
            'sayfalama' => ['KayitSayisi' => $this->memberPageSize, 'SayfaNo' => $page, 'SiralamaDegeri' => 'ID', 'SiralamaYonu' => 'Asc'],
        ]);
        $rows = R::list($result['SelectUyelerResult'] ?? null, 'Uye');

        $next = count($rows) >= $this->memberPageSize
            ? "{$phase}:".($page + 1)
            : $this->nextPhase($phase, $window->full ? ['all', 'subscribers'] : ['registered', 'modified', 'permission', 'subscribers']);

        return new RecordPage(array_map(fn (array $u) => $this->mapper->member($u), $rows), $next);
    }

    public function categories(?string $pageToken = null): RecordPage
    {
        // parentID = -1 returns the whole tree flat, each node with its PID.
        $result = $this->client->call('UrunServis', 'SelectKategori', ['kategoriID' => 0, 'dil' => '', 'parentID' => -1]);

        return new RecordPage(array_map(fn (array $k) => $this->mapper->category($k), R::list($result['SelectKategoriResult'] ?? null, 'Kategori')), null);
    }

    public function products(SyncWindow $window, ?string $pageToken = null): RecordPage
    {
        [$phase, $page] = $this->token($pageToken, $window->full ? 'all' : 'modified');

        $filter = self::PRODUCT_FILTER;
        if ($phase !== 'all') {
            [$fromField, $toField] = self::PRODUCT_DELTA_PHASES[$phase] ?? throw new \InvalidArgumentException('Unknown product phase.');
            $filter[$fromField] = R::filterDate($window->from ?? CarbonImmutable::create(2000), $this->tz());
            $filter[$toField] = R::filterDate($window->to, $this->tz());
        }

        // BaslangicIndex is a record offset; LastId is ignored by Ticimax.
        $result = $this->client->call('UrunServis', 'SelectUrun', [
            'f' => $filter,
            's' => ['BaslangicIndex' => ($page - 1) * $this->productPageSize, 'KayitSayisi' => $this->productPageSize, 'KayitSayisinaGoreGetir' => true, 'SiralamaDegeri' => 'ID', 'SiralamaYonu' => 'ASC'],
        ]);
        $rows = R::list($result['SelectUrunResult'] ?? null, 'UrunKarti');

        $next = count($rows) >= $this->productPageSize
            ? "{$phase}:".($page + 1)
            : $this->nextPhase($phase, $window->full ? ['all'] : ['modified', 'stock']);

        return new RecordPage(array_map(fn (array $p) => $this->mapper->product($p), $rows), $next);
    }

    public function orders(SyncWindow $window, ?string $pageToken = null): RecordPage
    {
        [, $page] = $this->token($pageToken, 'orders');

        // Full: by order date (backfill window). Delta: by last modification,
        // which also covers status changes of older orders.
        $filter = self::ORDER_FILTER + ($window->full
            ? ['SiparisTarihiBas' => R::filterDate($window->from ?? CarbonImmutable::create(2010), $this->tz()), 'SiparisTarihiSon' => R::filterDate($window->to, $this->tz())]
            : ['DuzenlemeTarihiBas' => R::filterDate($window->from ?? $window->to->subDay(), $this->tz()), 'DuzenlemeTarihiSon' => R::filterDate($window->to, $this->tz())]);

        // Unlike SelectUrun, SelectSiparis treats BaslangicIndex as a
        // zero-based page number (verified live: index 1 with 50 per page
        // returns records 51-100).
        $result = $this->client->call('SiparisServis', 'SelectSiparis', [
            'f' => $filter,
            's' => ['BaslangicIndex' => $page - 1, 'KayitSayisi' => $this->orderPageSize, 'SiralamaDegeri' => 'ID', 'SiralamaYonu' => 'Asc'],
        ]);
        $rows = R::list($result['SelectSiparisResult'] ?? null, 'WebSiparis');

        return new RecordPage(
            array_map(fn (array $o) => $this->mapper->order($o), $rows),
            count($rows) >= $this->orderPageSize ? 'orders:'.($page + 1) : null,
        );
    }

    public function carts(SyncWindow $window, ?string $pageToken = null): RecordPage
    {
        [, $page] = $this->token($pageToken, 'carts');

        if ($page > $this->maxCartPages) {
            throw new ProviderError(ProviderError::PERMANENT, 'Cart window exceeds the page safety limit; use a shorter window.', 'SelectSepet');
        }

        $result = $this->client->call('SiparisServis', 'SelectSepet', [
            'sepetId' => 0,
            'uyeId' => 0,
            'BaslangicTarihi' => R::filterDate($window->from ?? $window->to->subDays(2), $this->tz()),
            'BitisTarihi' => R::filterDate($window->to, $this->tz()),
            'sayfaSayisi' => $page,
            'guidSepetId' => '',
        ]);
        $root = $result['SelectSepetResult'] ?? [];
        $rows = R::list($root['Sepetler'] ?? null, 'WebSepet');
        $hasNext = $rows !== [] && ($root['Next'] ?? false) === true;

        return new RecordPage(array_map(fn (array $c) => $this->mapper->cart($c), $rows), $hasNext ? 'carts:'.($page + 1) : null);
    }

    public function member(string $externalId): ?CustomerRecord
    {
        $result = $this->client->call('UyeServis', 'SelectUyeler', [
            'filtre' => ['UyeID' => (int) $externalId] + self::MEMBER_FILTER,
            'sayfalama' => ['KayitSayisi' => 1, 'SayfaNo' => 1, 'SiralamaDegeri' => 'ID', 'SiralamaYonu' => 'Asc'],
        ]);
        $rows = R::list($result['SelectUyelerResult'] ?? null, 'Uye');

        return $rows === [] ? null : $this->mapper->member($rows[0]);
    }

    public function product(string $externalId): ?ProductRecord
    {
        $result = $this->client->call('UrunServis', 'SelectUrun', [
            'f' => ['UrunKartiID' => (int) $externalId] + self::PRODUCT_FILTER,
            's' => ['BaslangicIndex' => 0, 'KayitSayisi' => 1, 'KayitSayisinaGoreGetir' => true, 'SiralamaDegeri' => 'ID', 'SiralamaYonu' => 'ASC'],
        ]);
        $rows = R::list($result['SelectUrunResult'] ?? null, 'UrunKarti');

        return $rows === [] ? null : $this->mapper->product($rows[0]);
    }

    public function order(string $externalId): ?OrderRecord
    {
        $result = $this->client->call('SiparisServis', 'SelectSiparis', [
            'f' => ['SiparisID' => (int) $externalId] + self::ORDER_FILTER,
            's' => ['BaslangicIndex' => 0, 'KayitSayisi' => 1, 'SiralamaDegeri' => 'ID', 'SiralamaYonu' => 'Asc'],
        ]);
        $rows = R::list($result['SelectSiparisResult'] ?? null, 'WebSiparis');

        return $rows === [] ? null : $this->mapper->order($rows[0]);
    }

    public function productCount(): ?int
    {
        $result = $this->client->call('UrunServis', 'SelectUrunCount', ['f' => self::PRODUCT_FILTER]);

        return is_numeric($result['SelectUrunCountResult'] ?? null) ? (int) $result['SelectUrunCountResult'] : null;
    }

    /**
     * Newsletter subscribers come from one SelectMailList call per window.
     *
     * @return RecordPage<CustomerRecord>
     */
    private function subscribers(SyncWindow $window): RecordPage
    {
        $result = $this->client->call('CustomServis', 'SelectMailList', [
            'BaslangicTarihi' => R::filterDate($window->full || $window->from === null ? CarbonImmutable::create(2000) : $window->from, $this->tz()),
            'BitisTarihi' => R::filterDate($window->to, $this->tz()),
        ]);

        return new RecordPage(array_map(fn (array $m) => $this->mapper->subscriber($m), R::list($result['SelectMailListResult'] ?? null, 'MailList')), null);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function token(?string $token, string $defaultPhase): array
    {
        if ($token === null) {
            return [$defaultPhase, 1];
        }

        [$phase, $page] = array_pad(explode(':', $token, 2), 2, '1');

        return [$phase, max(1, (int) $page)];
    }

    /**
     * @param  list<string>  $phases
     */
    private function nextPhase(string $current, array $phases): ?string
    {
        $index = array_search($current, $phases, true);

        return $index !== false && isset($phases[$index + 1]) ? $phases[$index + 1].':1' : null;
    }

    private function tz(): string
    {
        return $this->client->credentials()->timezone;
    }
}
