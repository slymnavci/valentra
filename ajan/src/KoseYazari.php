<?php
declare(strict_types=1);

namespace Valentra\Ajan;

require_once __DIR__ . '/SemaliIstemci.php';

/**
 * "Valentra Diyor ki…" köşe yazılarını yazar.
 *
 * Iki adim:
 *   1. gundemSec(): son saatlerin en onemli haberlerinden (onem puani)
 *      3-4 gundem maddesi secer; en az biri dunya gundeminden.
 *      Ayni olayi anlatan birden cok haber tek maddede toplaniyor.
 *   2. yaz(): her madde icin ayri bir yazi yazar; malzeme o maddenin
 *      haberleri ve (alinabildiyse) kaynak sayfalarin metni.
 *
 * Model istemciye degil SemaliIstemci arayuzune bagli: istemdeki haber
 * numarasiyla yanittaki numaranin eslesmesi modele gitmeden sinanabiliyor
 * (bkz. DegerOkuyucu'daki ayni gerekce).
 *
 * YAZI UYDURMAZ. Rakam, tarih, kanun maddesi yalnizca malzemede geciyorsa
 * yaziliyor; bilinmeyen sey "henuz belli degil" diye soyleniyor. Yazilar
 * ayrica panelde onaydan geciyor.
 */
final class KoseYazari
{
    /** Seçim isteminde haber başına özet sınırı (karakter). */
    private const SECIM_OZET_SINIRI = 400;

    /** Yazı isteminde haber metni sınırı (karakter). */
    private const HABER_METNI_SINIRI = 6000;

    /** Yazı isteminde kaynak sayfa metni sınırı (karakter). */
    private const KAYNAK_METNI_SINIRI = 8000;

    /** Kabul edilecek en kısa yazı (karakter); sitedeki sınırla aynı. */
    public const ASGARI_METIN = 800;

    /*
     * Ekim 2026'da genel habere uyarlandi (site sahibinin istegi): kose
     * yazilari gunun EN ONEMLI haberlerinden (onem puani) seciliyor, en
     * az biri dunya gundeminden. Cizgi sitenin yayin politikasiyla ayni:
     * taraf tutmayan, dogrulanmamis iddiaya ve suclamaya dayanmayan,
     * cozumleyici yorum. Yazilar onaydan geciyor.
     */
    private const ORTAK = <<<'METIN'
        Valentra, Türkiye'den ve dünyadan güncel haberleri tarafsız
        özetler halinde yayımlayan bir haber sitesidir. Sitenin köşe
        yazarları her gün kendi alanlarında gündemin bir konusunu
        değerlendirir: ne oldu, neden önemli, arka planı ne, bundan sonra
        ne olabilir. Her yazarın kendi alanı ve üslubu vardır; istemde
        "KÖŞE YAZARI" bölümü verildiyse ona uy.

        YAYIN POLİTİKASI (her yazı için geçerli, tartışmasız):
        - Siyasi taraf tutulmaz. Hiçbir partiyi, siyasetçiyi, hükümeti ya
          da muhalefeti övmez, yermez, hedef göstermez. Siyasi konularda
          değerlendirme, kararın içeriği, süreci ve olası etkileri
          üzerinedir; kişiler ve partiler hakkında hüküm verilmez.
        - Doğrulanmamış iddia, söylenti ve isimsiz kaynak malzeme olarak
          kullanılmaz.
        - Kişi ya da kurumlara suç isnat edilmez; yargı süreci sürüyorsa
          sonuç önceden ilan edilmez (masumiyet karinesi).
        - Devlet kurumları, güvenlik güçleri ve yargı hakkında dayanaksız
          olumsuz ifade kullanılmaz.
        - Hakaret, aşağılama, kışkırtıcı ve kutuplaştırıcı dil yoktur.
        - Yayın yasağı olan konulara ve terörle ilgili propaganda
          niteliğindeki içeriğe girilmez.
        METIN;

    private const SECIM_YONERGE = self::ORTAK . <<<'METIN'


        Görevin: aşağıda numaralı verilen haberlerden bugünün köşe
        yazılarının konularını, yani GÜNDEM MADDELERİNİ seçmek.

        - 3 ya da 4 madde seç. Gündem gerçekten zayıfsa 2 madde de olur;
          zorlama konu seçme. İstemde "EN FAZLA" sayısı verilmişse onu
          aşma.
        - EN ÖNEMLİ HABERLERDEN SEÇ. Her haberin "Önem" puanı (0-100)
          verilmiştir ve haberler bu puana göre sıralıdır. Öncelik puanı
          yüksek haberlerdedir; düşük puanlı bir haberi ancak gündemde
          ondan önemli bir konu kalmadıysa seç.
        - EN AZ BİR MADDE DÜNYA GÜNDEMİNDEN olsun (konusu "Dünya" olan
          haberler): uluslararası bir gelişmeyi yorumlayan, Türkiye'ye ve
          dünyaya olası etkilerini anlatan bir yazı. Listede dünya haberi
          varsa bu kural zorunludur.
        - Konular çeşitli olsun: maddelerin hepsi aynı başlıktan
          (yalnızca politika ya da yalnızca ekonomi) olmasın.
        - Bir madde tek bir olay ya da tek bir konudur. Aynı olayı
          anlatan birden çok haber varsa hepsini o maddenin haberleri
          olarak ver; aynı olayı iki ayrı madde yapma.
        - "ÖNCEKİ YAZILAR" listesindeki konuları, üzerine YENİ ve somut
          bir gelişme yoksa yeniden seçme.
        - Yayın politikasına takılacak konuları (yargı süreci süren
          suçlamalar, siyasi polemik, doğrulanmamış iddia) SEÇME.
        - Magazin ya da tek bir maçın sonucu gibi yoruma alan bırakmayan
          haberleri tek başına konu yapma.

        Her madde için: kısa bir gündem etiketi, dayandığı haber
        numaraları, yazının açısı (neden önemli, okuyucuya ne
        anlatılacak; 1-2 cümle) ve bir başlık önerisi yaz.
        METIN;

    private const YAZI_YONERGE = self::ORTAK . <<<'METIN'


        Görevin: verilen gündem maddesi için bir köşe yazısı yazmak.
        Malzeme olarak o maddenin haberleri ve varsa kaynak sayfaların
        metni verilecek. Kaynak yabancı dildeyse de yazı Türkçedir.

        BİÇİM
        - 600-1000 kelime, Türkçe.
        - İlk paragraf: ne oldu ve neden önemli; iki-üç cümlede.
        - Ardından 2-4 ara başlık. Ara başlık satırı "## " ile başlar ve
          tek satırdır. Konuya uygun olanları seç: arka plan, rakamlarla
          tablo, tarafların açıkladığı tutumlar, Türkiye'ye etkisi,
          dünyaya ve bölgeye etkisi, ekonomiye olası etkileri, bundan
          sonra izlenecekler.
        - Paragraflar boş satırla ayrılır. Madde listesi gerekiyorsa her
          satır "- " ile başlar. Başka biçimlendirme YOK: kalın, italik,
          bağlantı, tablo, emoji kullanma.
        - Son paragraf kısa bir değerlendirme: önümüzdeki günlerde neye
          bakılmalı.

        YORUM — bu köşe haber özeti DEĞİLDİR, ama taraf da tutmaz.
        Her yazıda şunlar bulunur:
        - Bağlam: olayın arka planı, nasıl bu noktaya gelindiği.
        - Çözümleme: gelişmenin anlamı ve olası etkileri. Etkiler için
          mekanizmayı anlat (neden, hangi yoldan) ve mümkünse birden
          fazla senaryoyu göster. Değerlendirme olduğunu belli et
          ("değerlendirmemize göre", "bu gelişme ... anlamına
          gelebilir"); kesin sonuç gibi sunma.
        - Taraflar: bir konuda farklı tarafların açıklanmış tutumları
          varsa, hiçbirini öne çıkarmadan ve adlarıyla atıf yaparak
          aktar. Hangi tarafın haklı olduğuna hüküm verme.
        - Dünya konularında: gelişmenin Türkiye'ye ve bölgeye olası
          yansımalarını ayrıca ele al.

        ÜSLUP
        - Kurumsal, sakin ve anlaşılır. Abartı, heyecan ve tık tuzağı yok
          ("şok", "flaş", "bomba", "skandal" gibi).
        - Olgu ile değerlendirmeyi ayır.
        - Yatırım tavsiyesi verme ("alın", "satın" deme).

        EN ÖNEMLİ KURAL — UYDURMA.
        - Rakam, oran, tarih, isim ve alıntı YALNIZCA malzemede geçiyorsa
          yazılır. Malzemede olmayan bir ayrıntıyı tahminle tamamlama.
          Kendi sayısal tahminini üretme; beklenti rakamı ancak
          malzemedeki bir kaynağa dayanıyorsa ve o kaynağın adıyla
          yazılır.
        - Bir şey henüz belli değilse bunu açıkça "henüz belli değil"
          diye söyle.
        - Kaynaklardan cümle kopyalama; kendi cümlelerinle yaz. Kaynağa
          adıyla atıf yapabilirsin ("Anadolu Ajansı'nın aktardığına
          göre…", "BBC'nin haberine göre…").

        editor_notu alanına, yazıyı onaylayacak editör için doğrulanması
        gereken noktaları ve varsa hassas yönleri (siyasi, hukuki)
        kısaca yaz.
        METIN;

    /** @var array<string,mixed> */
    private const SECIM_SEMA = [
        'type' => 'object',
        'properties' => [
            'sonuclar' => [
                'type'  => 'array',
                'description' => 'Seçilen gündem maddeleri, önem sırasıyla',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'sira'     => ['type' => 'integer', 'description' => 'Maddenin sıra numarası, 1den başlar'],
                        'gundem'   => ['type' => 'string',  'description' => 'Kısa gündem etiketi, en fazla 60 karakter'],
                        'haberler' => [
                            'type'  => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'Maddenin dayandığı HABER numaraları (istemdeki numaralar)',
                        ],
                        'aci'      => ['type' => 'string',  'description' => 'Yazının açısı: neden önemli, ne anlatılacak; 1-2 cümle'],
                        'baslik_onerisi' => ['type' => 'string', 'description' => 'Yazı için başlık önerisi'],
                    ],
                    'required' => ['sira', 'gundem', 'haberler', 'aci', 'baslik_onerisi'],
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
                'description' => 'Tek eleman: yazı',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'sira'        => ['type' => 'integer', 'description' => 'Her zaman 1'],
                        'baslik'      => ['type' => 'string',  'description' => 'Yazının başlığı, en fazla 90 karakter'],
                        'ozet'        => ['type' => 'string',  'description' => 'Yazının özü, 1-2 cümle, en fazla 220 karakter'],
                        'icerik'      => ['type' => 'string',  'description' => '600-1000 kelime; paragraflar boş satırla ayrılmış, ara başlıklar "## " ile'],
                        'editor_notu' => ['type' => 'string',  'description' => 'Onaylayacak editör için doğrulanacak noktalar'],
                    ],
                    'required' => ['sira', 'baslik', 'ozet', 'icerik', 'editor_notu'],
                ],
            ],
        ],
        'required' => ['sonuclar'],
    ];

    public function __construct(private readonly SemaliIstemci $istemci)
    {
    }

    /**
     * Gündem maddelerini seçer.
     *
     * Donen her maddenin 'haberler' alani malzemedeki haberlerin DIZI
     * INDISLERI (0 tabanli); modelin yazdigi numaralar burada cevriliyor
     * ve malzemede olmayan numaralar atiliyor. Haberi kalmayan madde
     * dusuyor.
     *
     * @param list<array<string,mixed>> $haberler Sitenin gönderdiği malzeme
     * @param list<array<string,mixed>> $onceki   Son günlerin yazıları
     * @return list<array{gundem:string,haberler:list<int>,aci:string,baslik_onerisi:string}>
     */
    public function gundemSec(array $haberler, array $onceki, int $enFazla, array $kalem = []): array
    {
        if ($haberler === [] || $enFazla < 1) {
            return [];
        }

        $sonuclar = $this->istemci->semaliIstek(
            self::SECIM_YONERGE,
            self::kalemBlogu($kalem, true) . $this->secimIstemi($haberler, $onceki, $enFazla),
            self::SECIM_SEMA,
            8000,
            0,
            'haberler'
        );

        ksort($sonuclar);

        $maddeler   = [];
        $kullanilan = [];

        foreach ($sonuclar as $sonuc) {
            $indisler = [];

            foreach ((array) ($sonuc['haberler'] ?? []) as $no) {
                $indis = (int) $no - 1;

                /*
                 * Ayni haber iki maddeye verilmesin: ayni olay iki yazi
                 * olmasin diye. Once gelen (daha onemli) madde alir.
                 */
                if (isset($haberler[$indis]) && !isset($kullanilan[$indis])) {
                    $indisler[]          = $indis;
                    $kullanilan[$indis]  = true;
                }
            }

            $gundem = trim((string) ($sonuc['gundem'] ?? ''));

            if ($indisler === [] || $gundem === '') {
                continue;
            }

            $maddeler[] = [
                'gundem'         => mb_substr($gundem, 0, 200, 'UTF-8'),
                'haberler'       => $indisler,
                'aci'            => trim((string) ($sonuc['aci'] ?? '')),
                'baslik_onerisi' => trim((string) ($sonuc['baslik_onerisi'] ?? '')),
            ];

            if (count($maddeler) >= $enFazla) {
                break;
            }
        }

        return $maddeler;
    }

    /**
     * Bir gündem maddesi için yazıyı yazar. Geçerli yazı çıkmazsa null.
     *
     * @param array{gundem:string,haberler:list<int>,aci:string,baslik_onerisi:string} $madde
     * @param list<array<string,mixed>> $haberler Tüm malzeme
     * @param array<int,string> $kaynakMetinleri  indis => kaynak sayfa metni
     * @return array{baslik:string,ozet:string,icerik:string,editor_notu:string}|null
     */
    public function yaz(array $madde, array $haberler, array $kaynakMetinleri = [], array $kalem = []): ?array
    {
        $sonuclar = $this->istemci->semaliIstek(
            self::YAZI_YONERGE,
            self::kalemBlogu($kalem, false) . $this->yaziIstemi($madde, $haberler, $kaynakMetinleri),
            self::YAZI_SEMA,
            24000,
            1,
            'icerik'
        );

        $yazi = $sonuclar[0] ?? null;

        if (!is_array($yazi)) {
            return null;
        }

        $baslik = trim((string) ($yazi['baslik'] ?? ''));
        $icerik = self::metniDuzelt((string) ($yazi['icerik'] ?? ''));

        if (mb_strlen($baslik, 'UTF-8') < 10 || mb_strlen($icerik, 'UTF-8') < self::ASGARI_METIN) {
            return null;
        }

        return [
            'baslik'      => mb_substr($baslik, 0, 300, 'UTF-8'),
            'ozet'        => mb_substr(trim((string) ($yazi['ozet'] ?? '')), 0, 600, 'UTF-8'),
            'icerik'      => $icerik,
            'editor_notu' => mb_substr(trim((string) ($yazi['editor_notu'] ?? '')), 0, 600, 'UTF-8'),
        ];
    }

    /**
     * Model çıktısını sitenin biçimine uydurur.
     *
     * Istenmese de ara sira gelen kalin yazi yildizlari ve tek satir
     * sonlari temizleniyor; "### " ara basligi "## " yapiliyor.
     */
    public static function metniDuzelt(string $metin): string
    {
        $metin = str_replace(["\r\n", "\r"], "\n", $metin);
        $metin = (string) preg_replace('/\*\*(.+?)\*\*/u', '$1', $metin);
        $metin = (string) preg_replace('/^#{1,6}\s+/mu', '## ', $metin);

        /*
         * Ara baslik satirinin hemen altinda bos satir yoksa ekle: site
         * bloklari bos satirla ayiriyor, yoksa baslik paragrafa yapisir.
         */
        $metin = (string) preg_replace('/^(## .+)\n(?!\n)/mu', "$1\n\n", $metin);
        $metin = (string) preg_replace("/\n{3,}/u", "\n\n", $metin);

        return trim($metin);
    }

    /**
     * @param list<array<string,mixed>> $haberler
     * @param list<array<string,mixed>> $onceki
     */
    /**
     * Köşe yazarının istem bloğu (bos dizi: blok yok, eski davranis).
     *
     * Secimde: yazar yalnizca kendi alanindan ve kendi onceki yazilarini
     * tekrarlamadan TEK konu secer. Yazida: yazarin uslubu; yazi yazarin
     * kaleminden, ama yayin politikasi ve uydurmama kurali aynen.
     *
     * @param array{ad?:string,alan?:string,uslup?:string} $kalem
     */
    private static function kalemBlogu(array $kalem, bool $secim): string
    {
        if (($kalem['ad'] ?? '') === '') {
            return '';
        }

        $blok = "KÖŞE YAZARI: {$kalem['ad']}
"
              . 'Alanı: ' . ($kalem['alan'] ?? '-') . "
"
              . 'Üslubu: ' . ($kalem['uslup'] ?? '-') . "
";

        $blok .= $secim
            ? "Bu yazarın bugünkü yazısı için, aşağıdaki haberlerden YALNIZCA bu yazarın alanına giren TEK bir konu seç. "
              . "Yazarın son günlerde yazdığı konuyu (ÖNCEKİ YAZILAR'da yazar adıyla işaretli) yeni bir gelişme yoksa yeniden seçme. "
              . "Dünya gündemi kuralı bu seçimde geçerli değil; çeşitlilik kuralı da (tek konu).

"
            : "Yazıyı bu yazarın kaleminden, yukarıdaki üslupla yaz. Yazıda yazarın adını ya da kendini tanıtan bir cümle kullanma; "
              . "birinci tekil şahıs ölçülü kullanılabilir (\"bence\" yerine \"değerlendirmeme göre\"). Yayın politikası ve "
              . "UYDURMA kuralı aynen geçerli.

";

        return $blok;
    }

    private function secimIstemi(array $haberler, array $onceki, int $enFazla): string
    {
        $bloklar = [];

        foreach ($haberler as $i => $h) {
            $no    = $i + 1;
            $ozet  = mb_substr(trim((string) ($h['ozet'] ?? '')), 0, self::SECIM_OZET_SINIRI, 'UTF-8');
            $deg   = trim((string) ($h['analiz_degisen'] ?? ''));
            $kat   = trim((string) ($h['kategori'] ?? ''));
            $satir = "HABER {$no} — {$h['baslik']}\n"
                   . '  Konu: ' . ($kat !== '' ? $kat : '-') . ' · Önem: ' . (int) ($h['onem'] ?? 50)
                   . ' · Kaynak: ' . ($h['kaynak_adi'] ?? '-')
                   . ' · Tarih: ' . ($h['tarih'] ?? '-') . "\n"
                   . "  Özet: {$ozet}";

            if ($deg !== '') {
                $satir .= "\n  Ne değişti: " . mb_substr($deg, 0, 300, 'UTF-8');
            }

            $bloklar[] = $satir;
        }

        $oncekiBlok = '(yok)';

        if ($onceki !== []) {
            $satirlar = [];

            foreach (array_slice($onceki, 0, 40) as $y) {
                $satirlar[] = '- ' . ($y['gun'] ?? '') . ' · ' . ($y['gundem'] ?? '') . ' · ' . ($y['baslik'] ?? '')
                    . (($y['yazar_adi'] ?? '') !== '' ? ' · yazar: ' . $y['yazar_adi'] : '');
            }

            $oncekiBlok = implode("\n", $satirlar);
        }

        $adet = count($haberler);

        return "EN FAZLA {$enFazla} madde seç.\n\n"
             . "ÖNCEKİ YAZILAR (son günler):\n{$oncekiBlok}\n\n"
             . "HABERLER ({$adet} adet; haberler alanına bu numaraları yaz):\n\n"
             . implode("\n\n", $bloklar);
    }

    /**
     * @param array{gundem:string,haberler:list<int>,aci:string,baslik_onerisi:string} $madde
     * @param list<array<string,mixed>> $haberler
     * @param array<int,string> $kaynakMetinleri
     */
    private function yaziIstemi(array $madde, array $haberler, array $kaynakMetinleri): string
    {
        $bloklar = [];

        foreach ($madde['haberler'] as $sira => $indis) {
            $h = $haberler[$indis];
            $analiz = [];

            foreach ([
                'analiz_degisen'   => 'Ne değişti',
                'analiz_etkilenen' => 'Kimleri etkiliyor',
                'analiz_zaman'     => 'Ne zaman',
                'analiz_islem'     => 'Hangi işlem',
            ] as $alan => $ad) {
                $deger = trim((string) ($h[$alan] ?? ''));

                if ($deger !== '') {
                    $analiz[] = "  {$ad}: {$deger}";
                }
            }

            $metin = mb_substr(trim((string) ($h['icerik'] ?? '')), 0, self::HABER_METNI_SINIRI, 'UTF-8');
            $blok  = '### MALZEME ' . ($sira + 1) . "\n"
                   . "Başlık: {$h['baslik']}\n"
                   . 'Kaynak: ' . ($h['kaynak_adi'] ?? '-') . ' — ' . ($h['kaynak_url'] ?? '') . "\n"
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

        return "GÜNDEM MADDESİ: {$madde['gundem']}\n"
             . "AÇI: {$madde['aci']}\n"
             . "BAŞLIK ÖNERİSİ (değiştirebilirsin): {$madde['baslik_onerisi']}\n\n"
             . "Yanıtta sonuclar dizisinde TEK sonuç döndür, sira alanı 1 olsun.\n\n"
             . implode("\n\n", $bloklar);
    }
}
