<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * Haber görsellerinde haber sitesi adı / logosu / filigranı denetimi ve
 * yerine konacak özgür lisanslı görsel araması (bkz. ajan/gorsel_denetle.php).
 *
 * Site sahibinin karari (08.11.2026): "ana sayfada bir haberdeki resimde
 * kesinlikle bir haber sitesinin adi olmayacak; uygun resim yoksa
 * internetten konuya uygun, haber sitesi adi olmayan bir resim bulup
 * yerlestirelim."
 *
 *  - Denetim: gorseller partiler halinde Claude'a (yedek: Gemini; 10.11.2026)
 *    gosteriliyor; her biri icin "temiz" (medya kurulusu adi/logosu yok)
 *    ve istenirse "uygun" (basliga uyuyor) karari.
 *  - Arama: Wikimedia Commons ve Openverse; yalnizca ticari kullanima
 *    acik lisanslar (CC0, kamu mali, CC BY, CC BY-SA). Bulunan aday da
 *    ayni denetimden geciyor; atif satiri lisansin istedigi bicimde.
 */
final class GorselDenetci
{
    private const GEMINI_API = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private const CLAUDE_API = 'https://api.anthropic.com/v1/messages';

    private const TARAYICI = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                           . '(KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

    /** Wikimedia ve Openverse kimlik isteyen istemci adı (iletişim: site adresi). */
    private const BOT = 'ValentraGorselBot/1.0 (https://valentra.com.tr; haber gorseli arama)';

    /** Modele giden görselin en uzun kenarı: köşedeki küçük filigran okunabilsin. */
    private const EN_UZUN_KENAR = 1024;

    private const EN_FAZLA_BAYT = 8_000_000;

    private bool $geminiKapali = false;
    private bool $claudeKapali = false;
    private bool $openverseKapali = false;

    /** @var array{gemini:int,claude:int,hata:int} */
    private array $sayac = ['gemini' => 0, 'claude' => 0, 'hata' => 0];

    public function __construct(
        private readonly string $geminiKey,
        private readonly string $geminiModel,
        private readonly string $claudeKey = '',
        private readonly string $claudeModel = '',
        private readonly int $sonZaman = PHP_INT_MAX,
    ) {
    }

    /** @return array{gemini:int,claude:int,hata:int} */
    public function sayac(): array
    {
        return $this->sayac;
    }

    // ------------------------------------------------------------------
    // Indirme
    // ------------------------------------------------------------------

    /**
     * Adresleri birlikte indirir; modele gidecek küçültülmüş JPEG'i döndürür.
     *
     * @param array<int|string,string> $adresler
     * @return array<int|string,?array{veri:string,tur:string,genislik:int,yukseklik:int}>
     */
    public function indir(array $adresler): array
    {
        $cok = curl_multi_init();
        $tutucular = [];

        foreach ($adresler as $anahtar => $url) {
            if (!preg_match('#^https?://#i', $url)) {
                continue;
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 4,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 25,
                CURLOPT_USERAGENT      => str_contains($url, 'wikimedia.org') ? self::BOT : self::TARAYICI,
                CURLOPT_HTTPHEADER     => ['Accept: image/avif,image/webp,image/png,image/jpeg,*/*;q=0.8'],
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ]);
            curl_multi_add_handle($cok, $ch);
            $tutucular[$anahtar] = $ch;
        }

        do {
            $durum = curl_multi_exec($cok, $calisan);

            if ($calisan > 0) {
                curl_multi_select($cok, 1.0);
            }
        } while ($calisan > 0 && $durum === CURLM_OK);

        $sonuc = [];

        foreach ($adresler as $anahtar => $_) {
            $sonuc[$anahtar] = null;
        }

        foreach ($tutucular as $anahtar => $ch) {
            $govde = curl_multi_getcontent($ch);
            $kod   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_multi_remove_handle($cok, $ch);
            curl_close($ch);

            if ($kod === 200 && is_string($govde) && $govde !== '' && strlen($govde) <= self::EN_FAZLA_BAYT) {
                $sonuc[$anahtar] = $this->hazirla($govde);
            }
        }

        curl_multi_close($cok);

        return $sonuc;
    }

    /** @return ?array{veri:string,tur:string,genislik:int,yukseklik:int} */
    private function hazirla(string $ikili): ?array
    {
        $bilgi = @getimagesizefromstring($ikili);

        if ($bilgi === false || $bilgi[0] < 50 || $bilgi[1] < 50) {
            return null;
        }

        [$g, $y] = $bilgi;
        $tur = (string) ($bilgi['mime'] ?? '');

        if (function_exists('imagecreatefromstring')) {
            $resim = @imagecreatefromstring($ikili);

            if ($resim !== false) {
                $oran = min(1.0, self::EN_UZUN_KENAR / max($g, $y));
                $yg = max(1, (int) round($g * $oran));
                $yy = max(1, (int) round($y * $oran));
                $tuval = imagecreatetruecolor($yg, $yy);
                imagefill($tuval, 0, 0, (int) imagecolorallocate($tuval, 255, 255, 255));
                imagecopyresampled($tuval, $resim, 0, 0, 0, 0, $yg, $yy, $g, $y);
                ob_start();
                imagejpeg($tuval, null, 85);
                $jpeg = (string) ob_get_clean();
                imagedestroy($resim);
                imagedestroy($tuval);

                if ($jpeg !== '') {
                    return ['veri' => $jpeg, 'tur' => 'image/jpeg', 'genislik' => $g, 'yukseklik' => $y];
                }
            }
        }

        if (in_array($tur, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) && strlen($ikili) <= 4_000_000) {
            return ['veri' => $ikili, 'tur' => $tur, 'genislik' => $g, 'yukseklik' => $y];
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Denetim
    // ------------------------------------------------------------------

    private const YONERGE = <<<'METIN'
Sen bir haber sitesinin görsel editörüsün. Sana numaralı görseller ve her
birinin ait olduğu haberin başlığı verilecek. Her görsel için karar ver.

TEMİZ Mİ? Görselde bir MEDYA KURULUŞUNUN izi var mı: haber sitesi, gazete,
dergi, TV kanalı, radyo, haber ajansı ya da fotoğraf ajansının adı, logosu,
amblemi, filigranı (watermark), site adresi (www..., .com, .com.tr).
Örnekler: AA, Anadolu Ajansı, DHA, İHA, Reuters, AFP, AP, Getty Images,
Shutterstock, Alamy, EPA, NTV, CNN Türk, TRT, Habertürk, Show TV, A Haber,
Halk TV, Sözcü, Hürriyet, Sabah, Milliyet, BBC, Euronews, DW, Sputnik.
Şunların hepsi temiz=false:
- Köşede, kenarda ya da ortada, küçük ya da yarı saydam logo veya filigran.
- Mikrofonlarda, kürsüde ya da ekranda görünen kanal/ajans logoları.
- TV ekran görüntüsü: kanal bandı, "SON DAKİKA" bandı, alt yazı şeridi.
- Görselin üstüne yazılmış manşet, haber başlığı, haber kartı tasarımı.
- Medya adı OLMASA BİLE fotoğrafın üstüne sonradan bindirilmiş büyük yazı
  (site sahibi, 11.11.2026: "ana sayfada yazısız resimler kullan"): video
  kapağı yazısı, slogan, alıntı cümlesi, "SON DAKİKA"/"FLAŞ" bandı, skor
  ya da tarih kartı, afiş/duyuru tasarımı, kolaj üstü başlık.
Şunlar medya kuruluşu DEĞİL, tek başlarına görseli kirletmez (temiz=true):
spor kulübü armaları ve formalar, marka/şirket logoları (ürün, bina,
tabela), kamu kurumu amblemleri, bayraklar, stadyum reklam panoları,
fotoğrafın doğal parçası olan yazılar.
Emin değilsen ve yazı ya da amblem bir medya kuruluşuna benziyorsa temiz=false.

UYGUN MU? Görsel haberin konusuna uyuyor mu (yer, nesne, olay, alan)?
Haberin başlığına değil KONUSUNA bak (özet verildiyse onu da oku).
Birebir aynı olay olması gerekmez; konuyu temsil etmesi yeter. Konuyla
ilgisiz, yanıltıcı ya da başka bir olayı/kişiyi gösteriyormuş gibi
duruyorsa uygun=false. Uygunsuz, şiddet içeren, çıplaklık içeren görsel
uygun=false.
KİŞİ FOTOĞRAFI YER KURALINDAN MUAF: Haberin konusu olan tanınmış kişinin
fotoğrafı, başka bir ülkede ya da toplantıda çekilmiş olsa da uygundur
(Zelenski haberine Zelenski'nin herhangi bir resmî görüşme fotoğrafı).
Aşağıdaki yer kuralı kişisiz, yer/nesne fotoğrafları içindir.
YANLIŞ YER (site sahibi, 11.11.2026: "resimler haberle alakasız"):
Görselde ya da arşiv adında haberdekinden BAŞKA bir ülke, şehir, kurum ya
da tesis tanınıyorsa uygun=false. Örnekler: Antalya'daki trafik haberine
ABD polis aracı; Türkiye'deki dava haberine ABD adliye binası; Türk
bakanın haberine başka ülkedeki santral; Beypazarı haberine ABD'deki
yaban atları; İstanbul haberine başka bir şehrin silueti. Yeri belli
olmayan, konunun NESNESİNİ gösteren genel fotoğraf uygundur (elektrik
sayacı, banknotlar, buğday tarlası, boş sınıf, ambulans ışığı), ama
üstünde başka ülkenin yazısı, plakası, üniforması ya da tabelası
okunuyorsa uygun=false.
FOTOĞRAF KALİTESİ (site sahibi: "resim kalitesi mevcut haberlerdeki
resimler kadar olsun"): Görsel güncel, net, renkli bir haber fotoğrafı
gibi durmalı. Şunlar uygun=false: bulanık, pikselli ya da çok karanlık
fotoğraf; kartpostal, gravür, tablo, çizim, karikatür; eski siyah-beyaz
arşiv fotoğrafı ya da gazete/dergi taraması; grafik, tablo, harita,
şema, logo, amblem, pul, afiş; ekran görüntüsü; kolaj.
KİŞİ: Haber bir kişiyle ilgiliyse (ör. "Erdoğan Bakü'de") o kişinin
başka bir yerde/tarihte çekilmiş fotoğrafı UYGUNDUR. Başka bir kişiyi
gösteren fotoğraf uygun=false. Görselin arşivdeki adı verildiyse ona da
bak: adında haberdeki kişinin adı geçmiyorsa ve görselde bir kişi ön
plandaysa uygun=false.
YAZI VE SLOGAN: Üstünde okunur slogan, pankart, siyasi mesaj ya da taraf
tutan ifade olan görsel (ör. adında ya da üstünde "occupied", "işgal",
"... is ..." gibi) uygun=false.

"bulunan": temiz=false ise ne gördüğün (ör. "sağ altta AA logosu").
METIN;

    /**
     * Görselleri denetler.
     *
     * @param list<array{resim:array{veri:string,tur:string},baslik:string}> $ogeler
     * @return list<?array{temiz:bool,uygun:bool,bulunan:string}> aynı sırada; null: karar alınamadı
     */
    public function denetle(array $ogeler): array
    {
        if ($ogeler === []) {
            return [];
        }

        $sema = [
            'type'       => 'object',
            'properties' => [
                'kararlar' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'sira'    => ['type' => 'integer', 'description' => 'Görsel numarası (1 tabanlı)'],
                            'temiz'   => ['type' => 'boolean', 'description' => 'Medya kuruluşu adı/logosu/filigranı YOK'],
                            'uygun'   => ['type' => 'boolean', 'description' => 'Haberin konusuna uyuyor'],
                            'bulunan' => ['type' => 'string',  'description' => 'temiz=false ise görülen ad/logo; değilse boş'],
                        ],
                        'required' => ['sira', 'temiz', 'uygun', 'bulunan'],
                    ],
                ],
            ],
            'required' => ['kararlar'],
        ];

        $veri = null;

        // Once Claude, olmazsa Gemini (site sahibi, 10.11.2026: "hepsinde once Claude").
        if (!$this->claudeKapali && $this->claudeKey !== '') {
            $veri = $this->claudeGorsel($ogeler, $sema);
        }

        if ($veri === null && !$this->geminiKapali && $this->geminiKey !== '') {
            $veri = $this->geminiGorsel($ogeler, $sema);
        }

        $sonuc = array_fill(0, count($ogeler), null);

        if ($veri === null) {
            $this->sayac['hata']++;

            return $sonuc;
        }

        foreach ((array) ($veri['kararlar'] ?? []) as $k) {
            $i = (int) ($k['sira'] ?? 0) - 1;

            if ($i >= 0 && $i < count($ogeler) && is_bool($k['temiz'] ?? null)) {
                $sonuc[$i] = [
                    'temiz'   => (bool) $k['temiz'],
                    'uygun'   => (bool) ($k['uygun'] ?? false),
                    'bulunan' => mb_substr(trim((string) ($k['bulunan'] ?? '')), 0, 200, 'UTF-8'),
                ];
            }
        }

        return $sonuc;
    }

    /**
     * @param list<array{resim:array{veri:string,tur:string},baslik:string}> $ogeler
     * @param array<string,mixed> $sema
     * @return ?array<string,mixed>
     */
    private function geminiGorsel(array $ogeler, array $sema): ?array
    {
        $parcalar = [['text' => 'Aşağıda ' . count($ogeler) . ' görsel var. Her biri için karar ver.']];

        foreach ($ogeler as $i => $o) {
            $parcalar[] = ['text' => 'Görsel ' . ($i + 1) . ' — haber başlığı: ' . $o['baslik']
                . (($o['ozet'] ?? '') !== '' ? "\n   Haberin özeti: " . $o['ozet'] : '')
                . (($o['ad'] ?? '') !== '' ? "\n   Görselin arşivdeki adı: " . $o['ad'] : '')];
            $parcalar[] = ['inlineData' => ['mimeType' => $o['resim']['tur'], 'data' => base64_encode($o['resim']['veri'])]];
        }

        return $this->geminiJson($parcalar, $sema, self::YONERGE);
    }

    /**
     * @param list<array<string,mixed>> $parcalar
     * @param array<string,mixed> $sema
     * @return ?array<string,mixed>
     */
    private function geminiJson(array $parcalar, array $sema, string $yonerge): ?array
    {
        $govde = [
            'systemInstruction'  => ['parts' => [['text' => $yonerge]]],
            'contents'           => [['role' => 'user', 'parts' => $parcalar]],
            'generationConfig'   => [
                'temperature'      => 0,
                'responseMimeType' => 'application/json',
                'responseSchema'   => $sema,
            ],
        ];

        foreach ([0, 5, 15] as $bekle) {
            if ($bekle > 0) {
                if (time() + $bekle + 40 > $this->sonZaman) {
                    return null;
                }

                sleep($bekle);
            }

            [$kod, $yanit] = $this->post(
                self::GEMINI_API . $this->geminiModel . ':generateContent',
                ['Content-Type: application/json', 'x-goog-api-key: ' . $this->geminiKey],
                $govde,
                90
            );

            if ($kod === 200) {
                $j = json_decode($yanit, true);
                $metin = '';

                foreach ((array) ($j['candidates'][0]['content']['parts'] ?? []) as $p) {
                    $metin .= (string) ($p['text'] ?? '');
                }

                $veri = json_decode($metin, true);

                if (is_array($veri)) {
                    $this->sayac['gemini']++;

                    return $veri;
                }

                return null;
            }

            // Anahtar/izin/kota sorunu: bu calismada Gemini'yi birak.
            if (in_array($kod, [400, 401, 403, 404], true)) {
                fwrite(STDERR, 'Gemini görsel denetimi: HTTP ' . $kod . ' ' . mb_substr($yanit, 0, 300) . PHP_EOL);
                $this->geminiKapali = true;

                return null;
            }
        }

        return null;
    }

    /**
     * @param list<array{resim:array{veri:string,tur:string},baslik:string}> $ogeler
     * @param array<string,mixed> $sema
     * @return ?array<string,mixed>
     */
    private function claudeGorsel(array $ogeler, array $sema): ?array
    {
        $icerik = [['type' => 'text', 'text' => 'Aşağıda ' . count($ogeler) . ' görsel var. Her biri için karar ver.']];

        foreach ($ogeler as $i => $o) {
            $icerik[] = ['type' => 'text', 'text' => 'Görsel ' . ($i + 1) . ' — haber başlığı: ' . $o['baslik']
                . (($o['ozet'] ?? '') !== '' ? "\n   Haberin özeti: " . $o['ozet'] : '')
                . (($o['ad'] ?? '') !== '' ? "\n   Görselin arşivdeki adı: " . $o['ad'] : '')];
            $icerik[] = ['type' => 'image', 'source' => [
                'type' => 'base64', 'media_type' => $o['resim']['tur'], 'data' => base64_encode($o['resim']['veri']),
            ]];
        }

        return $this->claudeJson($icerik, $sema, self::YONERGE);
    }

    /**
     * @param list<array<string,mixed>> $icerik
     * @param array<string,mixed> $sema
     * @return ?array<string,mixed>
     */
    private function claudeJson(array $icerik, array $sema, string $yonerge): ?array
    {
        $govde = [
            'model'         => $this->claudeModel,
            'max_tokens'    => 4000,
            'system'        => $yonerge,
            'messages'      => [['role' => 'user', 'content' => $icerik]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => self::kesinSema($sema)]],
        ];

        [$kod, $yanit] = $this->post(self::CLAUDE_API, [
            'Content-Type: application/json',
            'x-api-key: ' . $this->claudeKey,
            'anthropic-version: 2023-06-01',
        ], $govde, 120);

        if ($kod !== 200) {
            fwrite(STDERR, 'Claude görsel denetimi: HTTP ' . $kod . ' ' . mb_substr($yanit, 0, 300) . PHP_EOL);

            if (in_array($kod, [400, 401, 403, 404], true)) {
                $this->claudeKapali = true;
            }

            return null;
        }

        $j = json_decode($yanit, true);

        foreach ((array) ($j['content'] ?? []) as $blok) {
            if (($blok['type'] ?? '') === 'text') {
                $veri = json_decode((string) $blok['text'], true);

                if (is_array($veri)) {
                    $this->sayac['claude']++;

                    return $veri;
                }
            }
        }

        return null;
    }

    /**
     * Claude'un kesin şeması: her nesnede additionalProperties=false, açıklamalar korunuyor.
     *
     * @param array<string,mixed> $sema
     * @return array<string,mixed>
     */
    private static function kesinSema(array $sema): array
    {
        if (($sema['type'] ?? '') === 'object') {
            $sema['additionalProperties'] = false;

            foreach ((array) ($sema['properties'] ?? []) as $ad => $alt) {
                $sema['properties'][$ad] = self::kesinSema((array) $alt);
            }
        }

        if (($sema['type'] ?? '') === 'array' && isset($sema['items'])) {
            $sema['items'] = self::kesinSema((array) $sema['items']);
        }

        return $sema;
    }

    // ------------------------------------------------------------------
    // Arama
    // ------------------------------------------------------------------

    /** Türkçe/aksanlı harfleri sadeleştirip küçültür ("Erdoğan" → "erdogan"). */
    public static function katla(string $metin): string
    {
        $metin = mb_strtolower(strtr($metin, ['İ' => 'i', 'I' => 'ı']), 'UTF-8');

        return strtr($metin, [
            'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u', 'ə' => 'a',
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'é' => 'e', 'è' => 'e',
            'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ô' => 'o',
            'õ' => 'o', 'ú' => 'u', 'û' => 'u', 'ñ' => 'n',
        ]);
    }

    /**
     * Haberler için görsel arama sözcükleri (İngilizce; Commons ve Openverse
     * İngilizce etiketli).
     *
     * @param list<array{baslik:string,ozet:string}> $haberler
     * @return list<array{liste:list<string>,kisiler:list<string>}> aynı sırada:
     *         1-3 sorgu ve haberdeki tanınmış kişiler (bkz. yedek_bul)
     */
    public function sorgular(array $haberler): array
    {
        $yonerge = <<<'METIN'
Sen bir haber sitesinin görsel editörüsün. Her haber için, özgür lisanslı
fotoğraf arşivlerinde (Wikimedia Commons, Openverse) aranacak İngilizce
arama sözcükleri yaz. Sırayla 2-3 sorgu. Her sorgu 1-4 kelime.

KİŞİ ÖNCE (site sahibinin kuralı: "Erdoğan Bakü'de diyorsa Erdoğan'ın
bir fotoğrafını kullan"): Haberin ana konusu tanınmış bir kamu figürüyse
(devlet/hükümet başkanı, bakan, parti lideri, ünlü sporcu, teknik
direktör, sanatçı) İLK sorgu o kişinin tam adı, Wikipedia'daki yazımıyla
("Recep Tayyip Erdoğan", "Ilham Aliyev", "Donald Trump", "Mohamed
Salah"). Haber bir ziyaret ya da iki ülke/lider ilişkisiyse ilk sorgu
kişi + karşı taraf ("Erdoğan Aliyev", "Erdoğan Azerbaijan"), ikinci sorgu
kişinin tam adı. Bu kişileri "kisiler" listesine de yaz. Tanınmamış sıradan
kişilerin (kaza, suç haberindeki kişiler) adını YAZMA; kisiler boş kalsın.

Sonra özgül yer/kurum/nesne (ör. "Grand National Assembly of Turkey",
"Borsa Istanbul", "Baku"), en son genel ve kesin sonuç veren (ör. "gold
bars", "football stadium", "wheat field"). Haber sitesi, kanal ya da
ajans adı yazma. Bayrak ya da slogan aratma.

KONUYA GÖRE (site sahibi, 11.11.2026: "sadece başlığa göre değil konuya
göre"): Sorguları başlıktaki kelimelerden değil haberin METNİNDEN çıkar:
haber gerçekte neyi anlatıyor, okur hangi fotoğrafı görünce "bu haber
bu" der? Örnek: başlık "6,5 milyon abone etkilenecek", metin elektrik
faturası desteğini anlatıyorsa sorgu "electricity meter", "power lines
Turkey"; "abone" değil.
YER: Haber Türkiye'de geçiyorsa özgül sorguya yeri kat ("Antalya city",
"Erzurum winter", "Istanbul courthouse", "Ankara"). Başka bir ülkenin
kurumunu, polisini, adliyesini, şehrini getirecek çıplak genel sorgu
yazma ("city traffic", "courthouse building", "police car" YAZMA); genel
sorgu yalnızca yeri belli olmayan bir NESNE olsun ("electricity meter",
"Turkish lira banknotes", "olive harvest", "school classroom").
METIN;

        $sema = [
            'type'       => 'object',
            'properties' => [
                'sorgular' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'sira'    => ['type' => 'integer'],
                            'liste'   => ['type' => 'array', 'items' => ['type' => 'string']],
                            'kisiler' => ['type' => 'array', 'items' => ['type' => 'string'],
                                          'description' => 'Haberdeki tanınmış kamu figürlerinin tam adları; yoksa boş'],
                        ],
                        'required' => ['sira', 'liste', 'kisiler'],
                    ],
                ],
            ],
            'required' => ['sorgular'],
        ];

        $istem = '';

        foreach ($haberler as $i => $h) {
            $istem .= ($i + 1) . '. Başlık: ' . $h['baslik'] . "\n   Özet: " . mb_substr($h['ozet'], 0, 240, 'UTF-8') . "\n"
                    . (trim((string) ($h['metin'] ?? '')) !== ''
                        ? '   Metin: ' . preg_replace('/\s+/u', ' ', mb_substr((string) $h['metin'], 0, 700, 'UTF-8')) . "\n" : '');
        }

        $veri = null;

        if (!$this->claudeKapali && $this->claudeKey !== '') {
            $veri = $this->claudeJson([['type' => 'text', 'text' => $istem]], $sema, $yonerge);
        }

        if ($veri === null && !$this->geminiKapali && $this->geminiKey !== '') {
            $veri = $this->geminiJson([['text' => $istem]], $sema, $yonerge);
        }

        $sonuc = array_fill(0, count($haberler), ['liste' => [], 'kisiler' => []]);
        $temizle = static fn (array $l, int $n): array => array_values(array_slice(array_filter(array_map(
            static fn ($q): string => trim(mb_substr((string) $q, 0, 60, 'UTF-8')),
            $l
        ), static fn (string $q): bool => $q !== ''), 0, $n));

        foreach ((array) ($veri['sorgular'] ?? []) as $s) {
            $i = (int) ($s['sira'] ?? 0) - 1;

            if ($i >= 0 && $i < count($haberler)) {
                $sonuc[$i] = [
                    'liste'   => $temizle((array) ($s['liste'] ?? []), 3),
                    'kisiler' => $temizle((array) ($s['kisiler'] ?? []), 3),
                ];
            }
        }

        return $sonuc;
    }

    /**
     * Özgür lisanslı aday görseller (önce Wikimedia Commons, sonra Openverse).
     *
     * @return list<array{url:string,ad:string,atif:string,sayfa:string}>
     */
    public function ara(string $sorgu, int $enFazla = 6): array
    {
        $adaylar = $this->commons($sorgu);

        if (count($adaylar) < $enFazla && !$this->openverseKapali) {
            $adaylar = array_merge($adaylar, $this->openverse($sorgu));
        }

        $gorulen = [];
        $sonuc = [];

        foreach ($adaylar as $a) {
            if (!isset($gorulen[$a['url']])) {
                $gorulen[$a['url']] = true;
                $sonuc[] = $a;
            }
        }

        return array_slice($sonuc, 0, $enFazla);
    }

    /** @return list<array{url:string,ad:string,atif:string,sayfa:string}> */
    private function commons(string $sorgu): array
    {
        $adres = 'https://commons.wikimedia.org/w/api.php?' . http_build_query([
            'action'       => 'query',
            'format'       => 'json',
            'generator'    => 'search',
            'gsrsearch'    => $sorgu . ' filetype:bitmap',
            'gsrnamespace' => 6,
            'gsrlimit'     => 30,   // 15'ten (12.11.2026): boyut ve ad suzgeclerinden sonra aday kalmiyordu
            'prop'         => 'imageinfo',
            'iiprop'       => 'url|size|mime|extmetadata',
            'iiurlwidth'   => 1280,
        ]);

        [$kod, $yanit] = $this->get($adres);
        $j = $kod === 200 ? json_decode($yanit, true) : null;

        if (!is_array($j)) {
            return [];
        }

        $sayfalar = array_values((array) ($j['query']['pages'] ?? []));
        usort($sayfalar, static fn ($a, $b): int => ((int) ($a['index'] ?? 0)) <=> ((int) ($b['index'] ?? 0)));
        $sonuc = [];

        foreach ($sayfalar as $s) {
            $b = (array) ($s['imageinfo'][0] ?? []);
            $m = (array) ($b['extmetadata'] ?? []);
            $lisans = trim((string) ($m['LicenseShortName']['value'] ?? ''));

            // Yalnizca fotograf (PNG cogunlukla grafik/harita/logo), en az 1200
            // piksel genis ve yatay (11.11.2026: mansette bulanik gorunmesin;
            // onceden 800 piksel ve PNG de kabul ediliyordu).
            if (!in_array((string) ($b['mime'] ?? ''), ['image/jpeg', 'image/webp'], true)
                || !self::boyutUygun((int) ($b['width'] ?? 0), (int) ($b['height'] ?? 0))) {
                continue;
            }

            $acik = preg_match('/^(CC0|Public domain|PD|CC BY(-SA)? [\d.]+)/i', $lisans) === 1;

            if (!$acik || stripos($lisans, 'NC') !== false || stripos($lisans, 'ND') !== false) {
                continue;
            }

            // thumburl "?utm_source=..." ekiyle geliyor; gorselin kendisi eksiz adreste.
            $url = (string) preg_replace('/\?.*$/', '', (string) ($b['thumburl'] ?? $b['url'] ?? ''));

            if (!str_starts_with($url, 'https://upload.wikimedia.org/')) {
                continue;
            }

            $sanatci = trim(html_entity_decode(strip_tags((string) ($m['Artist']['value'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $sanatci = self::kisaAd((string) preg_replace('/\s+/u', ' ', $sanatci));
            $kamu    = preg_match('/^(CC0|Public domain|PD)/i', $lisans) === 1;

            $sonuc[] = [
                'url'   => $url,
                // Dosya adi ("File:Recep Tayyip Erdogan 2023.jpg"): kisi eslesmesi icin.
                'ad'    => mb_substr(preg_replace('/^File:|\.[a-z]+$/i', '', (string) ($s['title'] ?? '')) ?? '', 0, 160, 'UTF-8'),
                'atif'  => 'Fotoğraf: ' . ($sanatci !== '' ? $sanatci . ', ' : '')
                         . ($kamu ? 'kamu malı' : $lisans) . ' (Wikimedia Commons)',
                'sayfa' => (string) ($b['descriptionurl'] ?? ''),
            ];
        }

        return $sonuc;
    }

    /** @return list<array{url:string,ad:string,atif:string,sayfa:string}> */
    private function openverse(string $sorgu): array
    {
        $adres = 'https://api.openverse.org/v1/images/?' . http_build_query([
            'q'         => $sorgu,
            'license'   => 'cc0,pdm,by,by-sa',
            'page_size' => 15,
            'mature'    => 'false',
        ]);

        [$kod, $yanit] = $this->get($adres);

        if ($kod === 429 || $kod === 401 || $kod === 403) {
            $this->openverseKapali = true;

            return [];
        }

        $j = $kod === 200 ? json_decode($yanit, true) : null;
        $sonuc = [];

        foreach ((array) ($j['results'] ?? []) as $r) {
            $g = (int) ($r['width'] ?? 0);
            $y = (int) ($r['height'] ?? 0);
            $url = (string) ($r['url'] ?? '');

            // Genislik bilinmiyorsa (0) indirince denetleniyor; bilinen kucukler eleniyor.
            if (!str_starts_with($url, 'https://') || ($g > 0 && $y > 0 && !self::boyutUygun($g, $y))
                || preg_match('/\.(png|gif|svg)(\?|$)/i', $url) === 1) {
                continue;
            }

            $lisans  = strtolower((string) ($r['license'] ?? ''));
            $surum   = (string) ($r['license_version'] ?? '');
            $kamu    = in_array($lisans, ['cc0', 'pdm'], true);
            $sanatci = self::kisaAd(trim((string) ($r['creator'] ?? '')));
            $kaynak  = ucfirst((string) ($r['source'] ?? 'Openverse'));

            $sonuc[] = [
                'url'   => $url,
                'ad'    => mb_substr(trim((string) ($r['title'] ?? '')), 0, 160, 'UTF-8'),
                'atif'  => 'Fotoğraf: ' . ($sanatci !== '' ? $sanatci . ', ' : '')
                         . ($kamu ? 'kamu malı' : 'CC ' . strtoupper($lisans) . ($surum !== '' ? ' ' . $surum : ''))
                         . ' (' . $kaynak . ')',
                'sayfa' => (string) ($r['foreign_landing_url'] ?? ''),
            ];
        }

        return $sonuc;
    }

    /** Yerine konacak görselin en az genişliği (piksel). */
    public const EN_AZ_GENISLIK = 1200;

    /**
     * Aday görselin boyutu haber fotoğrafı gibi mi: en az EN_AZ_GENISLIK
     * genişlikte, yatay (en/boy 1,2 ile 2,4 arası; çok geniş panorama ve
     * kareye yakın görsel manşet ve kartta kötü kırpılıyor).
     */
    public static function boyutUygun(int $genislik, int $yukseklik): bool
    {
        if ($genislik < self::EN_AZ_GENISLIK || $yukseklik < 1) {
            return false;
        }

        $oran = $genislik / $yukseklik;

        return $oran >= 1.2 && $oran <= 2.4;
    }

    /**
     * Aday görselin adı fotoğraf olmayan ya da eski bir şeyi mi gösteriyor
     * (kartpostal, harita, grafik, logo, 1980 öncesi tarih)? 10.11.2026
     * çalışmasında "Lima City Hall postcard", 1924 tarihli dergi taraması
     * ve bir dış ticaret grafiği haberlere kondu.
     */
    public static function fotografDisi(string $ad): bool
    {
        $ad = self::katla($ad);

        return preg_match('/\b(postcard|kartpostal|map|harita|chart|graph|grafik|diagram|logo|emblem|coat of arms|flag|bayrak|'
            . 'stamp|poster|engraving|painting|drawing|illustration|cartoon|sketch|lithograph|screenshot|scan|svg)\b/', $ad) === 1
            || preg_match('/(?<!\d)(1[0-8]\d\d|19[0-7]\d)(\d{4})?(?!\d|\s*(x|px))/', $ad) === 1;
    }

    /**
     * Atıf satırı için yazar adı: Commons'ta "Artist" bazen uzun bir tanıtım
     * cümlesi; ilk parça (virgül/nokta/parantez öncesi) ve en fazla 50 karakter.
     */
    private static function kisaAd(string $ad): string
    {
        $ad = trim((string) preg_split('/\s*(?:[,;(\[]|\.\s|\s-\s)/u', $ad)[0]);

        if (mb_strlen($ad, 'UTF-8') > 50) {
            $ad = rtrim(mb_substr($ad, 0, 50, 'UTF-8'));
            $ad = mb_substr($ad, 0, (int) (mb_strrpos($ad, ' ', 0, 'UTF-8') ?: 50), 'UTF-8') . '…';
        }

        return $ad;
    }

    // ------------------------------------------------------------------
    // HTTP
    // ------------------------------------------------------------------

    /** @return array{0:int,1:string} */
    private function get(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_USERAGENT      => self::BOT,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        ]);
        $govde = curl_exec($ch);
        $kod   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$kod, is_string($govde) ? $govde : ''];
    }

    /**
     * @param list<string> $basliklar
     * @param array<string,mixed> $govde
     * @return array{0:int,1:string}
     */
    private function post(string $url, array $basliklar, array $govde, int $zamanAsimi): array
    {
        $kalan = $this->sonZaman - time();

        if ($kalan < 20) {
            return [0, 'süre doldu'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => (string) json_encode($govde, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => $basliklar,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT        => min($zamanAsimi, $kalan),
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        ]);
        $yanit = curl_exec($ch);
        $kod   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hata  = curl_error($ch);
        curl_close($ch);

        return [$kod, is_string($yanit) && $yanit !== '' ? $yanit : $hata];
    }
}
