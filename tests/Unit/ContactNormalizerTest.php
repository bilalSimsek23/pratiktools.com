<?php

namespace Tests\Unit;

use App\Commerce\Identity\ContactNormalizer;
use App\Connectors\Ticimax\TicimaxResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContactNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function phones(): array
    {
        return [
            'local with zero' => ['0532 123 45 67', '+905321234567'],
            'ten digits' => ['5321234567', '+905321234567'],
            'with country code' => ['905321234567', '+905321234567'],
            'plus form' => ['+90 (532) 123-45-67', '+905321234567'],
            'double zero' => ['00905321234567', '+905321234567'],
            'foreign with plus' => ['+49 151 2345 6789', '+4915123456789'],
            'too short' => ['12345', null],
            'empty' => ['  ', null],
        ];
    }

    #[DataProvider('phones')]
    public function test_phone_normalization(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, (new ContactNormalizer)->phone($input));
    }

    public function test_email_and_marketplace_detection(): void
    {
        $n = new ContactNormalizer(['trendyolmail.com', 'hepsifatura.com']);

        $this->assertSame('ayse@example.com', $n->email('  Ayse@Example.COM '));
        $this->assertNull($n->email('not-an-email'));
        $this->assertTrue($n->isMarketplaceEmail('x1@trendyolmail.com'));
        $this->assertTrue($n->isMarketplaceEmail('x1@sub.hepsifatura.com'));
        $this->assertFalse($n->isMarketplaceEmail('x1@nottrendyolmail.com'));
    }

    public function test_ticimax_dates_use_store_timezone_and_drop_sentinels(): void
    {
        $this->assertSame('2026-10-20 11:30:00', TicimaxResponse::date('2026-10-20T14:30:00', 'Europe/Istanbul')?->toDateTimeString());
        $this->assertSame('2026-10-20 14:30:00', TicimaxResponse::date('2026-10-20T14:30:00+00:00', 'Europe/Istanbul')?->toDateTimeString());
        $this->assertNull(TicimaxResponse::date('2000-01-01T00:00:00', 'Europe/Istanbul'));
        $this->assertNull(TicimaxResponse::date('0001-01-01T00:00:00', 'Europe/Istanbul'));
        $this->assertSame([], TicimaxResponse::list(null, 'Uye'));
        $this->assertSame([['ID' => 1]], TicimaxResponse::list(['Uye' => ['ID' => 1]], 'Uye'));
    }
}
