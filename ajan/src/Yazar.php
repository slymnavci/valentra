<?php
declare(strict_types=1);

namespace Valentra\Ajan;

use Anthropic\Client;

/**
 * Aday haberi Claude'a verip iki soruyu birden yanıtlatır:
 * vergiyle ilgili mi, ve ilgiliyse haber metni nasıl yazılmalı.
 *
 * Telif: modele kaynak metin YALNIZCA anlaması için verilir. Çıktının
 * özgün olması, kaynaktan cümle kopyalanmaması ve kaynağa atıf verilmesi
 * sistem yönergesinde açıkça şart koşulur.
 */
final class Yazar
{
    private const MODEL = 'claude-opus-5';

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

    public function __construct(private readonly Client $istemci)
    {
    }

    /**
     * @param array{baslik:string,ozet:string,baglanti:string} $aday
     * @return array<string,mixed>|null  Model yanıtı; çözümlenemezse null
     */
    public function isle(
        array $aday,
        string $sayfaMetni,
        string $kaynakAdi,
        string $kaynakTuru,
        array $kategoriler,
    ): ?array {
        $istem = $this->istemHazirla($aday, $sayfaMetni, $kaynakAdi, $kaynakTuru, $kategoriler);

        $yanit = $this->istemci->messages->create(
            model: self::MODEL,
            maxTokens: 8000,
            system: [
                ['type' => 'text', 'text' => self::YONERGE, 'cacheControl' => ['type' => 'ephemeral']],
            ],
            thinking: ['type' => 'adaptive'],
            messages: [['role' => 'user', 'content' => $istem]],
            outputConfig: [
                'format' => ['type' => 'json_schema', 'schema' => self::SEMA],
            ],
        );

        if ($yanit->stopReason === 'refusal') {
            return null;
        }

        foreach ($yanit->content as $blok) {
            if ($blok->type !== 'text') {
                continue;
            }

            $veri = json_decode($blok->text, true);

            if (is_array($veri) && array_key_exists('ilgili', $veri)) {
                return $veri;
            }
        }

        return null;
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
