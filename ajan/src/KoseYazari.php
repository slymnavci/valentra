<?php
declare(strict_types=1);

namespace Valentra\Ajan;

require_once __DIR__ . '/SemaliIstemci.php';

/**
 * "Valentra Diyor ki…" köşe yazılarını yazar.
 *
 * Iki adim:
 *   1. gundemSec(): son saatlerin haberlerinden 3-4 gundem maddesi secer.
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

    private const ORTAK = <<<'METIN'
        Valentra vergi, muhasebe ve finans alanında yayın yapan bir
        haber ve analiz sitesidir. Okuyucuları mali müşavirler, muhasebeciler,
        şirketlerin mali işler birimleri, işletme sahipleri ve
        mükelleflerdir. Sitenin "Valentra Diyor ki…" köşesinde her gün
        gündemin en önemli 3-4 konusu ayrı ayrı yazılarla ele alınır.
        METIN;

    private const SECIM_YONERGE = self::ORTAK . <<<'METIN'


        Görevin: aşağıda numaralı verilen haberlerden bugünün köşe
        yazılarının konularını, yani GÜNDEM MADDELERİNİ seçmek.

        - 3 ya da 4 madde seç. Gündem gerçekten zayıfsa 2 madde de olur;
          zorlama konu seçme. İstemde "EN FAZLA" sayısı verilmişse onu
          aşma.
        - Bir madde tek bir olay ya da tek bir konudur. Aynı olayı
          anlatan birden çok haber varsa hepsini o maddenin haberleri
          olarak ver; aynı olayı iki ayrı madde yapma.
        - Bir EKONOMİ SAYFASI gözüyle seç; köşe yalnızca vergi ve
          muhasebe köşesi değildir. Günün en çok konuşulan ve en geniş
          etkili gelişmelerini ara:
          - hükümetin ekonomi politikası: alınan ya da planlanan
            önlemler (enflasyonla mücadele, tasarruf, teşvik) ve olası
            etkileri;
          - enflasyon, faiz ve kur: son veriler, beklentiler, piyasanın
            ne beklediği;
          - piyasalar: borsa, döviz, altın, petrol ve emtia;
          - dünya ekonomisi: Fed ve ECB, ABD-Çin ilişkileri, enerji
            fiyatları ve Türkiye'ye yansımaları;
          - şirketler ve yatırımcılar: büyük şirketlerin durumu,
            yatırımcıları geniş ölçekte etkileyen olaylar;
          - vergi ve mevzuat: mükellefe doğrudan etkisi olan önemli bir
            düzenleme varsa.
        - Konular çeşitli olsun: dört maddenin dördü aynı temadan olmasın.
          Gündem elveriyorsa en az biri piyasalar ya da dünya
          ekonomisinden, en az biri ekonomi politikasından olsun; önemli
          bir vergi düzenlemesi varsa onu da ayrı bir madde yap.
        - "ÖNCEKİ YAZILAR" listesindeki konuları, üzerine YENİ ve somut
          bir gelişme yoksa yeniden seçme. Yeni gelişme varsa seçebilirsin;
          açıyı o yeni gelişme üzerine kur.
        - Beklenti, söylenti ve toplantı duyurusu gibi içeriği olmayan
          haberleri tek başına konu yapma.

        Her madde için: kısa bir gündem etiketi, dayandığı haber
        numaraları, yazının açısı (neden önemli, okuyucuya ne
        anlatılacak; 1-2 cümle) ve bir başlık önerisi yaz.
        METIN;

    private const YAZI_YONERGE = self::ORTAK . <<<'METIN'


        Görevin: verilen gündem maddesi için bir köşe yazısı yazmak.
        Malzeme olarak o maddenin haberleri ve varsa kaynak sayfaların
        metni verilecek.

        BİÇİM
        - 700-1100 kelime, Türkçe.
        - İlk paragraf: ne oldu ve okuyucu için neden önemli; iki-üç
          cümlede.
        - Ardından 2-4 ara başlık. Ara başlık satırı "## " ile başlar ve
          tek satırdır. Konuya uygun olanları seç: arka plan, rakamlar
          ve tarihler, ekonomiye ve piyasalara olası etkileri, kimleri
          etkiliyor, piyasa ne bekliyor, uygulamada dikkat edilecekler,
          bundan sonra izlenecekler.
        - Ekonomi konularında haberi tekrar etmekle yetinme: gelişmenin
          enflasyona, kura, faize, bütçeye, şirketlere ya da hane
          halkına olası etkilerini tartış. Etkiler için mekanizmayı
          anlat (neden, hangi yoldan) ve mümkünse birden fazla
          senaryoyu göster. Bu bir değerlendirmedir; kesin bir sonuç
          gibi sunma.
        - Paragraflar boş satırla ayrılır. Madde listesi gerekiyorsa her
          satır "- " ile başlar. Başka biçimlendirme YOK: kalın, italik,
          bağlantı, tablo, emoji kullanma.
        - Son paragraf kısa bir değerlendirme ya da okuyucuya somut bir
          hatırlatma olsun.

        ÖZGÜN ANALİZ — bu köşe haber özeti DEĞİLDİR.
        Her yazıda şu üçü mutlaka bulunur:
        - Somut yorum: Valentra'nın konuya dair açık bir görüşü. "Bizce",
          "değerlendirmemize göre" gibi bir ifadeyle, tek cümlede
          söylenebilecek netlikte (ör. "Bu düzenleme en çok ihracatçı
          KOBİ'lerin nakit akışını rahatlatacak; büyük şirketler için
          etkisi sınırlı kalacak."). "Etkileri zamanla görülecek" gibi
          her konuya uyan genel cümleler yorum sayılmaz.
        - Gerekçe: bu görüşe neden varıldığı; hangi olgu, hangi
          mekanizma. Görüş ile gerekçe aynı ya da ardışık paragrafta.
        - Uygulama örneği: okuyucunun kendi durumuna uyarlayabileceği
          somut bir örnek. Oran, süre ve eşikler malzemeden gelir;
          işletmenin cirosu ya da tutar gibi örnek değerler varsayımsal
          olabilir ama "varsayalım", "örneğin … TL'lik bir satışta"
          diye açıkça varsayım olduğu belli edilir ve hesap adım adım
          gösterilir. Malzemede hesabı kuracak oran ya da kural yoksa
          sayısal örnek UYDURMA; bunun yerine kimin, hangi durumda, ne
          yapması gerektiğini somut bir senaryo olarak anlat.

        ÜSLUP
        - Kurumsal ve sakin; deneyimli bir mali müşavirin müşterisine
          yazdığı bilgilendirme notunun açıklığında. Abartı, heyecan ve tık
          tuzağı yok ("şok", "flaş", "bomba" gibi).
        - Olgu ile değerlendirmeyi ayır: değerlendirme yaparken bunun
          bir değerlendirme olduğu cümleden anlaşılsın.
        - Siyasi taraf tutma. Yatırım tavsiyesi verme ("alın", "satın"
          deme). Kişiye özel vergi danışmanlığı yapma.

        EN ÖNEMLİ KURAL — UYDURMA.
        - Rakam, oran, tarih, kanun ya da madde numarası YALNIZCA
          malzemede geçiyorsa yazılır. Malzemede olmayan bir ayrıntıyı
          tahminle tamamlama. Kendi sayısal tahminini üretme ("dolar yıl
          sonunda şu olur" deme); beklenti rakamı ancak malzemedeki bir
          kaynağa (anket, kurum, banka) dayanıyorsa ve o kaynağın adıyla
          yazılır.
        - Bir şey henüz belli değilse (yürürlük tarihi, ikincil
          düzenleme, uygulamanın ayrıntısı) bunu açıkça "henüz belli
          değil" diye söyle.
        - Kaynaklardan cümle kopyalama; kendi cümlelerinle yaz. Kaynağa
          adıyla atıf yapabilirsin ("Resmî Gazete'de yayımlanan karara
          göre…").

        editor_notu alanına, yazıyı onaylayacak editör için doğrulanması
        gereken noktaları kısaca yaz (hangi rakam hangi kaynaktan,
        emin olunamayan bir husus varsa o).
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
                        'icerik'      => ['type' => 'string',  'description' => '700-1100 kelime; paragraflar boş satırla ayrılmış, ara başlıklar "## " ile'],
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
    public function gundemSec(array $haberler, array $onceki, int $enFazla): array
    {
        if ($haberler === [] || $enFazla < 1) {
            return [];
        }

        $sonuclar = $this->istemci->semaliIstek(
            self::SECIM_YONERGE,
            $this->secimIstemi($haberler, $onceki, $enFazla),
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
    public function yaz(array $madde, array $haberler, array $kaynakMetinleri = []): ?array
    {
        $sonuclar = $this->istemci->semaliIstek(
            self::YAZI_YONERGE,
            $this->yaziIstemi($madde, $haberler, $kaynakMetinleri),
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
    private function secimIstemi(array $haberler, array $onceki, int $enFazla): string
    {
        $bloklar = [];

        foreach ($haberler as $i => $h) {
            $no    = $i + 1;
            $ozet  = mb_substr(trim((string) ($h['ozet'] ?? '')), 0, self::SECIM_OZET_SINIRI, 'UTF-8');
            $deg   = trim((string) ($h['analiz_degisen'] ?? ''));
            $kat   = trim((string) ($h['kategori'] ?? ''));
            $satir = "HABER {$no} — {$h['baslik']}\n"
                   . '  Konu: ' . ($kat !== '' ? $kat : '-') . ' · Kaynak: ' . ($h['kaynak_adi'] ?? '-')
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
                $satirlar[] = '- ' . ($y['gun'] ?? '') . ' · ' . ($y['gundem'] ?? '') . ' · ' . ($y['baslik'] ?? '');
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
