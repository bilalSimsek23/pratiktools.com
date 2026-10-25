<?php

namespace App\Panel;

/**
 * Product-language (Turkish) text for approval and dispatch refusal codes.
 * Unknown codes fall back to the service message.
 */
final class PanelMessages
{
    private const MESSAGES = [
        'ALREADY_DECIDED' => 'Bu onay adımı zaten karara bağlandı.',
        'EXPIRED' => 'Onay isteğinin süresi doldu; yeniden onay isteyin.',
        'INVALIDATED' => 'Kampanya onay istendikten sonra değişti; bu onay artık geçersiz.',
        'NOT_FROZEN' => 'Onay istemek için kampanyayı önce hazır olarak işaretleyin.',
        'REASON_REQUIRED' => 'Reddetmek için bir gerekçe yazın.',
        'SAME_PERSON' => 'İkinci onay farklı bir kişiden gelmelidir.',
        'SELF_APPROVAL' => 'Kendi oluşturduğunuz veya onaya gönderdiğiniz kampanyayı onaylayamazsınız.',
        'APPROVALS_MISSING' => 'Gerekli onaylar tamamlanmadı.',
        'CAMPAIGN_CHANGED' => 'Kampanya onaydan sonra değişti; yeniden onay gerekir.',
        'CAMPAIGN_NOT_FROZEN' => 'Yalnızca hazır (dondurulmuş) kampanya için gönderim talebi oluşturulabilir.',
        'INVALID_STATE' => 'Bu talep mevcut durumunda bu işleme izin vermiyor.',
        'LIVE_DISABLED' => 'Canlı gönderim harici gereksinimler tamamlanana kadar kapalıdır.',
        'PROVIDER_NOT_CONNECTED' => 'Gönderim sağlayıcısı bağlı değil veya gerekli yetkiye sahip değil.',
        'RECIPIENT_COUNT' => 'İzinli test listesinden en az bir, en fazla izin verilen sayıda alıcı seçin.',
        'RECIPIENT_NOT_ALLOWLISTED' => 'Seçilen alıcılardan biri izinli test listesinde değil.',
        'SENDER_NOT_VERIFIED' => 'Gönderici kimliği doğrulanmamış.',
        'SENDING_MODE_NOT_TEST_ONLY' => 'Gönderim modu TEST_ONLY değil; kuyruğa alınamaz. Modu yalnızca operatör değiştirebilir.',
    ];

    public static function for(string $code, string $fallback): string
    {
        return self::MESSAGES[$code] ?? $fallback;
    }
}
