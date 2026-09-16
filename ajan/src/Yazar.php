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
final class Yazar implements SemaliIstemci
{
    /**
     * Varsayilan model. GEMINI_MODEL ortam degiskeniyle degistirilebilir;
     * model adi degisirse kod duzenlemeye gerek kalmaz.
     */
    private const VARSAYILAN_MODEL = 'gemini-3.8-flash';
    private const API   = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private const YONERGE = <<<'METIN'
        Sen Valentra adlı vergi haberleri sitesinin editör yardımcısısın.
        Valentra bir yeminli mali müşavirlik kuruluşudur; okuyucuları mali
        müşavirler, muhasebeciler, şirketlerin mali işler birimleri ve
        mükelleflerdir.

        Sana bir haber kaynağından başlık, özet ve sayfa metni verilecek.
        İki iş yapacaksın:

        1) SINIFLANDIR. Haber iki başlıktan birine giriyor mu?

           A) VERGİ VE MALİ MEVZUAT (asıl odak). Vergi kanunu
              değişiklikleri, tebliğ ve sirkülerler, oran/had/tutar
              güncellemeleri, beyanname süreçleri, e-belge düzenlemeleri,
              vergi cezaları ve incelemeleri, yapılandırma ve af
              düzenlemeleri, muhasebe ve denetim yükümlülükleri.

           B) EKONOMİ GÜNDEMİ (ikincil). Mükellefleri ve şirketleri
              ilgilendiren makroekonomik gelişmeler: enflasyon ve TÜFE
              verileri, Merkez Bankası faiz kararları, kur ve piyasa
              hareketleri, büyüme ve istihdam verileri, bütçe ve kamu
              maliyesi, teşvik ve destek programları, asgari ücret,
              sektörel ekonomik düzenlemeler.

           Şunlar İKİSİ DE DEĞİLDİR ve ilgili sayılmaz: siyaset, magazin,
           spor, kültür-sanat, asayiş, hava durumu, tanıtım ve reklam
           içerikleri, yalnızca bir bakanın katıldığı etkinlik duyuruları,
           içeriği olmayan "şu yayına katılacak" türü duyurular.

           Emin değilsen ilgili sayma. A grubuna girenler daha değerli;
           B grubunda yalnızca somut veri ya da karar içeren haberleri al,
           yorum ve beklenti yazılarını alma.

        2) İLGİLİYSE HABERİ YAZ. Kurallar:
           - Kaynak metni yalnızca anlamak için okursun. ASLA cümle
             kopyalamazsın, yeniden ifade edersin. Özgün bir metin yaz.
           - Türkçe, sade ve kurumsal bir dille yaz. Tabloid üslup,
             abartı, ünlem ve tıklama tuzağı başlık kullanma.
           - ÇIKTININ TAMAMI TÜRKÇE olmalı: başlık, özet, haber metni,
             etiketler, red nedeni ve ajan notu. Yalnızca kurum adları,
             kişi adları ve standart kodları (OECD, IASB, IFRS 18 gibi)
             özgün biçimini koruyabilir.
           - KAYNAK YABANCI DİLDE OLABİLİR. Bu durumda da haberi
             TÜRKÇE yazarsın; cümle cümle çeviri yapma, içeriği anlayıp
             Türk okura kendi cümlelerinle aktar. Mesleki terimleri
             Türkçe karşılığıyla kullan (corporate tax -> kurumlar
             vergisi, VAT -> KDV, withholding -> stopaj, transfer
             pricing -> transfer fiyatlandırması, IFRS -> UFRS/TFRS).
             Kurum ve standart adlarını olduğu gibi bırak, gerekiyorsa
             parantezle açıkla (OECD, IFRS 18).
           - Yabancı bir düzenleme haberinde Türkiye bağlantısı varsa
             (Türkiye'nin taraf olduğu anlaşma, TFRS'ye yansıyacak bir
             standart) bunu kaynakta yazılı olduğu ölçüde belirt.
             Kaynakta yoksa kendin çıkarım yapma.
           - Para birimlerini kaynaktaki birimiyle ver, TL'ye çevirme.
           - 3-5 paragraf. Paragrafları BOŞ SATIRLA ayır.
           - Kaynakta olmayan hiçbir bilgiyi ekleme. Rakam, oran, tarih ve
             tutarları kaynaktaki gibi ver; kaynakta yoksa uydurma.
           - Yorum ve tavsiye verme; olanı aktar. "Yapmalısınız" deme.
           - Başlık en fazla 90 karakter, olguyu bildirsin.
           - Özet tek cümle, en fazla 200 karakter.
           - Etiketler: 2-4 adet, vergi terimleri (örnek: KDV, Tebliğ,
             Gelir Vergisi, e-Fatura).

        GÜVEN SKORU (0-100): Haberin doğruluğundan ve site için
        değerinden ne kadar eminsin. Vergi mevzuatı haberleri ekonomi
        haberlerinden daha değerli; aynı güvenilirlikteki bir ekonomi
        haberini vergi haberinden birkaç puan aşağıda tut. Resmî kaynak
        (Resmî Gazete, GİB, Bakanlık) ve net mevzuat bilgisi varsa
        yüksek (85-100). İkincil kaynak, eksik
        ayrıntı veya "bekleniyor/planlanıyor" gibi kesinleşmemiş ifadeler
        varsa düşür (50-80). Şüpheliyse 50'nin altı.

        KONU GRUBU: Haberi, kullanıcı mesajında verilen gruplardan birine
        ata. Yanıtta grubun slug değerini TAM olarak yaz. Haber birden çok
        grubu ilgilendiriyorsa ağırlıklı olanı seç.

        Kategori eşlemesi KESİNDİR:
        - IFRS, IAS, IASB, IFRIC, ISSB, TMS, TFRS, BOBİ FRS, KÜMİ FRS,
          finansal raporlama standardı, muhasebe standardı ve bu
          standartlardaki değişiklikler -> "tms-tfrs".
        - Bağımsız denetim, denetim standardı, güvence standardı ve denetçi
          düzenlemeleri -> "denetim".
        - Makroekonomi, enflasyon, merkez bankası, faiz, büyüme, istihdam,
          bütçe ve dış ticaret verileri -> "ekonomi".
        - Vergi mevzuatı -> ilgili vergi alt grubuna.
        - Site kapsamına giren fakat yukarıdaki gruplardan hiçbirine
          oturmayan mali, muhasebesel veya düzenleyici haber -> "genel".

        "vergi-kanunlari" ve "muhasebe-denetim" yalnızca menü üst
        başlıklarıdır; kategori olarak ASLA seçme.

        AJAN NOTU: Haberi onaylayacak editöre tek cümlelik not. Neyi
        doğrulaması gerektiğini söyle; her şey netse bunu belirt.
        Örnek: "İkincil kaynak; tebliğ Resmî Gazete'de teyit edilmeli."

        İlgili değilse baslik, ozet, icerik, etiketler boş kalsın ve
        red_nedeni'ni tek cümleyle doldur.
        METIN;

    /**
     * Toplu sema: tek istekte birden cok haber degerlendirilir.
     *
     * Ucretsiz katmanda istek sayisi sinirli oldugu icin her haber icin
     * ayri cagri yapmak kotayi hemen tuketiyor. Adaylar gruplanip tek
     * istekte gonderilir; "sira" alani sonuclari adaylarla eslestirir.
     *
     * @var array<string,mixed>
     */
    private const TOPLU_SEMA = [
        'type' => 'object',
        'properties' => [
            'sonuclar' => [
                'type'  => 'array',
                'description' => 'Her aday icin bir sonuc, sirasiyla',
                'items' => self::HABER_SEMA_ITEM,
            ],
        ],
        'required' => ['sonuclar'],
    ];

    /** @var array<string,mixed> */
    private const HABER_SEMA_ITEM = [
        'type' => 'object',
        'properties' => [
            'sira'        => ['type' => 'integer', 'description' => 'Adayin kullanici mesajindaki sira numarasi'],
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
            'sira', 'ilgili', 'red_nedeni', 'baslik', 'ozet', 'icerik',
            'etiketler', 'kategori', 'guven_skoru', 'ajan_notu',
        ],
    ];

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
        // Gemini'nin responseSchema'si OpenAPI alt kumesidir;
        // additionalProperties desteklenmez, gonderilirse istek 400 doner.
        'required' => [
            'ilgili', 'red_nedeni', 'baslik', 'ozet', 'icerik',
            'etiketler', 'kategori', 'guven_skoru', 'ajan_notu',
        ],
    ];

    private readonly string $model;

    /** @var array{girdi:int,cikti:int,istek:int} Calisma boyunca biriken kullanim */
    private array $kullanim = ['girdi' => 0, 'cikti' => 0, 'istek' => 0];

    public function __construct(private readonly string $apiKey, string $model = '')
    {
        $ortam = (string) (getenv('GEMINI_MODEL') ?: '');

        $this->model = $model !== '' ? $model : ($ortam !== '' ? $ortam : self::VARSAYILAN_MODEL);
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
                'maxOutputTokens'  => 8000,
                // Gemini yapisal cikti icin bu iki alani kullanir.
                // "responseFormat" OpenAI'ya ait bir sekildir; Gemini
                // bilinmeyen alan olarak reddeder.
                'responseMimeType' => 'application/json',
                'responseSchema'   => self::SEMA,
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
        $adres = self::API . $this->model . ':generateContent';
        $json  = json_encode($govde, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($json)) {
            throw new \RuntimeException('Gemini isteği JSON olarak hazırlanamadı.');
        }

        $sonMesaj = '';

        // Ucretsiz katmanda 503 ("yogun talep") sik gorulur; birkac
        // saniyelik bekleme yetmez. Artan bekleme ile daha uzun israr
        // edilir: 4, 10, 20, 35, 60 saniye.
        $beklemeler = [4, 10, 20, 35, 60];
        $enFazlaDeneme = count($beklemeler) + 1;

        for ($deneme = 1; $deneme <= $enFazlaDeneme; $deneme++) {
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

                if ($deneme < $enFazlaDeneme && in_array($errno, [
                    CURLE_COULDNT_RESOLVE_HOST,
                    CURLE_COULDNT_CONNECT,
                    CURLE_OPERATION_TIMEDOUT,
                ], true)) {
                    sleep($beklemeler[$deneme - 1]);
                    continue;
                }

                throw new \RuntimeException('Gemini API erişim hatası: ' . $sonMesaj);
            }

            $veri = json_decode($ham, true);

            if ($kod >= 200 && $kod < 300 && is_array($veri)) {
                $this->kullanimiTopla($veri);

                return $veri;
            }

            $sonMesaj = is_array($veri)
                ? (string) ($veri['error']['message'] ?? ('HTTP ' . $kod))
                : ('HTTP ' . $kod . ': ' . mb_substr($ham, 0, 300, 'UTF-8'));

            // Gunluk kota bittiyse beklemenin anlami yok: kalan adaylar
            // da ayni duvara carpar. Cagrani bilgilendirip cikiyoruz.
            if ($kod === 429 && $this->gunlukKotaBitti($sonMesaj)) {
                throw new KotaBittiException($sonMesaj);
            }

            // 429 (kota) ve 5xx (gecici sunucu sorunu) yeniden denenir.
            if ($deneme < $enFazlaDeneme && ($kod === 429 || $kod >= 500)) {
                // Google kac saniye beklenecegini soyluyorsa ona uy;
                // kendi tahminimizle erken denemek istegi bosa harcar.
                $onerilen = $this->onerilenBekleme($veri, $sonMesaj);
                sleep($onerilen ?? $beklemeler[$deneme - 1]);
                continue;
            }

            throw new \RuntimeException('Gemini API hatası (HTTP ' . $kod . '): ' . $sonMesaj);
        }

        throw new \RuntimeException('Gemini API hatası: ' . $sonMesaj);
    }

    /**
     * Birden çok adayı tek istekte değerlendirir.
     *
     * Ücretsiz katmanda istek sayısı sınırlı; her haber için ayrı çağrı
     * kotayı hemen tüketiyor. Beş adaylık bir grup tek istekte işlenince
     * aynı iş beşte bir istekle yapılır.
     *
     * @param list<array{girdi:array{baslik:string,ozet:string,baglanti:string},sayfaMetni:string,kaynakAdi:string,kaynakTuru:string}> $adaylar
     * @param list<array<string,mixed>> $kategoriler
     * @return array<int,array<string,mixed>> sira => sonuc
     */
    public function topluIsle(array $adaylar, array $kategoriler): array
    {
        if ($adaylar === []) {
            return [];
        }

        return $this->semaliIstek(
            self::YONERGE,
            $this->topluIstemHazirla($adaylar, $kategoriler),
            self::TOPLU_SEMA,
            24000,
            count($adaylar),
            'ilgili'
        );
    }

    /**
     * Şema kısıtlı bir istek atar ve "sonuclar" dizisini sıraya göre döndürür.
     *
     * Haber yazimi ile pratik bilgi okuma ayni istek/yanit dokusunu
     * kullaniyor ama yonergeleri ve semalari tamamen farkli. Ortak olan
     * kismi burada topluyoruz; kopyalanmis bir istek kurulumu iki yerde
     * ayri ayri bozulurdu.
     *
     * @param array<string,mixed> $sema
     * @param int    $adayAdedi Sira numarasi denetimi icin
     * @param string $zorunluAlan Sonucta bulunmasi gereken alan ('' ise denetim yok)
     * @return array<int,array<string,mixed>> 0 tabanli sira => sonuc
     */
    public function semaliIstek(
        string $yonerge,
        string $istem,
        array $sema,
        int $enFazlaToken,
        int $adayAdedi = 0,
        string $zorunluAlan = ''
    ): array {
        $govde = [
            'systemInstruction' => [
                'parts' => [['text' => $yonerge]],
            ],
            'contents' => [[
                'role'  => 'user',
                'parts' => [['text' => $istem]],
            ]],
            'generationConfig' => [
                'maxOutputTokens'  => $enFazlaToken,
                'responseMimeType' => 'application/json',
                'responseSchema'   => $sema,
            ],
        ];

        $yanit    = $this->geminiIstegi($govde);
        $parcalar = $yanit['candidates'][0]['content']['parts'] ?? [];

        if (!is_array($parcalar)) {
            return [];
        }

        foreach ($parcalar as $parca) {
            if (!is_array($parca) || !isset($parca['text']) || !is_string($parca['text'])) {
                continue;
            }

            $veri = json_decode($parca['text'], true);

            if (!is_array($veri) || !isset($veri['sonuclar']) || !is_array($veri['sonuclar'])) {
                continue;
            }

            $sonuclar = [];

            foreach ($veri['sonuclar'] as $sonuc) {
                if (!is_array($sonuc)) {
                    continue;
                }

                if ($zorunluAlan !== '' && !array_key_exists($zorunluAlan, $sonuc)) {
                    continue;
                }

                // Sira numarasi 1'den baslar; dizi indisine cevriliyor.
                $sira = (int) ($sonuc['sira'] ?? 0) - 1;

                if ($sira < 0) {
                    continue;
                }

                if ($adayAdedi > 0 && $sira >= $adayAdedi) {
                    continue;
                }

                $sonuclar[$sira] = $sonuc;
            }

            return $sonuclar;
        }

        return [];
    }

    /**
     * @param list<array{girdi:array{baslik:string,ozet:string,baglanti:string},sayfaMetni:string,kaynakAdi:string,kaynakTuru:string}> $adaylar
     * @param list<array<string,mixed>> $kategoriler
     */
    private function topluIstemHazirla(array $adaylar, array $kategoriler): string
    {
        $gruplar = $this->gruplariYaz($kategoriler);
        $bloklar = [];

        foreach ($adaylar as $sira => $aday) {
            $no     = $sira + 1;
            $girdi  = $aday['girdi'];
            $tur    = $aday['kaynakTuru'] === 'resmi'
                ? 'Birincil/resmî kaynak.'
                : 'İkincil haber kaynağı.';

            // Grup halinde gonderildigi icin sayfa metni kisaltiliyor;
            // aksi halde istek gereksiz buyur ve model dagilir.
            $metin = $aday['sayfaMetni'] !== ''
                ? mb_substr($aday['sayfaMetni'], 0, 3000, 'UTF-8')
                : '(Sayfa metni alınamadı; yalnızca başlık ve özet mevcut.)';

            $bloklar[] = <<<METIN
            ### ADAY {$no}
            Kaynak: {$aday['kaynakAdi']} — {$tur}
            Adres: {$girdi['baglanti']}
            Başlık: {$girdi['baslik']}
            Özet: {$girdi['ozet']}
            Sayfa metni:
            ---
            {$metin}
            ---
            METIN;
        }

        $adayMetni = implode("\n\n", $bloklar);
        $adet      = count($adaylar);

        return <<<METIN
        Konu grupları (kategori alanına bunlardan birinin slug'ını yaz):
        {$gruplar}

        Aşağıda {$adet} haber adayı var. HER BİRİNİ ayrı ayrı değerlendir
        ve "sonuclar" dizisinde {$adet} sonuç döndür. Her sonucun "sira"
        alanına ilgili adayın numarasını yaz (1'den {$adet}'e kadar).
        Hiçbir adayı atlama; vergiyle ilgili olmayanlar için de ilgili
        değerini false yapıp red_nedeni'ni doldur.

        {$adayMetni}
        METIN;
    }

    /** @param list<array<string,mixed>> $kategoriler */
    private function gruplariYaz(array $kategoriler): string
    {
        $satirlar = [];

        foreach ($kategoriler as $kategori) {
            $aciklama = trim((string) ($kategori['aciklama'] ?? ''));
            $satirlar[] = '- ' . $kategori['slug'] . ' (' . $kategori['ad'] . ')'
                . ($aciklama !== '' ? ': ' . $aciklama : '');
        }

        return implode("\n", $satirlar);
    }

    /**
     * Yanittaki token sayilarini biriktirir.
     *
     * Gunluk maliyeti tahmin etmek yerine olcmek icin: Gemini her
     * yanitta usageMetadata ile kac token harcandigini bildiriyor.
     *
     * @param array<string,mixed> $veri
     */
    private function kullanimiTopla(array $veri): void
    {
        $olcum = $veri['usageMetadata'] ?? [];

        if (!is_array($olcum)) {
            return;
        }

        // Dusunme tokenlari ayri alanda gelir ama cikti gibi faturalanir;
        // saymazsak maliyet oldugundan az gorunur.
        $cikti = (int) ($olcum['candidatesTokenCount'] ?? 0)
               + (int) ($olcum['thoughtsTokenCount'] ?? 0);

        $this->kullanim['istek']++;
        $this->kullanim['girdi'] += (int) ($olcum['promptTokenCount'] ?? 0);
        $this->kullanim['cikti'] += $cikti;
    }

    /**
     * Çalışma boyunca harcanan token sayıları.
     *
     * @return array{girdi:int,cikti:int,istek:int}
     */
    public function kullanim(): array
    {
        return $this->kullanim;
    }

    /**
     * Google'in onerdigi bekleme suresi (saniye), yoksa null.
     *
     * Hata govdesinde yapisal RetryInfo ya da mesaj icinde
     * "Please retry in 40.4s" bicimi gelir.
     *
     * @param array<string,mixed>|null $veri
     */
    private function onerilenBekleme(?array $veri, string $mesaj): ?int
    {
        if (is_array($veri)) {
            foreach ($veri['error']['details'] ?? [] as $ayrinti) {
                $gecikme = $ayrinti['retryDelay'] ?? null;

                if (is_string($gecikme) && preg_match('/([\d.]+)s/', $gecikme, $m) === 1) {
                    return min(120, (int) ceil((float) $m[1]) + 2);
                }
            }
        }

        if (preg_match('/retry in ([\d.]+)\s*s/i', $mesaj, $m) === 1) {
            return min(120, (int) ceil((float) $m[1]) + 2);
        }

        return null;
    }

    /** Gunluk kota tukenmesi mi, yoksa dakikalik hiz siniri mi? */
    private function gunlukKotaBitti(string $mesaj): bool
    {
        // Dakikalik sinirda Google kisa bir bekleme onerir; gunluk
        // kotada "per day" ifadesi gecer ya da bekleme onerilmez.
        if (stripos($mesaj, 'per day') !== false || stripos($mesaj, 'PerDay') !== false) {
            return true;
        }

        return stripos($mesaj, 'quota') !== false
            && preg_match('/retry in ([\d.]+)\s*s/i', $mesaj) !== 1;
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
