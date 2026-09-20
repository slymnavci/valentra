<?php
declare(strict_types=1);

/**
 * Kanun metnini resmî kaynaktan alıp site içinde gösterir.
 *
 * Neden cerceve degil: mevzuat.gov.tr sayfanin baska bir site icinde
 * gosterilmesine izin vermiyor (X-Frame-Options). Cerceve denendi,
 * bombos gri bir kutu cikti. Ustelik engellenen cerceve tarayicida yine
 * "load" olayini tetikledigi icin bunu JavaScript ile fark etmek de
 * guvenilir degil.
 *
 * Bu yuzden metin SUNUCU tarafinda cekiliyor ve kendi sayfamizda
 * gosteriliyor. Kopyasi TUTULMUYOR: her seferinde resmi kaynaktan
 * aliniyor, yalnizca kisa sureli onbellege konuyor. Boylece gosterilen
 * metin her zaman yururlukteki hali oluyor ve sayfanin ustunde de
 * resmi kaynaga giden bag duruyor.
 *
 * Ikinci tuzak: "/mevzuat?MevzuatNo=..." adresi bir JavaScript
 * uygulamasi. Sunucudan gelen HTML bos bir kabuk; metin tarayicida
 * sonradan doluyor. Yani indirme basarili olsa bile icinde kanun
 * metni cikmiyor. Bu yuzden once metnin duragan dosya halleri
 * (PDF, DOC) deneniyor, sayfa en son sirada.
 *
 * Erisim yine de garanti degil: mevzuat.gov.tr veri merkezi IP'lerini
 * engelliyor olabilir (GitHub uzerinden yapilan kontrol 12 adresin
 * 12'sine de HTTP 0 dondurdu; gelistirme ortamindan da cikis kapali).
 * Site Turkiye'de barindigi icin oradan erisim mumkun — ama bunu ancak
 * sunucunun kendisi soyleyebilir.
 *
 * Neyin nerede takildigini gormenin iki yolu var:
 *   /kanun.php?k=213&tani=1   tek kanun icin, herkese acik
 *   /admin/kanunlar.php       butun kanunlar, panelden
 */

require_once __DIR__ . '/ayarlar.php';
require_once __DIR__ . '/url.php';
require_once __DIR__ . '/http_ortak.php';
require_once __DIR__ . '/kanun_dosya.php';

/** Onbellek suresi: kanun metinleri nadiren degisir. */
const KANUN_ONBELLEK_SURE = 6 * 3600;

/**
 * Başarısızlığın önbellekte kalma süresi.
 *
 * Basarisizlik da onbellege giriyor, ama kisa sureligine. Aksi halde
 * kaynak erisime kapaliyken sayfayi acan HER ziyaretci bes ayri
 * istegin zaman asimini bastan bekliyor; sayfa dakikalarca acilmiyor
 * ve sunucu bosuna yoruluyor. Kisa sure, kaynak geri geldiginde
 * sayfanin da kendiliginden duzelmesini sagliyor.
 */
const KANUN_HATA_ONBELLEK_SURE = 600;

/**
 * Tüm denemelere ayrılan toplam süre.
 *
 * Ziyaretci bir sayfanin acilmasini sonsuza kadar beklemez. Butce
 * dolunca kalan adaylar denenmeden "denenmedi" diye isaretlenir ve
 * sayfa resmi kaynak baglantisina duser.
 */
const KANUN_SURE_BUTCESI = 25;

/**
 * Kanunun sayfada nasıl gösterileceğini belirler.
 *
 * Adaylar (bkz. kanun_metin_adaylari) sirayla deneniyor, ilk tutan
 * kullaniliyor:
 *   pdf   - resmi metnin duragan hali; tarayicinin kendi
 *           goruntuleyicisinde, kendi sayfamizin icinde acilir.
 *   doc   - bazi kanunlarda Word-HTML olarak duruyor; ayiklanip
 *           metin olarak basilir.
 *   sayfa - JavaScript uygulamasi oldugu icin genelde bos gelir,
 *           yine de son sans olarak deneniyor.
 *
 * Ayni sunucuya baglanilamadiysa o sunucudaki diger adaylar
 * denenmiyor: hepsi ayni sekilde ve ayni sureyi harcayarak duserdi.
 *
 * Sonuc (hangi adayin tuttugu ve varsa metin) onbellege yaziliyor;
 * PDF'in kendisi onbellege KONMUYOR, o her istekte kaynaktan
 * aktariliyor.
 *
 * @return array{tur:string,govde:string,url:string,neden:string,onbellek:bool,
 *               denemeler:list<array{ad:string,url:string,kod:int,sonuc:string,ayrinti:string}>}
 */
function kanun_gosterim(array $kanun, bool $onbellekKullan = true): array
{
    $anahtar = 'kanun_gosterim_' . (int) $kanun['no'];

    if ($onbellekKullan) {
        $onbellek = kanun_onbellekten($anahtar);

        if ($onbellek !== null) {
            return $onbellek + ['onbellek' => true, 'denemeler' => []];
        }
    }

    $referer   = kanun_referer($kanun);
    $baslangic = microtime(true);
    $denemeler = [];
    $sonuc     = null;

    /** @var array<string,string> Ulasilamayan sunucular ve sebepleri. */
    $olu = [];

    /** @var array<string,list<string>> Sunucu basina sertifika zinciri. */
    $zincirler = [];

    foreach (kanun_tum_adaylar($kanun) as $aday) {
        $sunucu = kanun_sunucu($aday['url']);

        if ($sonuc !== null) {
            break;
        }

        if (isset($olu[$sunucu])) {
            $denemeler[] = kanun_deneme($aday, 0, 'Denenmedi; ' . $olu[$sunucu], '');
            continue;
        }

        if (microtime(true) - $baslangic > KANUN_SURE_BUTCESI) {
            $denemeler[] = kanun_deneme($aday, 0, 'Denenmedi; süre bütçesi doldu.', '');
            continue;
        }

        if ($aday['tur'] === 'pdf') {
            // Yalnizca bas kismi indirilip dosya imzasina bakiliyor.
            $yanit = http_bas_getir($aday['url'], 1024, 12, $referer);

            /*
             * Icerik turu de kontrol ediliyor.
             *
             * PDF diye istenen adresin text/html dondurmesi, kaynagin
             * dosya yerine bir sayfa (cogu zaman hata sayfasi) verdigi
             * anlamina gelir. Imza kontrolu bunu zaten yakaliyor ama
             * sebebi soylemiyordu; tani ekraninda "PDF degil" ile
             * "HTML geldi" ayri seylerdir.
             */
            $html = str_contains((string) $yanit['tur'], 'html');

            if (!$yanit['tamam']) {
                $denemeler[] = kanun_deneme($aday, $yanit['kod'], $yanit['neden'], kanun_ayrinti($yanit));
            } elseif (!str_starts_with($yanit['govde'], '%PDF')) {
                $denemeler[] = kanun_deneme(
                    $aday,
                    $yanit['kod'],
                    $html
                        ? 'PDF beklenirken HTML sayfası geldi; adres dosyaya değil sayfaya gidiyor.'
                        : 'Yanıt PDF imzası taşımıyor (ilk baytlar: '
                          . kanun_bayt_ozeti($yanit['govde']) . ').',
                    kanun_ayrinti($yanit)
                );
            } else {
                $denemeler[] = kanun_deneme($aday, $yanit['kod'], 'Tamam', kanun_ayrinti($yanit));
                $sonuc = ['tur' => 'pdf', 'govde' => '', 'url' => $aday['url'], 'neden' => ''];
            }
        } else {
            $yanit = http_getir($aday['url'], 25, $referer);

            if (!$yanit['tamam']) {
                $denemeler[] = kanun_deneme($aday, $yanit['kod'], $yanit['neden'], kanun_ayrinti($yanit));
            } elseif (kanun_hata_sayfasi_mi($yanit['govde'])) {
                // 200 donduren hata sayfasi. Uzunluk kontrolunden
                // gecerdi; icerigiyle taniniyor.
                $denemeler[] = kanun_deneme(
                    $aday,
                    $yanit['kod'],
                    'Kaynak HTTP 200 döndürdü ama gelen şey bir hata/uyarı sayfası.',
                    kanun_ayrinti($yanit)
                );
            } else {
                $govde = $aday['tur'] === 'doc'
                    ? kanun_word_html_ayikla($yanit['govde'])
                    : kanun_govdeyi_ayikla($yanit['govde']);

                if ($govde === '') {
                    $denemeler[] = kanun_deneme(
                        $aday,
                        $yanit['kod'],
                        $aday['tur'] === 'doc'
                            ? 'Dosya alındı ama metin ayıklanamadı (ikili Word belgesi olabilir).'
                            : 'Sayfa alındı ama metin yok; JavaScript ile doluyor olabilir.',
                        kanun_ayrinti($yanit)
                    );
                } elseif (!kanun_metni_ilgili_mi($govde, $kanun)) {
                    /*
                     * Metin var ama BU kanunun metni degil.
                     *
                     * Fihrist sayfalari, arama sonuclari ve baska bir
                     * kanunun metni bu kontrole takiliyor. Olmasaydi
                     * ziyaretcinin onune yanlis kanun konurdu — bir YMM
                     * sitesinde bos sayfadan cok daha kotu.
                     */
                    $denemeler[] = kanun_deneme(
                        $aday,
                        $yanit['kod'],
                        'Sayfadan metin çıktı (' . mb_strlen($govde, 'UTF-8')
                        . ' karakter) ama ' . (int) $kanun['no']
                        . ' sayılı kanunun metni değil; fihrist ya da başka bir sayfa.',
                        kanun_ayrinti($yanit)
                    );
                } else {
                    $denemeler[] = kanun_deneme($aday, $yanit['kod'], 'Tamam', kanun_ayrinti($yanit));
                    $sonuc = ['tur' => 'html', 'govde' => $govde, 'url' => $aday['url'], 'neden' => ''];
                }
            }
        }

        /*
         * Tani kipinde sertifika hatasinin SEBEBINI de goster.
         *
         * Zinciri okumak ayri bir istek gerektirdigi ve dogrulamayi
         * kapattigi icin yalnizca tani kipinde, yani onbellek atlanmis
         * bir istekte yapiliyor; ziyaretcinin gordugu normal sayfa
         * bunu hic calistirmiyor.
         */
        $sertifikaHatasi = isset($yanit) && !$yanit['tamam']
            && ((int) $yanit['dogrulama'] !== 0
                || in_array((int) $yanit['hata_no'], [35, 51, 60, 77], true));

        if (!$onbellekKullan && $sertifikaHatasi && !isset($zincirler[$sunucu])) {
            $zincirler[$sunucu] = http_sertifika_zinciri($aday['url']);
        }

        if (!$onbellekKullan && isset($yanit) && ($zincirler[$sunucu] ?? []) !== []) {
            $son = array_key_last($denemeler);

            $denemeler[$son]['ayrinti'] .= ' · zincir: '
                . implode(' | ', $zincirler[$sunucu]);
        }

        // Sunucuya hic ulasilamadiysa oradaki diger adaylari es gec.
        if ($sonuc === null && isset($yanit) && !$yanit['tamam']
            && http_baglanti_hatasi_mi((int) $yanit['hata_no'], (bool) $yanit['baglandi'])) {
            $olu[$sunucu] = mb_strtolower(mb_substr($yanit['neden'], 0, 1), 'UTF-8')
                          . mb_substr($yanit['neden'], 1);
        }
    }

    /*
     * Son care: panelden yuklenmis PDF.
     *
     * Bilerek EN SONDA. Resmi kaynaklardan biri calisiyorsa metnin
     * guncel hali gosterilmeli; yuklenen dosya bir kopyadir ve eskir.
     * Kaynak yeniden erisilebilir hale geldiginde sayfa kendiliginden
     * resmi metne donuyor, cunku bu dal yalnizca digerleri dustugunde
     * calisiyor.
     */
    if ($sonuc === null && kanun_dosya_var_mi((int) $kanun['no'])) {
        $bilgi = kanun_dosya_bilgisi((int) $kanun['no']);

        $denemeler[] = [
            'ad'      => 'Yüklenen PDF',
            'url'     => 'includes/kanun_pdf/' . (int) $kanun['no'] . '.pdf',
            'kod'     => 0,
            'sonuc'   => 'Tamam (panelden yüklenmiş kopya)',
            'ayrinti' => number_format($bilgi['boyut'] / 1024 / 1024, 1) . ' MB · yüklenme: '
                       . $bilgi['tarih'],
        ];

        $sonuc = ['tur' => 'yerel', 'govde' => '', 'url' => '', 'neden' => ''];
    }

    if ($sonuc === null) {
        $basarisiz = [
            'tur'   => 'yok',
            'govde' => '',
            'url'   => '',
            'neden' => kanun_neden_ozetle($denemeler),
        ];

        kanun_onbellege($anahtar, $basarisiz);

        return $basarisiz + ['onbellek' => false, 'denemeler' => $denemeler];
    }

    kanun_onbellege($anahtar, $sonuc);

    return $sonuc + ['onbellek' => false, 'denemeler' => $denemeler];
}

/**
 * Gelen yanıtın ilk baytlarını okunabilir biçimde gösterir.
 *
 * "PDF degil" demek yetmiyor; ne geldigini gormek gerekiyor. Basilabilir
 * olmayan baytlar noktaya cevriliyor ki tani satiri bozulmasin.
 */
function kanun_bayt_ozeti(string $govde, int $adet = 24): string
{
    $bas = substr($govde, 0, $adet);

    return (string) preg_replace('/[^\x20-\x7E]/', '.', $bas);
}

/**
 * Gelen metin gerçekten BU kanunun metni mi?
 *
 * Bir adresin HTTP 200 dondurmesi ve icinden metin ayiklanabilmesi,
 * o metnin aradigimiz kanun oldugu anlamina gelmiyor. Yedek kaynak
 * olarak girilen adresler (ornegin GIB'in mevzuat sayfalari) cogu
 * zaman bir fihrist, bir arama sonucu ya da "sayfa bulunamadi"
 * uyarisi donduruyor; bunlarin hepsi 500 karakterden uzun ve hepsi
 * ayiklanabiliyor. Kontrol olmadan bu sayfalar "tamam" sayilip
 * ziyaretcinin onune kanun metni diye konurdu.
 *
 * Iki isarete bakiliyor ve IKISI DE aranmiyor — biri yeterli:
 *   - kanunun numarasi (213, 193 gibi) metinde geciyor mu
 *   - kanunun adi (bosluk ve buyuk/kucuk harf farklari es gecilerek)
 *     metinde geciyor mu
 *
 * Ayrica metnin kanun metnine benzemesi bekleniyor: "MADDE" ya da
 * "Madde" gecmeyen bir sayfa kanun metni degildir.
 */
function kanun_metni_ilgili_mi(string $metin, array $kanun): bool
{
    $duz = kanun_karsilastirmaya_hazirla($metin);

    if ($duz === '') {
        return false;
    }

    // Kanun metninin en belirgin isareti madde basliklari.
    if (!str_contains($duz, 'madde')) {
        return false;
    }

    $no = (string) (int) $kanun['no'];

    if (str_contains($duz, $no)) {
        return true;
    }

    $ad = kanun_karsilastirmaya_hazirla((string) $kanun['ad']);

    return $ad !== '' && str_contains($duz, $ad);
}

/**
 * Metni karşılaştırmaya elverişli hâle getirir.
 *
 * Turkce harfler sadelestiriliyor cunku kaynaklar ayni kanunu farkli
 * yaziyor: "VERGİ USUL KANUNU", "Vergi Usul Kanunu", bazen noktasiz
 * "i" ile. Ard arda bosluklar tek bosluğa indiriliyor ki
 * "VERGI  USUL" ile "VERGI USUL" ayni sayilsin.
 */
function kanun_karsilastirmaya_hazirla(string $metin): string
{
    /*
     * Sadelestirme kucuk harfe cevirmeden ONCE yapiliyor.
     *
     * Sebebi Turkce'ye ozgu bir tuzak: mb_strtolower('İ') sonucu sade
     * bir "i" degil, "i" + birlesen nokta (U+0307). Once kucuk harfe
     * cevirip sonra harf eslemesi yapan bir kod bu noktayi goremiyor
     * ve "VERGİ USUL KANUNU" ile "Vergi Usul Kanunu" eslesmiyordu.
     * Once esleme yapilinca buyuk harfli 'İ' dogrudan 'i' oluyor.
     */
    $metin = strtr($metin, [
        'ı' => 'i', 'İ' => 'i', 'ş' => 's', 'Ş' => 's',
        'ğ' => 'g', 'Ğ' => 'g', 'ü' => 'u', 'Ü' => 'u',
        'ö' => 'o', 'Ö' => 'o', 'ç' => 'c', 'Ç' => 'c',
        'â' => 'a', 'Â' => 'a', 'î' => 'i', 'Î' => 'i',
        'û' => 'u', 'Û' => 'u',
    ]);

    $metin = mb_strtolower($metin, 'UTF-8');

    // Geriye kalan birlesen isaretler (baska kaynaklardan gelebilir)
    // temizleniyor ki gorunuste ayni iki metin ayni sayilsin.
    $metin = (string) preg_replace('/\p{Mn}/u', '', $metin);

    return trim((string) preg_replace('/\s+/u', ' ', $metin));
}

/**
 * Yanıt bir hata ya da yönlendirme sayfası mı?
 *
 * Bazi sunucular bulunamayan adres icin 404 yerine 200 ile kendi hata
 * sayfasini donduruyor. Bu sayfalar kisa olmadiklari icin uzunluk
 * kontrolunden geciyor; iceriklerinden tanimak gerekiyor.
 */
function kanun_hata_sayfasi_mi(string $html): bool
{
    $bas = kanun_karsilastirmaya_hazirla(mb_substr($html, 0, 4000, 'UTF-8'));

    foreach (['sayfa bulunamadi', 'bulunamadi', 'not found', '404',
              'erisim engellendi', 'access denied', 'forbidden',
              'bir hata olustu', 'hata olustu', 'gecersiz istek'] as $imza) {
        if (str_contains($bas, $imza)) {
            return true;
        }
    }

    return false;
}

/**
 * Adresin sunucu kimliği.
 *
 * "Ayni sunucuya ulasilamiyorsa oradaki diger adresleri deneme"
 * kuralinin anahtari. Port da dahil: ayni makinedeki farkli bir port
 * farkli bir servistir ve biri kapaliyken digeri acik olabilir.
 */
function kanun_sunucu(string $url): string
{
    $parcalar = parse_url($url);
    $sunucu   = (string) ($parcalar['host'] ?? '');

    return isset($parcalar['port'])
        ? $sunucu . ':' . (int) $parcalar['port']
        : $sunucu;
}

/**
 * Yönetici tarafından girilen yedek kaynak adresi.
 *
 * Neden gerekli: mevzuat.gov.tr'ye erisim sunucudan sunucuya degisiyor
 * ve bizim elimizde olmayan sebeplerle kapanabiliyor. Boyle bir durumda
 * kanun metnini gosterebilmek icin baska bir adresin denenebilmesi
 * lazim — ve o adresin kodda sabit olmasi ise yaramaz, cunku hangi
 * kaynagin acik oldugu ancak sunucunun kendisinden denenerek anlasiliyor.
 * Panelden girilen adres butun adaylardan ONCE deneniyor.
 *
 * Adres kendi listemizden degil yoneticiden geliyor, o yuzden sema
 * dogrulamasindan geciriliyor (yalnizca http/https).
 */
function kanun_yedek_oku(int $no): string
{
    return guvenli_url(ayar_oku('kanun_yedek_' . $no));
}

/** Yedek kaynağı kaydeder; boş adres kaydı siler. */
function kanun_yedek_yaz(int $no, string $url): void
{
    $url = guvenli_url($url);

    if ($url === '') {
        ayar_sil('kanun_yedek_' . $no);

        return;
    }

    ayar_yaz('kanun_yedek_' . $no, $url);
}

/**
 * Denenecek adayların tamamı: önce yedek, sonra resmî adresler.
 *
 * Yedegin turu uzantisindan anlasiliyor. Bilinmiyorsa "sayfa" kabul
 * ediliyor; o yol metni HTML icinden ayikliyor, yani bir kanun metni
 * sayfasi veriliyorsa calisir.
 *
 * @return list<array{ad:string,tur:string,url:string}>
 */
function kanun_tum_adaylar(array $kanun): array
{
    $adaylar = kanun_metin_adaylari($kanun);
    $yedek   = kanun_yedek_oku((int) $kanun['no']);

    if ($yedek === '') {
        return $adaylar;
    }

    $yol = strtolower((string) parse_url($yedek, PHP_URL_PATH));

    $tur = match (true) {
        str_ends_with($yol, '.pdf') => 'pdf',
        str_ends_with($yol, '.doc'), str_ends_with($yol, '.docx') => 'doc',
        default => 'sayfa',
    };

    array_unshift($adaylar, ['ad' => 'Yedek kaynak', 'tur' => $tur, 'url' => $yedek]);

    return $adaylar;
}

/**
 * kanun_gosterim()'in hiçbir koşulda istisna fırlatmayan hâli.
 *
 * Sayfa sablonu bu sarmalayici uzerinden cagiriyor. Sebebi canlida
 * yasandi: metin getirme yolunda olusan bir istisna sayfanin ustu
 * basildiktan SONRA firliyordu, yani ziyaretci once menuyu sonra
 * "Bir hata olustu" kutusunu goruyordu. Oysa bu sayfanin metin
 * gelmediginde ne gosterecegi zaten belli — resmi kaynak baglantilari.
 *
 * Bir kanun metnini getirememek sayfayi dusurmeyi hak eden bir durum
 * degil. Sebep gunluge yaziliyor ve yoneticiye gosteriliyor;
 * ziyaretci calisan yollari goruyor.
 *
 * @return array{tur:string,govde:string,url:string,neden:string,onbellek:bool,
 *               denemeler:list<array{ad:string,url:string,kod:int,sonuc:string,ayrinti:string}>}
 */
function kanun_gosterim_guvenli(array $kanun, bool $onbellekKullan = true): array
{
    try {
        return kanun_gosterim($kanun, $onbellekKullan);
    } catch (Throwable $e) {
        error_log('[valentra] kanun metni alinamadi (' . (int) $kanun['no'] . '): '
                . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine());

        return [
            'tur'       => 'yok',
            'govde'     => '',
            'url'       => '',
            'neden'     => 'Beklenmeyen hata: ' . $e->getMessage()
                         . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')',
            'onbellek'  => false,
            'denemeler' => [],
        ];
    }
}

/**
 * Tanı ekranında gösterilen tek deneme satırı.
 *
 * @param array{ad:string,tur:string,url:string} $aday
 * @return array{ad:string,url:string,kod:int,sonuc:string,ayrinti:string}
 */
function kanun_deneme(array $aday, int $kod, string $sonuc, string $ayrinti): array
{
    return ['ad' => $aday['ad'], 'url' => $aday['url'],
            'kod' => $kod, 'sonuc' => $sonuc, 'ayrinti' => $ayrinti];
}

/**
 * Bir yanıtın ölçülen ayrıntılarını tek satıra yazar.
 *
 * "HTTP 200 ama olmadi" durumlarini birbirinden ayiran sey bu: gelen
 * icerik turu text/html ise kaynak hata sayfasi dondurmus, sure
 * zaman asimina yakinsa yavaslik var, son adres farkliysa istek
 * baska yere yonlenmis demektir.
 *
 * @param array{tur?:string,boyut?:int,sure?:float,son_url?:string,hata_no?:int} $yanit
 */
function kanun_ayrinti(array $yanit): string
{
    $parcalar = [];

    if (($yanit['tur'] ?? '') !== '') {
        $parcalar[] = (string) $yanit['tur'];
    }

    $parcalar[] = (int) ($yanit['boyut'] ?? 0) . ' bayt';
    $parcalar[] = number_format((float) ($yanit['sure'] ?? 0), 1) . ' sn';

    if ((int) ($yanit['hata_no'] ?? 0) !== 0) {
        /*
         * Hem numara hem ham mesaj yaziliyor. Cevrilmis cumle "ne
         * oldu"yu, ham mesaj "tam olarak neresi"ni soyluyor; sertifika
         * hatalarinda aradaki fark belirleyici oluyor (ornegin
         * "unable to get local issuer certificate" ile "certificate
         * has expired" ayni cevrilmis cumleye dusuyordu).
         */
        $parcalar[] = 'curl ' . (int) $yanit['hata_no'];

        $ham = trim((string) ($yanit['hata'] ?? ''));

        if ($ham !== '') {
            $parcalar[] = '"' . $ham . '"';
        }
    }

    $dogrulama = http_dogrulama_acikla((int) ($yanit['dogrulama'] ?? 0));

    if ($dogrulama !== '') {
        $parcalar[] = $dogrulama;
    }

    $sonUrl = (string) ($yanit['son_url'] ?? '');

    if ($sonUrl !== '') {
        $parcalar[] = 'son adres: ' . $sonUrl;
    }

    return implode(' · ', $parcalar);
}

/**
 * Kararı önbelleğe yazar.
 *
 * Onbellek bir HIZLANDIRMA; basarisiz olmasi sayfayi dusurmemeli.
 * Ilk surumde oyle degildi ve kanun sayfasi canlida 500 verdi: yazma
 * sirasinda olusan her hata dogrudan ziyaretciye gidiyordu.
 *
 * Iki tuzak vardi:
 *   - json_encode gecersiz UTF-8'de false doner. Kaynaktan gelen metin
 *     her zaman temiz UTF-8 degil; false'u ayar_yaz'a vermek katı tip
 *     denetiminde TypeError firlatir.
 *   - Metin ayarlar tablosundaki TEXT sutununa sigmayabilir (VUK gibi
 *     bir kanun 64 KB'i asar). Sigmayan yazma veritabani hatasi verir.
 *     Metin cok buyukse yalnizca KARAR saklaniyor; bir sonraki istek
 *     kaynaktan yeniden okur, yani dogruluk bozulmaz, sadece onbellek
 *     kazanci kalmaz.
 *
 * @param array{tur:string,govde:string,url:string,neden:string} $sonuc
 */
function kanun_onbellege(string $anahtar, array $sonuc): void
{
    // TEXT sutununun siniri 65.535 BAYT; guvenli tarafta kaliyoruz.
    if (strlen($sonuc['govde']) > 50000) {
        $sonuc['govde'] = '';
        $sonuc['tur']   = 'yok';
        $sonuc['neden'] = 'Metin önbelleğe sığmayacak kadar uzun.';
    }

    $json = json_encode(['zaman' => time()] + $sonuc, JSON_UNESCAPED_UNICODE);

    if (!is_string($json)) {
        return;
    }

    try {
        ayar_yaz($anahtar, $json);
    } catch (Throwable $e) {
        error_log('[valentra] kanun onbellegi yazilamadi: ' . $e->getMessage());
    }
}

/**
 * Önbellekteki PDF adresini ya da çalışan ilk adayı verir.
 *
 * PDF'i aktaran uc (api/kanun-pdf.php) bunu kullanir: sayfa zaten hangi
 * adayin tuttugunu bulmus durumda, ayni aramayi bir daha yapmanin
 * anlami yok. Onbellek yoksa (ornegin PDF dogrudan adres cubuguna
 * yazildiysa) adaylar yeniden deneniyor.
 */
function kanun_pdf_adresi(array $kanun): ?string
{
    $onbellek = kanun_onbellekten('kanun_gosterim_' . (int) $kanun['no']);

    if ($onbellek !== null && $onbellek['tur'] === 'pdf' && $onbellek['url'] !== '') {
        return $onbellek['url'];
    }

    $referer = kanun_referer($kanun);
    $olu     = [];

    $pdfAdaylari = array_values(array_filter(
        kanun_tum_adaylar($kanun),
        static fn (array $a): bool => $a['tur'] === 'pdf'
    ));

    foreach ($pdfAdaylari as $aday) {
        $sunucu = kanun_sunucu($aday['url']);

        if (isset($olu[$sunucu])) {
            continue;
        }

        $yanit = http_bas_getir($aday['url'], 1024, 12, $referer);

        if ($yanit['tamam'] && str_starts_with($yanit['govde'], '%PDF')) {
            return $aday['url'];
        }

        if (!$yanit['tamam']
            && http_baglanti_hatasi_mi((int) $yanit['hata_no'], (bool) $yanit['baglandi'])) {
            $olu[$sunucu] = true;
        }
    }

    return null;
}

/**
 * Başarısız denemeleri tek cümlede özetler.
 *
 * Onceki surumde yalnizca son hata gorunuyordu; hangi yolun nerede
 * takildigi belli olmadigi icin sorunu uzaktan cozmek korlemesineydi.
 *
 * @param list<array{ad:string,url:string,kod:int,sonuc:string}> $denemeler
 */
function kanun_neden_ozetle(array $denemeler): string
{
    $parcalar = [];

    foreach ($denemeler as $deneme) {
        $parcalar[] = $deneme['ad'] . ': ' . $deneme['sonuc'];
    }

    return implode(' | ', $parcalar);
}

/**
 * Word olarak sunulan metni ayıklar.
 *
 * mevzuat.gov.tr'deki ".doc" dosyalarinin bir kismi gercekte Word'un
 * HTML olarak kaydettigi dosyalar; bunlar ayiklanabiliyor. Bir kismi
 * ise ikili (binary) Word belgesi ve PHP ile makul bicimde
 * okunamiyor — o durumda bos donup bir sonraki yola geciliyor.
 */
function kanun_word_html_ayikla(string $govde): string
{
    $bas = substr($govde, 0, 4096);

    if (stripos($bas, '<html') === false && stripos($bas, '<body') === false) {
        return '';
    }

    // Word'un kosullu yorumlari ve stil bloklari metin tasimaz.
    $govde = (string) preg_replace('#<!--.*?-->#s', ' ', $govde);

    return kanun_govdeyi_ayikla($govde);
}

/**
 * Önbellekteki kararı döndürür.
 *
 * Yalnizca "hangi aday tuttu", tuttuysa adresi ve varsa metin
 * saklaniyor. PDF yolunda dosyanin kendisi saklanmiyor: kanun metninin
 * kalici kopyasini tutmamak bilincli bir tercih (bkz. dosya basi).
 *
 * Basarisizlik da saklaniyor ama cok daha kisa sure icin; boylece
 * kaynak kapaliyken her ziyaretci bastan bes zaman asimi beklemiyor,
 * kaynak acilinca da sayfa kendiliginden duzeliyor.
 *
 * @return array{tur:string,govde:string,url:string,neden:string}|null
 */
function kanun_onbellekten(string $anahtar): ?array
{
    $ham = ayar_oku($anahtar);

    if ($ham === '') {
        return null;
    }

    $veri = json_decode($ham, true);

    if (!is_array($veri) || !isset($veri['zaman'], $veri['tur'])) {
        return null;
    }

    $tur = (string) $veri['tur'];

    /*
     * Yerel dosya karari da kisa omurlu: resmi kaynak geri geldiginde
     * sayfanin saatlerce kopyada takili kalmamasi icin.
     */
    $omur = in_array($tur, ['yok', 'yerel'], true)
        ? KANUN_HATA_ONBELLEK_SURE
        : KANUN_ONBELLEK_SURE;

    if (time() - (int) $veri['zaman'] > $omur) {
        return null;
    }

    return [
        'tur'   => $tur,
        'govde' => (string) ($veri['govde'] ?? ''),
        'url'   => (string) ($veri['url'] ?? ''),
        'neden' => (string) ($veri['neden'] ?? ''),
    ];
}

/**
 * Sayfadan kanun metnini ayıklar ve güvenli HTML'e indirger.
 *
 * Disaridan gelen HTML oldugu gibi basilamaz: script, iframe, form ve
 * olay nitelikleri sayfaya kod sokabilir. Bu yuzden beyaz liste
 * yaklasimi kullaniliyor — yalnizca metin bicimlendiren etiketler
 * birakiliyor, gerisi duz metne indirgeniyor.
 */
function kanun_govdeyi_ayikla(string $html): string
{
    // Metin tasimayan bloklari tamamen at.
    $html = (string) preg_replace(
        '#<(script|style|noscript|svg|nav|footer|header|form|iframe|object|embed)\b[^>]*>.*?</\1>#is',
        ' ',
        $html
    );

    $onceki = libxml_use_internal_errors(true);
    $belge  = new DOMDocument();
    $belge->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($onceki);

    $xpath = new DOMXPath($belge);

    /*
     * Metnin bulundugu kapsayici aranıyor.
     *
     * Kesin bir secici yok ve sayfa duzeni degisebilir; bu yuzden
     * "en cok metin tasiyan div" secilir. Belirli bir id'ye baglanmak
     * duzen degisince sessizce bos sonuc verirdi.
     */
    $enIyi     = null;
    $enIyiBoyut = 0;

    foreach ($xpath->query('//div|//article|//main|//td') ?: [] as $dugum) {
        $uzunluk = mb_strlen(trim($dugum->textContent), 'UTF-8');

        if ($uzunluk > $enIyiBoyut) {
            $enIyiBoyut = $uzunluk;
            $enIyi      = $dugum;
        }
    }

    if ($enIyi === null || $enIyiBoyut < 500) {
        return '';
    }

    return kanun_guvenli_html($belge, $enIyi);
}

/**
 * Düğümü yalnızca izin verilen etiketlerle yeniden yazar.
 *
 * Beyaz liste: yalnizca metin bicimlendiren etiketler. Nitelikler
 * tamamen atiliyor (href dahil), cunku disaridan gelen bir adres
 * ziyaretciyi baska yere goturebilir ya da javascript: tasiyabilir.
 */
function kanun_guvenli_html(DOMDocument $belge, DOMNode $dugum): string
{
    $izinli = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 'sup', 'sub',
               'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
               'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span'];

    $yaz = static function (DOMNode $n) use (&$yaz, $izinli): string {
        if ($n->nodeType === XML_TEXT_NODE) {
            return htmlspecialchars($n->textContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        if ($n->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        $ad = strtolower($n->nodeName);
        $ic = '';

        foreach ($n->childNodes as $cocuk) {
            $ic .= $yaz($cocuk);
        }

        if (!in_array($ad, $izinli, true)) {
            return $ic;
        }

        if ($ad === 'br') {
            return '<br>';
        }

        // div ve span'i p'ye indirgemiyoruz ama nitelikleri atiyoruz.
        return '<' . $ad . '>' . $ic . '</' . $ad . '>';
    };

    return $yaz($dugum);
}
