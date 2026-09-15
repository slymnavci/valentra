<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * Aday haberi Gemini'ye verip iki soruyu birden yanıtlatır:
 * vergiyle ilgili mi, ve ilgiliyse haber metni nasıl yazılmalı.
 *
 * Telif: modele kaynak metin YALNIZCA anlaması için verilir. Çıktının
 * özgün olması, kaynaktan cümle kopyalanmaması ve kaynağa atıf verilmesi
 * sistem yönergesinde açıkça şart koşulur.
 */
final class Yazar
{
    private const MODEL = 'gemini-3.8-flash';
    private const API   = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private const YONERGE = <<<'METIN'
        Sen Valentra adlı vergi haberleri sitesinin editör yardımcısısın.
        Valentra bir yeminli mali müşavirlik kuruluşudur; okuyucuları mali
        müşavirler, muhasebeciler, şirketlerin mali işler birimleri ve
        mükelleflerdir.

        Sana bir haber kaynağından başlık, özet ve sayfa metni verilecek.
        İki iş yapacaksın:

        1) SINIFLANDIR. Haber Türkiye'de vergi mevzuatını, vergi
           uygulamalarını, mali yükümlülükleri veya mükellefleri doğrudan
           ilgilendiriyor mu? Şunlar ilgilidir: vergi kanunu değişiklikleri,
           tebliğ ve sirkülerler, oran/had/tutar güncellemeleri, beyanname
           süreçleri, e-belge düzenlemeleri, vergi cezaları ve incelemeleri,
           yapılandırma ve af düzenlemeleri, muhasebe ve denetim
           yükümlülükleri. Şunlar ilgili DEĞİLDİR: genel ekonomi ve piyasa
           haberleri, döviz ve borsa hareketleri, siyaset, magazin, spor,
           yalnızca vergi kelimesi geçen ama vergi düzenlemesi içermeyen
           haberler. Emin değilsen ilgili sayma.

        2) İLGİLİYSE HABERİ YAZ. Kurallar:
           - Kaynak metni yalnızca anlamak için okursun. ASLA cümle
             kopyalamazsın, yeniden ifade edersin. Özgün bir metin yaz.
           - Türkçe, sade ve kurumsal bir dille yaz. Tabloid üslup,
             abartı, ünlem ve tıklama tuzağı başlık kullanma.
           - 3-5 paragraf. Paragrafları BOŞ SATIRLA ayır.
           - Kaynakta olmayan hiçbir bilgiyi ekleme. Rakam, oran, tarih ve
             tutarları kaynaktaki gibi ver; kaynakta yoksa uydurma.
           - Yorum ve tavsiye verme; olanı aktar. "Yapmalısınız" deme.
           - Başlık en fazla 90 karakter, olguyu bildirsin.
           - Özet tek cümle, en fazla 200 karakter.
           - Etiketler: 2-4 adet, vergi terimleri (örnek: KDV, Tebliğ,
             Gelir Vergisi, e-Fatura).

        GÜVEN SKORU (0-100): Haberin doğruluğundan ve vergi alakasından ne
        kadar eminsin. Resmî kaynak (Resmî Gazete, GİB, Bakanlık) ve net
        mevzuat bilgisi varsa yüksek (85-100). İkincil kaynak, eksik
        ayrıntı veya "bekleniyor/planlanıyor" gibi kesinleşmemiş ifadeler
        varsa düşür (50-80). Şüpheliyse 50'nin altı.

        KONU GRUBU: Haberi, kullanıcı mesajında verilen gruplardan birine
        ata. Yanıtta grubun slug değerini TAM olarak yaz. Haber birden çok
        grubu ilgilendiriyorsa ağırlıklı olanı seç. Hiçbiri uymuyorsa
        "genel" kullan.

        AJAN NOTU: Haberi onaylayacak editöre tek cümlelik not. Neyi
        doğrulaması gerektiğini söyle; her şey netse bunu belirt.
        Örnek: "İkincil kaynak; tebliğ Resmî Gazete'de teyit edilmeli."

        İlgili değilse baslik, ozet, icerik, etiketler boş kalsın ve
        red_nedeni'ni tek cümleyle doldur.
        METIN;

    /** @var array<string,mixed> */
    private const SEMA = [
        'type' => 'object',
        'properties' => [
            'ilgili'      => ['type' => 'boolean', 'description' => 'Haber vergiyle ilgili mi'],
            'red_nedeni'  => ['type' => 'string',  'description' => 'İlgili değilse tek cümlelik gerekçe, ilgiliyse boş'],
            'baslik'      => ['type' => 'string',  'description' => 'Haber başlığı, en fazla 90 karakter'],
            'ozet'        => ['type' => 'string',  'description' => 'Tek cümlelik spot, en fazla 200 karakter'],
            'icerik'      => ['type' => 'string',  'description' => '3-5 paragraf, paragraflar boş satırla ayrılmış'],
            'etiketler'   => [
                'type'  => 'array',
                'items' => ['type' => 'string'],
                'description' => '2-4 vergi terimi',
            ],
            'kategori'    => ['type' => 'string',  'description' => 'Verilen konu grubu listesinden TAM olarak bir slug'],
            'guven_skoru' => ['type' => 'integer', 'description' => '0-100 arası güven'],
            'ajan_notu'   => ['type' => 'string',  'description' => 'Onaylayacak editöre tek cümlelik not'],
        ],
        'required' => [
            'ilgili', 'red_nedeni', 'baslik', 'ozet', 'icerik',
            'etiketler', 'kategori', 'guven_skoru', 'ajan_notu',
        ],
        'additionalProperties' => false,
    ];

    public function __construct(private readonly string $apiKey)
    {
    }

    /**
     * @param array{baslik:string,ozet:string,baglanti:string} $aday
     * @return array<string,mixed>|null Model yanıtı; çözümlenemezse null
     */
    public function isle(
        array $aday,
        string $sayfaMetni,
        string $kaynakAdi,
        string $kaynakTuru,
        array $kategoriler,
    ): ?array {
        $istem = $this->istemHazirla($aday, $sayfaMetni, $kaynakAdi, $kaynakTuru, $kategoriler);

        $govde = [
            'systemInstruction' => [
                'parts' => [['text' => self::YONERGE]],
            ],
            'contents' => [[
                'role'  => 'user',
                'parts' => [['text' => $istem]],
            ]],
            'generationConfig' => [
                'maxOutputTokens' => 8000,
                'responseFormat' => [
                    'text' => [
                        'mimeType' => 'application/json',
                        'schema'   => self::SEMA,
                    ],
                ],
            ],
        ];

        $yanit = $this->geminiIstegi($govde);

        $adaylar = $yanit['candidates'] ?? [];
        if (!is_array($adaylar) || $adaylar === []) {
            return null;
        }

        $parcalar = $adaylar[0]['content']['parts'] ?? [];
        if (!is_array($parcalar)) {
            return null;
        }

        foreach ($parcalar as $parca) {
            if (!is_array($parca) || !isset($parca['text']) || !is_string($parca['text'])) {
                continue;
            }

            $veri = json_decode($parca['text'], true);

            if (is_array($veri) && array_key_exists('ilgili', $veri)) {
                return $veri;
            }
        }

        return null;
    }

    /**
     * Gemini generateContent çağrısı.
     *
     * Geçici 429/5xx durumlarında üç kez dener; kalıcı hatada Google'ın
     * döndürdüğü mesajı loga taşıyarak teşhisi kolaylaştırır.
     *
     * @param array<string,mixed> $govde
     * @return array<string,mixed>
     */
    private function geminiIstegi(array $govde): array
    {
        $adres = self::API . self::MODEL . ':generateContent';
        $json  = json_encode($govde, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($json)) {
            throw new \RuntimeException('Gemini isteği JSON olarak hazırlanamadı.');
        }

        $sonMesaj = '';

        for ($deneme = 1; $deneme <= 3; $deneme++) {
            $ch = curl_init($adres);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $json,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'x-goog-api-key: ' . $this->apiKey,
                ],
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT        => 90,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            ]);

            $ham   = curl_exec($ch);
            $kod   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $hata  = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);

            if (!is_string($ham)) {
                $sonMesaj = $hata !== '' ? $hata : 'bilinmeyen ağ hatası';

                if ($deneme < 3 && in_array($errno, [
                    CURLE_COULDNT_RESOLVE_HOST,
                    CURLE_COULDNT_CONNECT,
                    CURLE_OPERATION_TIMEDOUT,
                ], true)) {
                    sleep($deneme * 3);
                    continue;
                }

                throw new \RuntimeException('Gemini API erişim hatası: ' . $sonMesaj);
            }

            $veri = json_decode($ham, true);

            if ($kod >= 200 && $kod < 300 && is_array($veri)) {
                return $veri;
            }

            $sonMesaj = is_array($veri)
                ? (string) ($veri['error']['message'] ?? ('HTTP ' . $kod))
                : ('HTTP ' . $kod . ': ' . mb_substr($ham, 0, 300, 'UTF-8'));

            if ($deneme < 3 && ($kod === 429 || $kod >= 500)) {
                sleep($deneme * 10);
                continue;
            }

            throw new \RuntimeException('Gemini API hatası (HTTP ' . $kod . '): ' . $sonMesaj);
        }

        throw new \RuntimeException('Gemini API hatası: ' . $sonMesaj);
    }

    /**
     * @param array{baslik:string,ozet:string,baglanti:string} $aday
     */
    private function istemHazirla(
        array $aday,
        string $sayfaMetni,
        string $kaynakAdi,
        string $kaynakTuru,
        array $kategoriler,
    ): string {
        $turAciklama = $kaynakTuru === 'resmi'
            ? 'Bu birincil/resmî bir kaynaktır.'
            : 'Bu ikincil bir haber kaynağıdır.';

        $metin = $sayfaMetni !== ''
            ? $sayfaMetni
            : '(Sayfa metni alınamadı; yalnızca başlık ve özet mevcut.)';

        $grupSatirlari = [];

        foreach ($kategoriler as $kategori) {
            $aciklama = trim((string) ($kategori['aciklama'] ?? ''));
            $grupSatirlari[] = '- ' . $kategori['slug'] . ' (' . $kategori['ad'] . ')'
                . ($aciklama !== '' ? ': ' . $aciklama : '');
        }

        $gruplar = implode("\n", $grupSatirlari);

        return <<<METIN
        Konu grupları (kategori alanına bunlardan birinin slug'ını yaz):
        {$gruplar}

        Kaynak: {$kaynakAdi}
        {$turAciklama}
        Adres: {$aday['baglanti']}

        Besleme başlığı:
        {$aday['baslik']}

        Besleme özeti:
        {$aday['ozet']}

        Sayfa metni:
        ---
        {$metin}
        ---

        Bu haberi sınıflandır; vergiyle ilgiliyse Valentra için özgün bir
        haber metni yaz.
        METIN;
    }
}
