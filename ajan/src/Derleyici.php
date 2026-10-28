<?php
declare(strict_types=1);

namespace Valentra\Ajan;

require_once __DIR__ . '/SemaliIstemci.php';

/**
 * Derleme haberleri yazar (site sahibinin karari, 28.10.2026).
 *
 * Tek kaynagin ozeti Google'a gore aslinin kopyasi; derleme haber ayni
 * olayi veren BIRDEN COK yayinin haberlerinden yazilan ozgun haber
 * (bkz. includes/derleme.php). Iki adim:
 *   1. olaySec(): son saatlerin haberlerinden, en az iki farkli yayinin
 *      verdigi en onemli olaylari secer; ayni olayin haberleri bir arada.
 *   2. yaz(): her olay icin tek bir derleme haber yazar: butun
 *      kaynaklarin bilgisi, arka plan, ne anlama geldigi, sik sorulanlar.
 *
 * DERLEME UYDURMAZ: rakam, tarih, isim ve alinti yalnizca malzemede
 * geciyorsa yaziliyor. Derlemeler panelde onaydan geciyor.
 */
final class Derleyici
{
    /** Seçim isteminde haber başına özet sınırı (karakter). */
    private const SECIM_OZET_SINIRI = 260;

    /** Yazı isteminde haber metni sınırı (karakter). */
    private const HABER_METNI_SINIRI = 4000;

    /** Yazı isteminde kaynak sayfa metni sınırı (karakter). */
    private const KAYNAK_METNI_SINIRI = 7000;

    /** Kabul edilecek en kısa metin (karakter); sitedeki sınırla aynı. */
    public const ASGARI_METIN = 1500;

    /** Bir olayın dayanması gereken en az farklı yayın; sitedekiyle aynı. */
    public const ASGARI_YAYIN = 2;

    private const ORTAK = <<<'METIN'
        Valentra, Türkiye'den ve dünyadan güncel haberleri yayımlayan bir
        haber sitesidir. Sitenin DERLEME haberleri, aynı olayı veren birden
        çok yayının haberlerini okuyup olayı tek yerde, eksiksiz ve
        anlaşılır biçimde anlatan özgün haberlerdir. Bir derleme, tek bir
        kaynağın yeniden yazımı DEĞİLDİR: okuyucu bu haberi okuduğunda
        kaynakların hiçbirinde tek başına bulamayacağı bütünlüklü bir
        tabloya ulaşmalıdır.

        YAYIN POLİTİKASI (her haber için geçerli, tartışmasız):
        - Siyasi taraf tutulmaz. Hiçbir partiyi, siyasetçiyi, hükümeti ya
          da muhalefeti övmez, yermez, hedef göstermez. Tarafların
          açıklanmış tutumları adlarıyla ve dengeli aktarılır.
        - Doğrulanmamış iddia, söylenti ve isimsiz kaynak malzeme olarak
          kullanılmaz.
        - Kişi ya da kurumlara suç isnat edilmez; yargı süreci sürüyorsa
          sonuç önceden ilan edilmez (masumiyet karinesi).
        - Devlet kurumları, güvenlik güçleri ve yargı hakkında dayanaksız
          olumsuz ifade kullanılmaz.
        - Hakaret, aşağılama, kışkırtıcı ve kutuplaştırıcı dil yoktur.
        - Yayın yasağı olan konulara ve terörle ilgili propaganda
          niteliğindeki içeriğe girilmez.
        - Dış meselelerde Türkiye'nin resmî tutumu esas alınır ve
          gerekçeleriyle aktarılır; savaş kışkırtıcılığı, bir halka ya da
          dine düşmanlık yoktur.
        METIN;

    private const SECIM_YONERGE = self::ORTAK . <<<'METIN'


        Görevin: aşağıda numaralı verilen haberlerden bugünün DERLEME
        haberlerinin konusu olacak OLAYLARI seçmek.

        - Bir olay = tek bir gelişme (bir karar, bir açıklama, bir maç,
          bir veri, bir kaza). Aynı olayı anlatan bütün haberleri o olayın
          haberleri olarak ver. Yalnızca konusu benzeyen ama farklı
          gelişmeleri anlatan haberleri aynı olaya koyma.
        - Her olay EN AZ İKİ FARKLI YAYININ haberine dayanmalı (her
          haberde "Yayın" yazıyor; aynı yayının iki haberi tek yayın
          sayılır). Tek yayının verdiği olayı SEÇME.
        - EN ÖNEMLİ OLAYLARDAN SEÇ: çok sayıda yayının verdiği, "Önem"
          puanı yüksek, kamuoyunu geniş ölçüde ilgilendiren olaylar önce.
          Özellikle ekonomi, vergi, mevzuat ve vatandaşın hayatını
          doğrudan etkileyen düzenlemeler değerlidir.
        - Konular çeşitli olsun: listede varsa gündem, ekonomi, dünya,
          spor ve diğer başlıklardan olaylar.
        - "ÖNCEKİ DERLEMELER" listesindeki olayları, üzerine yeni ve somut
          bir gelişme yoksa yeniden seçme.
        - Yayın politikasına takılacak olayları (yargı süreci süren
          suçlamalar, siyasi polemik, doğrulanmamış iddia) SEÇME.
        - Magazin ve tık tuzağı haberleri seçme.

        Her olay için: kısa bir olay etiketi, dayandığı haber numaraları,
        derlemenin açısı (okuyucuya ne anlatılacak, hangi soruları
        cevaplayacak; 1-2 cümle), bir başlık önerisi ve konu (istemdeki
        KONULAR listesinden biri) yaz.
        METIN;

    private const YAZI_YONERGE = self::ORTAK . <<<'METIN'


        Görevin: verilen olay için bir DERLEME HABER yazmak. Malzeme olarak
        olayı veren yayınların haberleri ve varsa kaynak sayfaların metni
        verilecek. Kaynak yabancı dildeyse de haber Türkçedir.

        DERLEMENİN DEĞERİ — her derlemede şunlar bulunur:
        - Bütün kaynakların bilgisini birleştir: bir kaynakta olup
          diğerinde olmayan ayrıntıları da ekle. Kaynaklar arasında rakam
          ya da ifade farkı varsa bunu açıkça belirt ve kimin ne dediğini
          adıyla yaz ("Anadolu Ajansı 120, Reuters 115 kişi olarak
          aktardı").
        - Arka plan: olayın nasıl bu noktaya geldiği; malzemede varsa
          önceki adımlar ve tarihleri.
        - Ne anlama geliyor: vatandaş, çalışan, emekli, şirket ya da
          ülke için somut etkisi; mekanizmasıyla (neden, hangi yoldan).
          Değerlendirme olduğunu belli et; kesin sonuç gibi sunma.
        - Varsa resmî dayanak: kanun, yönetmelik, Resmî Gazete, kurum
          duyurusu; adı ve tarihiyle.
        - Sıkça sorulan sorular: okuyucunun aklına gelecek 3-5 soru ve
          kısa cevapları (kimleri kapsıyor, ne zaman başlıyor, ne
          yapılmalı...). Yalnızca malzemeden cevaplanabilen sorular;
          cevabı malzemede yoksa o soruyu sorma.

        BİÇİM
        - 450-900 kelime, Türkçe.
        - İlk paragraf: ne oldu; en önemli bilgi iki-üç cümlede.
        - Ardından ara başlıklar. Ara başlık satırı "## " ile başlar ve tek
          satırdır. Konuya uygun olanları seç; örnek: "## Neler biliniyor",
          "## Arka plan", "## Kaynaklar ne diyor", "## Ne anlama geliyor",
          "## Bundan sonra ne olacak". Son bölüm her zaman
          "## Sıkça sorulan sorular": her soru-cevap tek satırda ve "- "
          ile başlar ("- Kimleri kapsıyor? ..."). Soru-cevap
          çıkmıyorsa bu bölümü yazma.
        - Paragraflar boş satırla ayrılır. Madde listesinde her satır
          "- " ile başlar. Başka biçimlendirme YOK: kalın, italik,
          bağlantı, tablo, emoji kullanma.

        ÜSLUP
        - Haber dili: açık, sakin, kurumsal. Abartı ve tık tuzağı yok
          ("şok", "flaş", "bomba", "skandal" gibi).
        - Olgu ile değerlendirmeyi ayır. Yatırım tavsiyesi verme.

        EN ÖNEMLİ KURAL — UYDURMA.
        - Rakam, oran, tarih, isim ve alıntı YALNIZCA malzemede geçiyorsa
          yazılır. Malzemede olmayan bir ayrıntıyı tahminle tamamlama.
        - Bir şey henüz belli değilse "henüz belli değil" de.
        - Kaynaklardan cümle KOPYALAMA ve tek bir kaynağın akışını
          izleme; olayı kendi kurgunla, kendi cümlelerinle anlat.
          Kaynaklara adıyla atıf yap.

        Ayrıca:
        - neden_onemli: olayın okuyucu için anlamı, 2-3 cümle; metindeki
          "Ne anlama geliyor" bölümünü tekrar etme, özünü ver.
        - etiketler: 3-6 etiket, virgülle.
        - resmi_dayanak: malzemede kanun, yönetmelik ya da Resmî Gazete
          bilgisi varsa kısaca (ad, tarih, sayı); yoksa boş.
        - editor_notu: onaylayacak editör için doğrulanması gereken
          noktalar, kaynaklar arasındaki çelişkiler ve hassas yönler.
        METIN;

    /** @var array<string,mixed> */
    private const SECIM_SEMA = [
        'type' => 'object',
        'properties' => [
            'sonuclar' => [
                'type'  => 'array',
                'description' => 'Seçilen olaylar, önem sırasıyla',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'sira'     => ['type' => 'integer', 'description' => 'Olayın sıra numarası, 1den başlar'],
                        'olay'     => ['type' => 'string',  'description' => 'Kısa olay etiketi, en fazla 80 karakter'],
                        'haberler' => [
                            'type'  => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'Olayı veren HABER numaraları (istemdeki numaralar), en az iki farklı yayından',
                        ],
                        'aci'      => ['type' => 'string',  'description' => 'Derlemenin açısı; 1-2 cümle'],
                        'baslik_onerisi' => ['type' => 'string', 'description' => 'Başlık önerisi'],
                        'kategori' => ['type' => 'string',  'description' => 'KONULAR listesinden biri'],
                    ],
                    'required' => ['sira', 'olay', 'haberler', 'aci', 'baslik_onerisi', 'kategori'],
                ],
            ],
        ],
        'required' => ['sonuclar'],
    ];

    /** @var array<string,mixed> */
    private const YAZI_SEMA = [
        'type' => 'object',
        'properties' => [
            'sonuclar' => [
                'type'  => 'array',
                'description' => 'Tek eleman: derleme haber',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'sira'          => ['type' => 'integer', 'description' => 'Her zaman 1'],
                        'baslik'        => ['type' => 'string',  'description' => 'Haberin başlığı, en fazla 100 karakter; bilgi veren, tık tuzağı olmayan'],
                        'ozet'          => ['type' => 'string',  'description' => 'Spot: haberin özü, 1-2 cümle, en fazla 240 karakter'],
                        'icerik'        => ['type' => 'string',  'description' => '450-900 kelime; paragraflar boş satırla, ara başlıklar "## " ile'],
                        'neden_onemli'  => ['type' => 'string',  'description' => 'Okuyucu için anlamı, 2-3 cümle'],
                        'etiketler'     => ['type' => 'string',  'description' => '3-6 etiket, virgülle'],
                        'resmi_dayanak' => ['type' => 'string',  'description' => 'Resmî dayanak; yoksa boş'],
                        'editor_notu'   => ['type' => 'string',  'description' => 'Editör için doğrulanacak noktalar'],
                    ],
                    'required' => ['sira', 'baslik', 'ozet', 'icerik', 'neden_onemli', 'etiketler', 'resmi_dayanak', 'editor_notu'],
                ],
            ],
        ],
        'required' => ['sonuclar'],
    ];

    public function __construct(private readonly SemaliIstemci $istemci)
    {
    }

    /**
     * Derlenecek olayları seçer.
     *
     * Donen her olayin 'haberler' alani malzemedeki haberlerin DIZI
     * INDISLERI (0 tabanli). Malzemede olmayan numaralar atiliyor, ayni
     * haber iki olaya verilmiyor ve iki farkli yayina dayanmayan olay
     * dusuyor (model bu kurali kacirabilir; site de ayrica denetliyor).
     *
     * @param list<array<string,mixed>> $haberler
     * @param list<array<string,mixed>> $onceki
     * @param list<string> $kategoriler
     * @return list<array{olay:string,haberler:list<int>,aci:string,baslik_onerisi:string,kategori:string}>
     */
    public function olaySec(array $haberler, array $onceki, array $kategoriler, int $enFazla): array
    {
        if (count($haberler) < self::ASGARI_YAYIN || $enFazla < 1) {
            return [];
        }

        $sonuclar = $this->istemci->semaliIstek(
            self::SECIM_YONERGE,
            $this->secimIstemi($haberler, $onceki, $kategoriler, $enFazla),
            self::SECIM_SEMA,
            8000,
            0,
            'haberler'
        );

        ksort($sonuclar);

        $olaylar    = [];
        $kullanilan = [];

        foreach ($sonuclar as $sonuc) {
            $indisler = [];
            $yayinlar = [];

            foreach ((array) ($sonuc['haberler'] ?? []) as $no) {
                $indis = (int) $no - 1;

                if (isset($haberler[$indis]) && !isset($kullanilan[$indis])) {
                    $indisler[] = $indis;
                    $yayinlar[mb_strtolower(self::yayin($haberler[$indis]), 'UTF-8')] = true;
                }
            }

            $olay = trim((string) ($sonuc['olay'] ?? ''));

            if ($olay === '' || count($yayinlar) < self::ASGARI_YAYIN) {
                continue;
            }

            foreach ($indisler as $indis) {
                $kullanilan[$indis] = true;
            }

            $kategori = trim((string) ($sonuc['kategori'] ?? ''));

            $olaylar[] = [
                'olay'           => mb_substr($olay, 0, 200, 'UTF-8'),
                'haberler'       => $indisler,
                'aci'            => trim((string) ($sonuc['aci'] ?? '')),
                'baslik_onerisi' => trim((string) ($sonuc['baslik_onerisi'] ?? '')),
                'kategori'       => in_array($kategori, $kategoriler, true) ? $kategori : '',
            ];

            if (count($olaylar) >= $enFazla) {
                break;
            }
        }

        return $olaylar;
    }

    /**
     * Bir olay için derleme haberi yazar. Geçerli metin çıkmazsa null.
     *
     * @param array{olay:string,haberler:list<int>,aci:string,baslik_onerisi:string,kategori:string} $olay
     * @param list<array<string,mixed>> $haberler
     * @param array<int,string> $kaynakMetinleri indis => kaynak sayfa metni
     * @return array{baslik:string,ozet:string,icerik:string,neden_onemli:string,etiketler:string,resmi_dayanak:string,editor_notu:string}|null
     */
    public function yaz(array $olay, array $haberler, array $kaynakMetinleri = []): ?array
    {
        $sonuclar = $this->istemci->semaliIstek(
            self::YAZI_YONERGE,
            $this->yaziIstemi($olay, $haberler, $kaynakMetinleri),
            self::YAZI_SEMA,
            20000,
            1,
            'icerik'
        );

        $yazi = $sonuclar[0] ?? null;

        if (!is_array($yazi)) {
            return null;
        }

        $baslik = trim((string) ($yazi['baslik'] ?? ''));
        $icerik = KoseYazari::metniDuzelt((string) ($yazi['icerik'] ?? ''));

        if (mb_strlen($baslik, 'UTF-8') < 10 || mb_strlen($icerik, 'UTF-8') < self::ASGARI_METIN) {
            return null;
        }

        $kirp = static fn (string $alan, int $sinir): string => mb_substr(trim((string) ($yazi[$alan] ?? '')), 0, $sinir, 'UTF-8');

        return [
            'baslik'        => mb_substr($baslik, 0, 300, 'UTF-8'),
            'ozet'          => $kirp('ozet', 600),
            'icerik'        => $icerik,
            'neden_onemli'  => $kirp('neden_onemli', 800),
            'etiketler'     => $kirp('etiketler', 400),
            'resmi_dayanak' => $kirp('resmi_dayanak', 400),
            'editor_notu'   => $kirp('editor_notu', 600),
        ];
    }

    /** @param array<string,mixed> $h */
    private static function yayin(array $h): string
    {
        $yayin = trim((string) ($h['yayin'] ?? ''));

        return $yayin !== '' ? $yayin : trim((string) ($h['kaynak_adi'] ?? ''));
    }

    /**
     * @param list<array<string,mixed>> $haberler
     * @param list<array<string,mixed>> $onceki
     * @param list<string> $kategoriler
     */
    private function secimIstemi(array $haberler, array $onceki, array $kategoriler, int $enFazla): string
    {
        $bloklar = [];

        foreach ($haberler as $i => $h) {
            $ozet = mb_substr(trim((string) ($h['ozet'] ?? '')), 0, self::SECIM_OZET_SINIRI, 'UTF-8');
            $kat  = trim((string) ($h['kategori_slug'] ?? ''));

            $bloklar[] = 'HABER ' . ($i + 1) . " — {$h['baslik']}\n"
                       . '  Yayın: ' . self::yayin($h) . ' · Konu: ' . ($kat !== '' ? $kat : '-')
                       . ' · Önem: ' . (int) ($h['onem'] ?? 50) . ' · Tarih: ' . ($h['tarih'] ?? '-') . "\n"
                       . "  Özet: {$ozet}";
        }

        $oncekiBlok = $onceki === []
            ? '(yok)'
            : implode("\n", array_map(
                static fn (array $o): string => '- ' . ($o['tarih'] ?? '') . ' · ' . ($o['baslik'] ?? ''),
                array_slice($onceki, 0, 60)
            ));

        return "EN FAZLA {$enFazla} olay seç.\n\n"
             . 'KONULAR: ' . implode(', ', $kategoriler) . "\n\n"
             . "ÖNCEKİ DERLEMELER (son günler):\n{$oncekiBlok}\n\n"
             . 'HABERLER (' . count($haberler) . " adet; haberler alanına bu numaraları yaz):\n\n"
             . implode("\n\n", $bloklar);
    }

    /**
     * @param array{olay:string,haberler:list<int>,aci:string,baslik_onerisi:string,kategori:string} $olay
     * @param list<array<string,mixed>> $haberler
     * @param array<int,string> $kaynakMetinleri
     */
    private function yaziIstemi(array $olay, array $haberler, array $kaynakMetinleri): string
    {
        $bloklar = [];

        foreach ($olay['haberler'] as $sira => $indis) {
            $h      = $haberler[$indis];
            $metin  = mb_substr(trim((string) ($h['icerik'] ?? '')), 0, self::HABER_METNI_SINIRI, 'UTF-8');
            $analiz = [];

            foreach ([
                'analiz_degisen'   => 'Ne değişti',
                'analiz_etkilenen' => 'Kimleri etkiliyor',
                'analiz_zaman'     => 'Ne zaman',
                'analiz_islem'     => 'Hangi işlem',
                'resmi_dayanak'    => 'Resmî dayanak',
            ] as $alan => $ad) {
                $deger = trim((string) ($h[$alan] ?? ''));

                if ($deger !== '') {
                    $analiz[] = "  {$ad}: {$deger}";
                }
            }

            $blok = '### MALZEME ' . ($sira + 1) . "\n"
                  . "Başlık: {$h['baslik']}\n"
                  . 'Yayın: ' . self::yayin($h) . ' — ' . ($h['kaynak_url'] ?? '') . "\n"
                  . 'Tarih: ' . ($h['tarih'] ?? '-') . "\n"
                  . ($analiz !== [] ? "Analiz:\n" . implode("\n", $analiz) . "\n" : '')
                  . "Haber metni:\n---\n{$metin}\n---";

            $kaynak = trim($kaynakMetinleri[$indis] ?? '');

            if ($kaynak !== '') {
                $blok .= "\nKaynak sayfanın metni:\n---\n"
                       . mb_substr($kaynak, 0, self::KAYNAK_METNI_SINIRI, 'UTF-8') . "\n---";
            }

            $bloklar[] = $blok;
        }

        return "OLAY: {$olay['olay']}\n"
             . "AÇI: {$olay['aci']}\n"
             . "BAŞLIK ÖNERİSİ (değiştirebilirsin): {$olay['baslik_onerisi']}\n\n"
             . "Yanıtta sonuclar dizisinde TEK sonuç döndür, sira alanı 1 olsun.\n\n"
             . implode("\n\n", $bloklar);
    }
}
