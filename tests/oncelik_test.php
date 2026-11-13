<?php
declare(strict_types=1);

/**
 * Haber onceligi kabul testleri (12.11.2026; bkz. includes/oncelik.php).
 *
 *   VALENTRA_VT=valentra_oncelik_test php tests/oncelik_test.php
 *
 * AYRI bir test veritabaninda kosar: adi "_test" ile bitmeyen veritabaninda
 * calismayi reddeder (her senaryoda haberler tablosunu bosaltir). Yerel
 * includes/database.local.php, VALENTRA_VT ortam degiskenindeki veritabanina
 * baglanmali. Testler veritabanini, ajanin teslim yolunu (haber_toplu_taslak_ekle),
 * ana sayfa sorgusunu (haber_sirasi) ve, J'de, gercek HTTP uzerinden ana
 * sayfa ile sayfa kopyalarini birlikte dener.
 *
 * Buradaki olaylar UYDURMA test verisidir; gercek olaylara iliskin
 * dogrulanmamis ayrinti icermez, yayin icerigine tasinmaz.
 *
 * Yapay zekanin haberi okuyup alanlari doldurmasi burada denenmiyor (model
 * cagrisi gerekir): testler yapay zekanin DONDUREBILECEGI alanlari verip
 * sitenin kararini, siralamasini ve yayin yolunu olcer. Baslikta "sehit",
 * "deprem", "flas" gecmesinin tek basina hicbir seyi tetiklemedigi de boyle
 * gosteriliyor.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/oncelik.php';
require_once __DIR__ . '/../includes/konu_siniri.php';
require_once __DIR__ . '/../includes/ayarlar.php';

$vt = (string) db()->query('SELECT DATABASE()')->fetchColumn();

if (!str_ends_with($vt, '_test')) {
    fwrite(STDERR, "Test veritabani degil ($vt). VALENTRA_VT=..._test ile calistirin.\n");
    exit(2);
}

$secenek = getopt('', ['ic::', 'yalniz::']);

// --- Ic kipler: her teslim ve her ana sayfa okumasi ayri surecte -----------
// (sitede de her biri ayri istek; istek basina tutulan onbellekler karismasin).

if (($secenek['ic'] ?? '') === 'ekle') {
    $haberler = json_decode((string) stream_get_contents(STDIN), true);
    echo json_encode(haber_toplu_taslak_ekle($haberler), JSON_UNESCAPED_UNICODE);
    exit(0);
}

if (($secenek['ic'] ?? '') === 'sira') {
    echo json_encode(array_map(static fn (array $h): array => [
        'id' => (int) $h['id'], 'baslik' => (string) $h['baslik'], 'etkin' => (int) $h['etkin_sinif'],
        'neden' => oncelik_aciklama($h)['neden'],
    ], oncelik_ana_sayfa_sirasi(40)), JSON_UNESCAPED_UNICODE);
    exit(0);
}

if (($secenek['ic'] ?? '') === 'yayinla') {
    echo haber_bugunku_taslaklari_yayinla();
    exit(0);
}

// --- Yardimcilar -----------------------------------------------------------

$gecen = 0;
$kalan = 0;
$kayitlar = [];

function ic(string $kip, ?array $girdi = null): mixed
{
    $surec = proc_open(
        [PHP_BINARY, __FILE__, '--ic=' . $kip],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $boru
    );
    fwrite($boru[0], $girdi === null ? '' : (string) json_encode($girdi, JSON_UNESCAPED_UNICODE));
    fclose($boru[0]);
    $cikti = (string) stream_get_contents($boru[1]);
    $hata  = (string) stream_get_contents($boru[2]);
    proc_close($surec);

    $veri = json_decode($cikti, true);

    if ($veri === null && $cikti !== '0' && !is_numeric(trim($cikti))) {
        throw new RuntimeException("ic kip $kip: $cikti $hata");
    }

    return $veri ?? trim($cikti);
}

/** Ajanin gonderecegi bicimde haber. */
function haber(string $baslik, array $alanlar = []): array
{
    static $n = 0;
    $n++;

    return $alanlar + [
        'baslik'        => $baslik,
        'ozet'          => $baslik . ' (test).',
        'icerik'        => str_repeat('Bu bir test haberidir; gerçek bir olayı anlatmaz. ', 12),
        'kategori'      => 'gundem',
        'kaynak_adi'    => 'Test Kaynağı ' . $n,
        'kaynak_url'    => 'https://ornek.test/haber/' . $n . '-' . bin2hex(random_bytes(4)),
        'kaynak_baslik' => $baslik,
        'guven_skoru'   => 90,
        'onem'          => 50,
        'kaynak_zamani' => time() - 240,
        'dogrulama_ani' => time() - 2,
    ];
}

/** Dogrulanmis kritik haberin alanlari (yapay zekanin dondurecegi gibi). */
function kritik(string $tur, array $alanlar = []): array
{
    return $alanlar + [
        'oncelik' => 'kritik', 'olay_turu' => $tur, 'olay_guncel' => true, 'turkiye_dogrudan' => true,
        'dogrulama' => 'resmi', 'resmi_kurum' => 'Millî Savunma Bakanlığı', 'can_kaybi' => 'dogrulanmadi',
        'gelisme' => 'ilk', 'onem' => 92, 'olay_tarihi' => date('Y-m-d'),
    ];
}

function ekle(array $haberler): array
{
    return ic('ekle', array_values($haberler));
}

/** @return list<array{id:int,baslik:string,etkin:int,neden:string}> */
function sira(): array
{
    return ic('sira');
}

function satir(string $baslik): array
{
    $ifade = db()->prepare('SELECT * FROM haberler WHERE baslik = :b ORDER BY id DESC LIMIT 1');
    $ifade->execute(['b' => $baslik]);
    $s = $ifade->fetch();

    if ($s === false) {
        throw new RuntimeException('Haber yok: ' . $baslik);
    }

    return $s;
}

/** Haberi (ya da hepsini) $dakika kadar eskitir: zaman yolculugu. */
function eskit(int $dakika, ?string $baslik = null): void
{
    db()->prepare(
        'UPDATE haberler
            SET olusturuldu = olusturuldu - INTERVAL :d1 MINUTE, kaynak_tarihi = kaynak_tarihi - INTERVAL :d2 MINUTE,
                yayin_tarihi = yayin_tarihi - INTERVAL :d3 MINUTE, gelisme_zamani = gelisme_zamani - INTERVAL :d4 MINUTE,
                olay_zamani = olay_zamani - INTERVAL :d5 MINUTE, guncellendi = guncellendi'
        . ($baslik !== null ? ' WHERE baslik = ' . db()->quote($baslik) : '')
    )->execute(['d1' => $dakika, 'd2' => $dakika, 'd3' => $dakika, 'd4' => $dakika, 'd5' => $dakika]);
}

function temizle(): void
{
    db()->exec('DELETE FROM haberler');
    db()->exec('DELETE FROM haber_olay_gecmisi');
    @unlink(ONCELIK_ONBELLEK_DAMGA);
}

function bekle(bool $kosul, string $ne, string $ayrinti = ''): void
{
    global $gecen, $kalan, $kayitlar;

    if ($kosul) {
        $gecen++;
        echo "    ✓ $ne\n";
    } else {
        $kalan++;
        echo "    ✗ $ne" . ($ayrinti !== '' ? "  →  $ayrinti" : '') . "\n";
    }
}

function senaryo(string $ad, string $baslik, callable $is): void
{
    global $secenek;

    if (isset($secenek['yalniz']) && stripos((string) $secenek['yalniz'], $ad) === false) {
        return;
    }

    echo "\n[$ad] $baslik\n";
    temizle();

    try {
        $is();
    } catch (Throwable $e) {
        bekle(false, 'senaryo tamamlanamadı', $e->getMessage());
    }
}

/** Akisi dolduran olagan haberler (yuksek onemli, son dakika isaretli olanlar dahil). */
function olaganlar(int $adet, string $on = 'Olağan'): array
{
    $konular = ['bütçe görüşmesi', 'kira artışı düzenlemesi', 'otoyol ihalesi', 'buğday rekoltesi', 'üniversite kontenjanı',
        'belediye meclisi', 'turizm geliri', 'ihracat rakamı', 'baraj doluluğu', 'tapu harcı', 'okul servisi ücreti',
        'elektrikli araç teşviki', 'bankacılık düzenlemesi', 'konut satışı', 'sanayi üretimi', 'hal fiyatı'];
    $liste = [];

    for ($i = 0; $i < $adet; $i++) {
        $liste[] = haber("$on haber: " . $konular[$i % count($konular)] . ' ' . ($i + 1), [
            'kategori' => ['ekonomi', 'politika', 'gundem'][$i % 3],
            'onem' => [95, 90, 88, 85, 80, 76, 70, 65, 60, 55][$i % 10],
            'oncelik' => 'normal', 'olay_turu' => 'diger', 'olay_guncel' => true, 'turkiye_dogrudan' => true,
            'dogrulama' => 'tek_kaynak', 'can_kaybi' => 'yok', 'gelisme' => 'ilk',
            'son_dakika' => $i % 4 === 0,
        ]);
    }

    return $liste;
}

// ===========================================================================

senaryo('A', 'Askerî uçak kazası, transfer haberi ve rutin siyaset haberinin arasında ilk sıraya çıkar', static function (): void {
    $kaza = 'Sınavkent\'te askerî eğitim uçağı düştü: MSB açıklama yaptı';

    ekle([
        haber('Yıldız forvet için transfer görüşmesi başladı', ['kategori' => 'spor', 'onem' => 58, 'oncelik' => 'normal', 'olay_turu' => 'diger']),
        haber('Parti grup toplantısında bütçe eleştirisi', ['kategori' => 'politika', 'onem' => 86, 'oncelik' => 'normal', 'olay_turu' => 'diger']),
        haber($kaza, kritik('askeri_hava_araci_kazasi', ['olay_yeri' => 'Sınavkent'])),
    ]);

    $s = sira();
    $k = satir($kaza);
    bekle(($s[0]['baslik'] ?? '') === $kaza, 'kaza haberi ana sayfada 1. sırada', $s[0]['baslik'] ?? 'boş');
    bekle((int) $k['oncelik'] === ONCELIK_KRITIK && $k['durum'] === HABER_YAYINDA, 'kritik sınıfta ve yayında');
    bekle($k['kategori_id'] !== null && (int) $k['aciliyet'] === ACILIYET_HEMEN, 'kategori (gündem), önem ve aciliyet ayrı alanlarda');
    bekle(is_array(json_decode((string) $k['karar_kaydi'], true)), 'karar kaydı veritabanında');

    // Gercekte olan: iki saat sonra yeni toplama, icinde onemi 85-95 haberler.
    eskit(150);
    ekle(olaganlar(12));
    $s = sira();
    bekle(($s[0]['baslik'] ?? '') === $kaza, 'iki buçuk saat sonra gelen yeni toplamadan (önem 95 dahil) sonra da 1. sırada', $s[0]['baslik'] ?? 'boş');
});

senaryo('B', 'Can kaybı doğrulanmamış askerî uçak kazası', static function (): void {
    $temiz  = 'Örnekova\'da askerî helikopter kaza kırıma uğradı';
    $iddia  = 'Denemeköy\'de askerî uçak düştü: 3 asker şehit oldu';

    ekle([haber($temiz, kritik('askeri_hava_araci_kazasi', ['olay_yeri' => 'Örnekova', 'can_kaybi' => 'dogrulanmadi']))]);
    $t = satir($temiz);
    bekle($t['durum'] === HABER_YAYINDA && (int) $t['oncelik'] === ONCELIK_KRITIK, 'olay resmî açıklamayla doğrulandı: kritik olarak yayımlandı');
    bekle($t['dogrulama'] === 'resmi' && $t['can_kaybi'] === 'dogrulanmadi', 'olay doğrulaması ile can kaybı doğrulaması ayrı kayıtlı');
    bekle(!oncelik_can_kaybi_iddiasi($t), 'yayımlanan haberin başlık ve spotunda can kaybı ifadesi yok');

    ekle([haber($iddia, kritik('askeri_hava_araci_kazasi', ['olay_yeri' => 'Denemeköy', 'can_kaybi' => 'dogrulanmadi']))]);
    $i = satir($iddia);
    bekle($i['durum'] === HABER_TASLAK && $i['inceleme'] === 'acil', 'can kaybı doğrulanmadan "şehit oldu" yazan haber yayımlanmadı, acil incelemede', $i['durum'] . '/' . $i['inceleme']);
});

senaryo('C', 'Küçük ve hasarsız deprem, çok ölümlü büyük kazanın önüne geçmez', static function (): void {
    $deprem = 'Sınavkent\'te 4,1 büyüklüğünde deprem: hasar bildirilmedi';
    $kaza   = 'Örnekova\'da yolcu otobüsü devrildi: valilik 14 kişinin öldüğünü açıkladı';
    $yanlis = 'Denemeköy\'de 3,8 büyüklüğünde deprem oldu';

    ekle([haber($kaza, kritik('buyuk_can_kaybi', ['olay_yeri' => 'Örnekova', 'resmi_kurum' => 'Örnekova Valiliği', 'can_kaybi' => 'dogrulandi', 'onem' => 90]))]);
    eskit(100);
    ekle([
        haber($deprem, ['onem' => 45, 'oncelik' => 'normal', 'olay_turu' => 'diger', 'olay_guncel' => true, 'turkiye_dogrudan' => true, 'dogrulama' => 'resmi', 'resmi_kurum' => 'AFAD', 'can_kaybi' => 'yok', 'son_dakika' => true]),
        // Model yanlislikla "kritik" dese de olay turu politika listesinde degilse kritik olmaz.
        haber($yanlis, ['onem' => 60, 'oncelik' => 'kritik', 'olay_turu' => 'diger', 'olay_guncel' => true, 'turkiye_dogrudan' => true, 'dogrulama' => 'resmi', 'resmi_kurum' => 'AFAD', 'can_kaybi' => 'yok']),
    ]);

    $s = sira();
    bekle(($s[0]['baslik'] ?? '') === $kaza, 'çok ölümlü kaza 1. sırada (deprem daha yeni olduğu hâlde)', $s[0]['baslik'] ?? 'boş');
    bekle((int) satir($deprem)['oncelik'] === ONCELIK_NORMAL, 'hasarsız deprem normal sınıfta');
    bekle((int) satir($yanlis)['oncelik'] < ONCELIK_KRITIK, 'olay türü politika listesinde olmayan haber "kritik" dense de kritik olmadı');
});

senaryo('D', 'Rutin Cumhurbaşkanı mesajı, doğrulanmış şehit haberinin önüne geçmez', static function (): void {
    $sehit = 'MSB: Sınavkent\'teki operasyonda iki asker şehit oldu';
    $mesaj = 'Cumhurbaşkanı kutlama mesajı yayımladı';

    ekle([haber($sehit, kritik('sehit', ['olay_yeri' => 'Sınavkent', 'can_kaybi' => 'dogrulandi']))]);
    eskit(130);
    ekle([haber($mesaj, ['kategori' => 'politika', 'onem' => 90, 'oncelik' => 'yuksek', 'olay_turu' => 'diger', 'olay_guncel' => true, 'turkiye_dogrudan' => true, 'dogrulama' => 'resmi', 'resmi_kurum' => 'Cumhurbaşkanlığı', 'can_kaybi' => 'yok', 'son_dakika' => true])]);

    $s = sira();
    bekle(($s[0]['baslik'] ?? '') === $sehit, 'şehit haberi 1. sırada (mesaj daha yeni ve önemi 90 olduğu hâlde)', $s[0]['baslik'] ?? 'boş');
    bekle((int) satir($mesaj)['oncelik'] < ONCELIK_KRITIK, 'rutin mesaj kritik değil');
});

senaryo('E', 'Cumhurbaşkanı\'nın ülke çapında acil güvenlik kararı kritik işlenir', static function (): void {
    $karar = 'Cumhurbaşkanlığı: Ülke genelinde olağanüstü güvenlik tedbirleri yürürlüğe girdi';

    ekle(olaganlar(6));
    $y = ekle([haber($karar, kritik('cumhurbaskani_acil_karar', ['kategori' => 'politika', 'resmi_kurum' => 'Cumhurbaşkanlığı', 'can_kaybi' => 'yok', 'olay_yeri' => 'Türkiye', 'onem' => 97]))]);
    $k = satir($karar);
    $s = sira();

    bekle((int) $k['oncelik'] === ONCELIK_KRITIK && (int) $k['aciliyet'] === ACILIYET_HEMEN, 'kritik sınıf, "doğrulanınca hemen" aciliyeti');
    bekle(($y['kritik_yayinlanan'] ?? 0) === 1 && $k['durum'] === HABER_YAYINDA, 'hızlı yoldan yayımlandı');
    bekle(($s[0]['baslik'] ?? '') === $karar, 'ana sayfada 1. sırada', $s[0]['baslik'] ?? 'boş');
});

senaryo('F', 'Yıldönümü, anma, benzetme ve "flaş" başlıkları kritik sayılmaz', static function (): void {
    $anma    = 'Sınavkent\'teki uçak kazasının 10. yılında şehitler anıldı';
    $yanlis  = 'Örnekova şehitleri için anma töreni düzenlendi';
    $benzetme = 'Bakandan deprem gibi açıklama: Yeni düzenleme geliyor';
    $spor    = 'FLAŞ! ŞOK! Son dakika: Teknik direktörden bomba açıklama';

    ekle([
        haber($anma, ['onem' => 55, 'oncelik' => 'normal', 'olay_turu' => 'askeri_hava_araci_kazasi', 'olay_guncel' => false, 'turkiye_dogrudan' => true, 'dogrulama' => 'tek_kaynak', 'can_kaybi' => 'yok']),
        // Model "kritik" dese bile olay guncel degilse site kritik saymaz.
        haber($yanlis, ['onem' => 60, 'oncelik' => 'kritik', 'olay_turu' => 'sehit', 'olay_guncel' => false, 'turkiye_dogrudan' => true, 'dogrulama' => 'resmi', 'resmi_kurum' => 'Valilik', 'can_kaybi' => 'yok']),
        haber($benzetme, ['kategori' => 'politika', 'onem' => 62, 'oncelik' => 'normal', 'olay_turu' => 'diger', 'olay_guncel' => true, 'turkiye_dogrudan' => true, 'dogrulama' => 'tek_kaynak']),
        haber($spor, ['kategori' => 'spor', 'onem' => 40, 'oncelik' => 'normal', 'olay_turu' => 'diger', 'olay_guncel' => true, 'turkiye_dogrudan' => true, 'dogrulama' => 'tek_kaynak']),
        // Alanlari hic gondermeyen eski ajan: basliktaki kelimeler bir sey tetiklemez.
        haber('Şehit yakınlarına deprem konutu: son dakika flaş karar bekleniyor', ['onem' => 50]),
    ]);

    foreach ([$anma, $yanlis, $benzetme, $spor, 'Şehit yakınlarına deprem konutu: son dakika flaş karar bekleniyor'] as $b) {
        $h = satir($b);
        bekle((int) $h['oncelik'] === ONCELIK_NORMAL && (int) $h['aciliyet'] === ACILIYET_NORMAL, 'normal sınıf, normal kuyruk: ' . mb_substr($b, 0, 52), ONCELIK_ADLAR[(int) $h['oncelik']]);
    }

    bekle(array_filter(sira(), static fn (array $h): bool => $h['etkin'] >= 2) === [], 'ana sayfada kritik ya da yüksek sınıfta sıralanan haber yok');
});

senaryo('G', 'Aynı olayın on kopyası tek olayda birleşir', static function (): void {
    $ilk = 'Sınavkent\'te askerî nakliye uçağı düştü';
    $basliklar = [
        'MSB: Sınavkent\'te askerî uçak kaza kırıma uğradı', 'Sınavkent\'te düşen askerî uçak için arama çalışması',
        'Askerî nakliye uçağı Sınavkent kırsalına düştü', 'Sınavkent\'teki uçak kazasıyla ilgili açıklama',
        'Son dakika: Sınavkent\'te askerî uçak düştü', 'Hava Kuvvetleri\'ne ait uçak Sınavkent\'te düştü',
        'Sınavkent Valiliği: Bölgeye ekipler sevk edildi', 'Sınavkent\'te uçak kazası: ekipler bölgede',
        'Askerî uçak kazası sonrası Sınavkent\'te son durum',
    ];

    ekle([haber($ilk, kritik('askeri_hava_araci_kazasi', ['olay_yeri' => 'Sınavkent']))]);
    eskit(40);
    $gelismeIlk = (string) satir($ilk)['gelisme_zamani'];

    // Dokuz kopya uc ayri teslimde, farkli kaynaklardan; yeni onemli bilgi yok.
    foreach (array_chunk($basliklar, 3) as $parca) {
        ekle(array_map(static fn (string $b): array => haber($b, kritik('askeri_hava_araci_kazasi', ['olay_yeri' => 'Sınavkent', 'gelisme' => 'ayni_bilgi'])), $parca));
    }

    $olaylar = db()->query('SELECT COUNT(DISTINCT olay_kimlik) AS olay, COUNT(*) AS haber, MAX(gelisme_zamani) AS gelisme FROM haberler')->fetch();
    bekle((int) $olaylar['haber'] === 10 && (int) $olaylar['olay'] === 1, 'on haber tek olay kimliğinde', $olaylar['haber'] . ' haber, ' . $olaylar['olay'] . ' olay');
    bekle((string) $olaylar['gelisme'] === $gelismeIlk, 'kopyalar olayın güncellik süresini yenilemedi (son önemli gelişme zamanı aynı)', $gelismeIlk . ' / ' . $olaylar['gelisme']);

    $s = sira();
    $ayni = array_filter($s, static fn (array $h): bool => mb_stripos($h['baslik'], 'Sınavkent') !== false);
    bekle(count($ayni) === 1, 'ana sayfada olaydan tek haber', count($ayni) . ' haber');

    // Yeni onemli gelisme: can kaybi resmen dogrulandi.
    $yeni = 'MSB: Sınavkent\'teki uçak kazasında 5 asker şehit oldu';
    ekle([haber($yeni, kritik('askeri_hava_araci_kazasi', ['olay_yeri' => 'Sınavkent', 'gelisme' => 'yeni_gelisme', 'can_kaybi' => 'dogrulandi', 'onem' => 96]))]);
    $y = satir($yeni);
    $s = sira();
    $ayni = array_values(array_filter($s, static fn (array $h): bool => mb_stripos($h['baslik'], 'Sınavkent') !== false));

    bekle($y['durum'] === HABER_YAYINDA && $y['olay_kimlik'] === satir($ilk)['olay_kimlik'], 'yeni gelişme aynı olaya bağlandı ve tekrar süzgecine takılmadan yayımlandı');
    bekle(count($ayni) === 1 && $ayni[0]['baslik'] === $yeni && ($s[0]['baslik'] ?? '') === $yeni, 'ana sayfada olayın haberi artık yeni gelişme ve 1. sırada', ($s[0]['baslik'] ?? '') . ' / ' . count($ayni));
    bekle((string) $y['gelisme_zamani'] > $gelismeIlk, 'yeni önemli gelişme güncellik süresini yeniledi');

    $gecmis = oncelik_gecmis((string) $y['olay_kimlik'], 100);
    $turler = array_count_values(array_column($gecmis, 'tur'));
    bekle(count($gecmis) === 11 && ($turler['ilk'] ?? 0) === 1 && ($turler['gelisme'] ?? 0) === 1 && ($turler['kaynak'] ?? 0) === 9, 'kaynak ve güncelleme geçmişi saklandı (1 ilk, 9 kaynak, 1 gelişme)', json_encode($turler));
});

senaryo('H', 'Kritik haber olağan haberlerden sonra manşette kalır; güncelliğini yitirince kilitlenmez', static function (): void {
    $kaza = 'Örnekova\'da askerî helikopter düştü: MSB duyurdu';

    ekle([haber($kaza, kritik('askeri_hava_araci_kazasi', ['olay_yeri' => 'Örnekova']))]);

    foreach ([1, 2] as $tur) {
        eskit(125);
        ekle(olaganlar(14, "Dalga $tur"));
    }

    $s = sira();
    bekle(($s[0]['baslik'] ?? '') === $kaza, 'iki yeni toplama dalgasından (28 olağan haber, 4 saat) sonra da 1. sırada', $s[0]['baslik'] ?? 'boş');

    // Metin duzeltmesi gunceligi yenilemez.
    $h = satir($kaza);
    haber_guncelle((int) $h['id'], ['baslik' => $kaza, 'icerik' => $h['icerik'] . ' Yazım düzeltildi.', 'ozet' => $h['ozet'], 'gorsel_url' => $h['gorsel_url'], 'kategori_id' => $h['kategori_id'], 'onem' => $h['onem']]);
    bekle((string) satir($kaza)['gelisme_zamani'] === (string) $h['gelisme_zamani'], 'metin düzeltmesi son önemli gelişme zamanını değiştirmedi');

    // Kritik sure (6 saat) doldu: manset kilitli kalmaz.
    eskit(130);
    ekle([haber('Sınavkent\'te baraj kapağı arızası: valilik tahliye başlattı', kritik('ulke_capi_olaganustu', ['olay_yeri' => 'Sınavkent', 'resmi_kurum' => 'Sınavkent Valiliği', 'can_kaybi' => 'yok', 'onem' => 88]))]);
    $s = sira();
    $eski = array_values(array_filter($s, static fn (array $x): bool => $x['baslik'] === $kaza))[0] ?? null;
    bekle(($s[0]['baslik'] ?? '') !== $kaza && $eski !== null && $eski['etkin'] === ONCELIK_YUKSEK, '6 saat yeni gelişme olmayınca kritik haber üst haberlere indi; yeni kritik haber 1. sırada', ($s[0]['baslik'] ?? '') . ' / etkin ' . ($eski['etkin'] ?? '?'));

    eskit(20 * 60);
    $s = sira();
    $eski = array_values(array_filter($s, static fn (array $x): bool => $x['baslik'] === $kaza))[0] ?? null;
    bekle($eski === null || $eski['etkin'] === ONCELIK_NORMAL, '24 saatten sonra olağan sırada (koşulsuz kilit yok)');
});

senaryo('I', 'Doğrulanmamış sosyal medya iddiası otomatik yayımlanmaz, acil doğrulama kuyruğuna düşer', static function (): void {
    $iddia = 'Sosyal medyada iddia: Denemeköy\'de askerî araç konvoyuna saldırı';

    ekle(olaganlar(3));
    $y = ekle([haber($iddia, kritik('buyuk_teror', ['olay_yeri' => 'Denemeköy', 'dogrulama' => 'iddia', 'resmi_kurum' => '', 'can_kaybi' => 'dogrulanmadi']))]);
    ic('yayinla');   // ana sayfa acilisindaki "taslaklari yayinla" turu da yayimlamamali
    $h = satir($iddia);

    bekle($h['durum'] === HABER_TASLAK, 'yayımlanmadı (toplu otomatik yayın turundan sonra da)', $h['durum']);
    bekle($h['inceleme'] === 'acil' && ($y['acil_inceleme'] ?? []) === [$iddia], 'acil inceleme kuyruğunda; ajana da bildirildi');
    bekle(array_column(oncelik_inceleme_bekleyenler(), 'baslik') === [$iddia], 'paneldeki inceleme listesinde');
    bekle(!in_array($iddia, array_column(sira(), 'baslik'), true), 'ana sayfada yok');

    // Tek kaynakli kritik haber de (resmi aciklama ya da bagimsiz ikinci kaynak yok) bekler.
    $tek = 'Örnekova\'da büyük patlama: çok sayıda ölü olduğu öne sürüldü';
    ekle([haber($tek, kritik('buyuk_can_kaybi', ['olay_yeri' => 'Örnekova', 'dogrulama' => 'tek_kaynak', 'resmi_kurum' => '']))]);
    bekle(satir($tek)['inceleme'] === 'acil', 'tek kaynaklı kritik haber de acil incelemede');

    // Ayni ajansin iki kopyasi bagimsiz sayilmaz.
    $kopya = 'Sınavkent\'te köprü çöktü: ölü ve yaralılar var';
    ekle([haber($kopya, kritik('buyuk_can_kaybi', ['olay_yeri' => 'Sınavkent', 'dogrulama' => 'bagimsiz', 'resmi_kurum' => '', 'bagimsiz_kaynak_sayisi' => 1,
        'kaynak_adi' => 'Haber Sitesi A', 'ek_kaynaklar' => [['ad' => 'Haber Sitesi B', 'url' => 'https://ornek.test/b']]]))]);
    $k = satir($kopya);
    bekle($k['dogrulama'] === 'tek_kaynak' && $k['inceleme'] === 'acil', 'aynı ajansın kopyaları bağımsız kaynak sayılmadı');
});

senaryo('K', 'Editör müdahalesi: gerekçe ve bitiş zamanı zorunlu, otomasyon ezmez, süresi dolunca kalkar', static function (): void {
    $kaza   = 'Sınavkent\'te askerî uçak düştü: MSB açıkladı';
    $editor = 'Olağan haber: buğday rekoltesi 1';

    ekle(olaganlar(5));
    ekle([haber($kaza, kritik('askeri_hava_araci_kazasi', ['olay_yeri' => 'Sınavkent']))]);
    $e = satir(haber_bul_baslik('buğday rekoltesi'));

    $hata = '';

    try {
        oncelik_editor_ayarla((int) $e['id'], ['manset' => 1, 'saat' => 2, 'gerekce' => ''], 1);
    } catch (InvalidArgumentException $x) {
        $hata = $x->getMessage();
    }

    bekle($hata !== '', 'gerekçesiz müdahale reddedildi', $hata);

    oncelik_editor_ayarla((int) $e['id'], ['manset' => 1, 'saat' => 2, 'gerekce' => 'Test: editör kararı'], 1);
    ekle(olaganlar(4, 'Sonraki'));
    $s = sira();
    bekle(($s[0]['id'] ?? 0) === (int) $e['id'] && ($s[1]['baslik'] ?? '') === $kaza, 'editörün manşete aldığı haber 1., kritik haber 2. sırada; yeni teslim bunu değiştirmedi', ($s[0]['baslik'] ?? '') . ' | ' . ($s[1]['baslik'] ?? ''));
    bekle(str_contains($s[0]['neden'], 'Test: editör kararı'), '"neden bu sırada" açıklaması editörün gerekçesini gösteriyor');

    db()->exec('UPDATE haberler SET editor_bitis = NOW() - INTERVAL 1 MINUTE, guncellendi = guncellendi WHERE id = ' . (int) $e['id']);
    $s = sira();
    bekle(($s[0]['baslik'] ?? '') === $kaza, 'müdahalenin süresi dolunca haber kendiliğinden yeniden değerlendirildi (kritik haber 1. sırada)', $s[0]['baslik'] ?? '');

    // Otomatik yayini durdur: haber geri cekilir, yeniden yayimlanmaz, olayin yeni haberi incelemeye duser.
    $k = satir($kaza);
    oncelik_yayin_durdur((int) $k['id'], 'Test: doğrulama bekleniyor');
    ic('yayinla');
    bekle(satir($kaza)['durum'] === HABER_TASLAK, 'yayını durdurulan haber otomatik yayınla geri gelmedi');
    $yeni = 'MSB: Sınavkent\'teki uçak kazasında arama kurtarma sürüyor';
    ekle([haber($yeni, kritik('askeri_hava_araci_kazasi', ['olay_yeri' => 'Sınavkent', 'gelisme' => 'yeni_gelisme']))]);
    bekle(satir($yeni)['durum'] === HABER_TASLAK && satir($yeni)['inceleme'] === 'acil', 'yayını durdurulan olayın yeni haberi de editör incelemesine düştü');
});

function haber_bul_baslik(string $parca): string
{
    $ifade = db()->prepare('SELECT baslik FROM haberler WHERE baslik LIKE :b ORDER BY id LIMIT 1');
    $ifade->execute(['b' => '%' . $parca . '%']);

    return (string) $ifade->fetchColumn();
}

senaryo('J', 'Kritik haber yayımlanınca okurun gördüğü ana sayfa ve sayfa kopyaları (gerçek HTTP)', static function () use ($vt): void {
    $port   = 8097;
    $kok    = dirname(__DIR__);
    $adres  = "http://127.0.0.1:$port";
    $anahtar = 'test-' . bin2hex(random_bytes(8));

    db()->exec('DELETE FROM ajan_anahtarlari');
    db()->prepare('INSERT INTO ajan_anahtarlari (ad, anahtar_hash, aktif) VALUES (:a, :h, 1)')
        ->execute(['a' => 'test', 'h' => hash('sha256', $anahtar)]);

    foreach (glob($kok . '/onbellek/sayfa/*.html') ?: [] as $d) {
        if (basename($d) !== 'index.html') {
            @unlink($d);
        }
    }

    $sunucu = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $kok],
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $boru,
        $kok,
        ['VALENTRA_VT' => $vt, 'PATH' => (string) getenv('PATH')]
    );

    $al = static function (string $yol, ?string $govde = null) use ($adres, $anahtar): array {
        $c = curl_init($adres . $yol);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60]);

        if ($govde !== null) {
            curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $govde,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $anahtar]]);
        }

        $yanit = (string) curl_exec($c);
        $kod   = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
        $bas   = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
        curl_close($c);

        return ['kod' => $kod, 'baslik' => substr($yanit, 0, $bas), 'govde' => substr($yanit, $bas)];
    };

    $ilkSlayt = static function (string $html): array {
        preg_match('~aria-label="1 / \d+">\s*<a href="([^"]+)".*?<h2[^>]*>(.*?)</h2>~su', $html, $m);

        return ['adres' => html_entity_decode($m[1] ?? ''), 'baslik' => html_entity_decode(trim(strip_tags($m[2] ?? '')), ENT_QUOTES)];
    };

    try {
        for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
            usleep(100000);
        }

        ekle(olaganlar(10));
        eskit(30);

        $once = $al('/');
        $onceSlayt = $ilkSlayt($once['govde']);
        $kopya = $al('/');
        bekle($once['kod'] === 200 && $onceSlayt['baslik'] !== '', 'ana sayfa açıldı; ilk manşet olağan bir haber', $onceSlayt['baslik']);
        bekle(str_contains($kopya['baslik'], 'X-Valentra-Onbellek: var'), 'ikinci istek sayfa kopyasından verildi (önbellek devrede)');

        $kaza = 'Sınavkent\'te askerî uçak düştü: MSB açıklama yaptı';
        $t0   = microtime(true);
        $y    = $al('/api/ingest.php', (string) json_encode(['haberler' => [
            haber($kaza, kritik('askeri_hava_araci_kazasi', ['olay_yeri' => 'Sınavkent', 'dogrulama_ani' => time()])),
        ]], JSON_UNESCAPED_UNICODE));
        $yanit = json_decode($y['govde'], true) ?: [];
        bekle($y['kod'] === 200 && ($yanit['kritik_yayinlanan'] ?? 0) === 1, 'teslim ucu (api/ingest.php) kritik haberi hızlı yoldan yayımladı', $y['kod'] . ' ' . mb_substr($y['govde'], 0, 200));

        $sonra = $al('/');
        $sure  = microtime(true) - $t0;
        $slayt = $ilkSlayt($sonra['govde']);
        bekle($slayt['baslik'] === $kaza, 'okurun gördüğü ana sayfada ilk manşet artık kritik haber', $slayt['baslik']);
        bekle(!str_contains($sonra['baslik'], 'X-Valentra-Onbellek: var'), 'eski sayfa kopyası verilmedi; ana sayfa baştan üretildi');
        bekle($sure < 60, sprintf('doğrulamadan ana sayfada görünmeye %.1f sn (hedef 60 sn)', $sure));

        $h = $al($slayt['adres']);
        bekle($h['kod'] === 200 && str_contains($h['govde'], 'askerî uçak düştü'), 'haber sayfası açılıyor', (string) $h['kod']);

        $kayit = json_decode((string) satir($kaza)['karar_kaydi'], true);
        bekle(isset($kayit['gecikme_sn']['kaynak_tarama'], $kayit['gecikme_sn']['dogrulama_yayin']) && $kayit['gecikme_sn']['dogrulama_yayin'] < 60,
            'karar kaydında ölçüm: kaynak→tarama ' . ($kayit['gecikme_sn']['kaynak_tarama'] ?? '?') . ' sn, doğrulama→yayın ' . ($kayit['gecikme_sn']['dogrulama_yayin'] ?? '?') . ' sn');

        // Olagan haber kopyayi gecersiz kilmaz, kritik haberin yerini de almaz.
        ekle(olaganlar(3, 'Yeni'));
        $s2 = $ilkSlayt($al('/')['govde']);
        bekle($s2['baslik'] === $kaza, 'sonradan gelen olağan haberlerden sonra da okurun gördüğü ilk manşet kritik haber', $s2['baslik']);
    } finally {
        proc_terminate($sunucu);
        proc_close($sunucu);
    }
});

/* ---------------------------------------------------------------------------
 * 13.11.2026: son dakika bandi, ajans / bagimsiz kaynak, eski olayin yeniden
 * paylasimi, sayi degisikligi, kaynak hata bekletmesi (site sahibinin yedi
 * senaryosu). Bant bu testlerde dogrudan oncelik_bant() ile okunur: ayar
 * 'onizleme' iken site gorunumu degismez, panel ayni fonksiyonu gosterir.
 * ------------------------------------------------------------------------- */

/** Bandin basliklari. */
function bant(): array
{
    return array_map(static fn (array $s): string => (string) $s['baslik'], oncelik_bant(6));
}

senaryo('L', 'Şehit haberi: doğrulanınca manşet ve son dakika bandı; kronolojik akış ayrı', static function (): void {
    ekle(olaganlar(8));
    eskit(20);

    $sehit = 'Sınavkent\'te bir asker şehit oldu: MSB açıkladı';
    ekle([haber($sehit, kritik('sehit', ['can_kaybi' => 'dogrulandi', 'olay_yeri' => 'Sınavkent']))]);
    ekle(olaganlar(3, 'Sonraki'));

    $sira = sira();
    bekle($sira[0]['baslik'] === $sehit, 'manşette ilk sıra şehit haberi', $sira[0]['baslik']);
    bekle(bant() === [$sehit], 'son dakika bandında yalnızca şehit haberi var', implode(' | ', bant()));

    $serit = array_column(haber_son_dakika_seridi(), 'baslik');
    bekle(count(array_diff($serit, bant())) >= 1,
        'kronolojik "son haberler" akışı banttan bağımsız: olağan haberler orada', implode(' | ', $serit));

    $k = oncelik_bant_karari(satir($sehit));
    bekle($k['bantta'] && str_contains($k['gerekce'][0], 'resmî açıklama'), '"Neden bu öncelik?" bant gerekçesini veriyor', $k['gerekce'][0]);

    bekle(haber_son_dakika_bandi() === [], 'ayar önizlemedeyken sitede bant çıkmıyor (eski düzen sürüyor)');
    ayar_yaz(ONCELIK_BANT_AYARI, 'yayin');
    bekle(oncelik_bant_yayinda(), 'panelden yayına alınınca bant sitede açılıyor');
    ayar_yaz(ONCELIK_BANT_AYARI, 'onizleme');
});

senaryo('M', 'Askerî helikopter kazası: kaza doğrulandı, can kaybı doğrulanmadı', static function (): void {
    $kaza = 'Sınavkent\'te askerî helikopter kaza kırıma uğradı';
    ekle([haber($kaza, kritik('askeri_hava_araci_kazasi', ['olay_yeri' => 'Sınavkent']))]);
    $s = satir($kaza);
    bekle($s['durum'] === HABER_YAYINDA && (int) $s['oncelik'] === ONCELIK_KRITIK, 'kaza haberi kritik olarak yayımlandı');
    bekle($s['can_kaybi'] === 'dogrulanmadi', 'can kaybı "doğrulanmadı" olarak kayıtlı');
    bekle(bant() === [$kaza], 'bantta');

    $iddia = 'Sınavkent\'teki helikopter kazasında 4 asker şehit oldu';
    ekle([haber($iddia, kritik('askeri_hava_araci_kazasi', ['olay_yeri' => 'Sınavkent', 'dogrulama' => 'tek_kaynak', 'resmi_kurum' => '']))]);
    $i = satir($iddia);
    bekle($i['durum'] !== HABER_YAYINDA && $i['inceleme'] === 'acil', 'can kaybını kesin veren doğrulanmamış haber yayımlanmadı, acil incelemede', $i['durum'] . '/' . $i['inceleme']);
    bekle(!in_array($iddia, bant(), true), 'doğrulanmamış haber bantta değil');
});

senaryo('N', 'Cumhurbaşkanı kararı ve geniş etkili ekonomi kararı; rutin açıklama ve fiyat haberi bantta değil', static function (): void {
    $karar = 'Cumhurbaşkanı kararı Resmî Gazete\'de: ülke genelinde okullar bir hafta tatil';
    $ucret = 'Asgari ücret açıklandı: yeni tutar Resmî Gazete\'de yayımlandı';
    $rutin = 'Cumhurbaşkanı açılış töreninde konuştu';
    $fiyat = 'Benzine bu gece zam geliyor';
    $dis   = 'Uzak bir ülkede hükûmet istifa etti';

    ekle([
        haber($karar, kritik('cumhurbaskani_acil_karar', ['resmi_kurum' => 'Cumhurbaşkanlığı', 'can_kaybi' => 'yok'])),
        haber($ucret, kritik('genis_etkili_ekonomi', ['resmi_kurum' => 'Çalışma ve Sosyal Güvenlik Bakanlığı', 'can_kaybi' => 'yok', 'kategori' => 'ekonomi'])),
        haber($rutin, ['oncelik' => 'normal', 'olay_turu' => 'diger', 'olay_guncel' => true, 'turkiye_dogrudan' => true, 'dogrulama' => 'resmi', 'resmi_kurum' => 'Cumhurbaşkanlığı', 'can_kaybi' => 'yok', 'onem' => 60, 'son_dakika' => true]),
        haber($fiyat, ['oncelik' => 'normal', 'olay_turu' => 'diger', 'olay_guncel' => true, 'turkiye_dogrudan' => true, 'dogrulama' => 'tek_kaynak', 'can_kaybi' => 'yok', 'onem' => 70, 'kategori' => 'ekonomi', 'son_dakika' => true]),
        haber($dis, ['oncelik' => 'yuksek', 'olay_turu' => 'diger', 'olay_guncel' => true, 'turkiye_dogrudan' => false, 'dogrulama' => 'bagimsiz', 'bagimsiz_kaynak_sayisi' => 2,
            'ek_kaynaklar' => [['ad' => 'Başka Kaynak', 'url' => 'https://ornek.test/b']], 'can_kaybi' => 'yok', 'onem' => 75, 'kategori' => 'dunya']),
    ]);

    $b = bant();
    bekle(in_array($karar, $b, true), 'ülke genelini etkileyen Cumhurbaşkanı kararı bantta');
    bekle(in_array($ucret, $b, true) && (int) satir($ucret)['oncelik'] === ONCELIK_KRITIK, 'açıklanan asgari ücret kararı kritik ve bantta');
    bekle(!in_array($rutin, $b, true), 'rutin Cumhurbaşkanı konuşması bantta değil ("son dakika" işaretli olsa da)');
    bekle(!in_array($fiyat, $b, true), 'tek ürünün fiyat değişikliği bantta değil');
    bekle(!in_array($dis, $b, true), 'Türkiye\'yi doğrudan ilgilendirmeyen dış haber bantta değil', oncelik_bant_karari(satir($dis))['gerekce'][0]);

    $sira = array_column(sira(), 'baslik');
    bekle(array_search($rutin, $sira, true) > max(array_search($karar, $sira, true), array_search($ucret, $sira, true)), 'manşet sırasında iki karar rutin konuşmanın önünde');
});

senaryo('O', 'Eski bir olayın bugün yeniden paylaşılması son dakika gibi işlenmez', static function (): void {
    ekle(olaganlar(5));
    $eski = 'O görüntüler yeniden gündemde: Sınavkent\'teki büyük patlama';
    ekle([haber($eski, kritik('buyuk_kaza', ['olay_guncel' => false, 'olay_tarihi' => date('Y-m-d', time() - 400 * 86400), 'can_kaybi' => 'dogrulandi', 'son_dakika' => true, 'kaynak_zamani' => time() - 60]))]);

    $s = satir($eski);
    bekle((int) $s['oncelik'] === ONCELIK_NORMAL, 'kaynak bugün yayımlasa da olay güncel değil: normal sınıf', ONCELIK_ADLAR[(int) $s['oncelik']]);
    bekle(!in_array($eski, bant(), true), 'bantta değil');
    bekle(sira()[0]['baslik'] !== $eski, 'manşette ilk sırada değil');
    bekle(str_contains(implode(' ', json_decode((string) $s['karar_kaydi'], true)['gerekce']), 'güncel değil'), 'karar kaydı nedenini yazıyor');
});

senaryo('P', 'Aynı haber üç kaynaktan: tek olay; aynı ajansın iki sitedeki kopyası iki doğrulama değil', static function (): void {
    $ortak = ['olay_yeri' => 'Sınavkent', 'resmi_kurum' => '', 'can_kaybi' => 'yok'];

    // Iki site de AA'nin haberini veriyor; model "bagimsiz, 2 kaynak" dese de tek kaynak.
    $ajans = 'Sınavkent\'te rafineride büyük patlama';
    ekle([haber($ajans, kritik('buyuk_kaza', $ortak + [
        'dogrulama' => 'bagimsiz', 'bagimsiz_kaynak_sayisi' => 2, 'kaynak_adi' => 'TRT Haber Son Dakika', 'kaynak_ajans' => 'AA',
        'ek_kaynaklar' => [['ad' => 'Örnek Gazete', 'url' => 'https://ornek.test/aa-kopya', 'ajans' => 'Anadolu Ajansı']],
    ]))]);
    $a = satir($ajans);
    bekle($a['dogrulama'] === 'tek_kaynak' && $a['durum'] !== HABER_YAYINDA && $a['inceleme'] === 'acil',
        'AA haberini veren iki site tek kaynak sayıldı; kritik haber yayımlanmadı, acil incelemede', $a['dogrulama'] . '/' . $a['durum']);
    bekle($a['kaynak_ajans'] === 'AA', 'haberi ilk veren ajans kaydedildi');
    bekle(bant() === [], 'bant boş');

    // Ucuncu kaynak kendi muhabiriyle veriyor: AA + bagimsiz kaynak = iki bagimsiz dogrulama.
    $uc = 'Sınavkent rafinerisindeki patlama: üç kaynak doğruladı';
    ekle([haber($uc, kritik('buyuk_kaza', $ortak + [
        'dogrulama' => 'bagimsiz', 'bagimsiz_kaynak_sayisi' => 2, 'kaynak_adi' => 'TRT Haber Son Dakika', 'kaynak_ajans' => 'AA', 'gelisme' => 'yeni_gelisme',
        'ek_kaynaklar' => [
            ['ad' => 'Örnek Gazete', 'url' => 'https://ornek.test/aa-kopya-2', 'ajans' => 'AA'],
            ['ad' => 'Bağımsız Haber', 'url' => 'https://ornek.test/muhabir', 'ajans' => 'Muhabir Adı'],
        ],
    ]))]);
    $u = satir($uc);
    bekle($u['dogrulama'] === 'bagimsiz' && $u['durum'] === HABER_YAYINDA, 'üç kaynağın ikisi bağımsız: doğrulandı ve yayımlandı', $u['dogrulama'] . '/' . $u['durum']);
    bekle($u['olay_kimlik'] === $a['olay_kimlik'], 'iki haber aynı olaya bağlandı');
    bekle(bant() === [$uc], 'bantta olay tek satır', implode(' | ', bant()));
    bekle((int) json_decode((string) $u['karar_kaydi'], true)['bagimsiz_kaynak'] === 2, 'karar kaydı: 3 kaynak, 2 bağımsız');
});

senaryo('R', 'Can kaybı sayısının değişmesi: yeni gelişme, olay güncelliği yenilenir, bantta tek satır', static function (): void {
    $ilk = 'Sınavkent\'te maden ocağında göçük: Valilik 3 can kaybını açıkladı';
    ekle([haber($ilk, kritik('buyuk_can_kaybi', ['resmi_kurum' => 'Sınavkent Valiliği', 'can_kaybi' => 'dogrulandi', 'olay_yeri' => 'Sınavkent']))]);
    eskit(5 * 60);

    $yeni = 'Sınavkent\'teki maden göçüğünde can kaybı 7\'ye yükseldi: Valilik açıkladı';
    ekle([haber($yeni, kritik('buyuk_can_kaybi', ['resmi_kurum' => 'Sınavkent Valiliği', 'can_kaybi' => 'dogrulandi', 'olay_yeri' => 'Sınavkent', 'gelisme' => 'yeni_gelisme']))]);

    $i = satir($ilk);
    $y = satir($yeni);
    bekle($y['durum'] === HABER_YAYINDA && $y['olay_kimlik'] === $i['olay_kimlik'], 'yeni sayı aynı olayın haberi olarak yayımlandı (kopya diye elenmedi)', $y['durum']);
    bekle(strtotime((string) $y['gelisme_zamani']) > strtotime((string) $i['gelisme_zamani']) + 4 * 3600, 'son önemli gelişme zamanı yenilendi');
    bekle(bant() === [$yeni], 'bantta olayın yalnızca güncel sayılı haberi var', implode(' | ', bant()));
    bekle(sira()[0]['baslik'] === $yeni, 'manşette güncel sayı ilk sırada');

    $gecmis = array_column(oncelik_gecmis((string) $y['olay_kimlik']), 'tur');
    bekle(in_array('gelisme', $gecmis, true) && in_array('ilk', $gecmis, true), 'olay geçmişinde ilk haber ve gelişme ayrı ayrı kayıtlı');

    oncelik_duzeltme_yaz((int) $i['id'], 'Can kaybı sayısı Valiliğin son açıklamasıyla 7 olarak güncellendi.');
    bekle(str_contains((string) satir($ilk)['duzeltme'], '7 olarak'), 'eski habere düzeltme notu düşülebiliyor');

    // Ayni sayiyi baska kaynak tekrar ederse guncellik YENILENMEZ.
    eskit(60);
    ekle([haber('Sınavkent maden göçüğü: can kaybı 7 (başka kaynak)', kritik('buyuk_can_kaybi', ['resmi_kurum' => 'Sınavkent Valiliği', 'can_kaybi' => 'dogrulandi', 'olay_yeri' => 'Sınavkent', 'gelisme' => 'ayni_bilgi']))]);
    $t = satir('Sınavkent maden göçüğü: can kaybı 7 (başka kaynak)');
    bekle(strtotime((string) $t['gelisme_zamani']) <= strtotime((string) satir($yeni)['gelisme_zamani']) + 60, 'aynı bilgiyi tekrarlayan kaynak güncelliği yenilemedi');
});

senaryo('S', 'RSS geçici olarak erişilemez: bekletme katlanır, başarıda sıfırlanır; başarısız adres çalışıyor sayılmaz', static function (): void {
    require_once __DIR__ . '/../includes/kaynak_durum.php';

    db()->exec("DELETE FROM kaynaklar WHERE ad = 'Test Beslemesi'");
    db()->exec("INSERT INTO kaynaklar (ad, site_url, besleme_url, tur, kategori, aktif) VALUES ('Test Beslemesi', 'https://ornek.test', 'https://ornek.test/rss', 'rss', 'gundem', 1)");
    $id    = (int) db()->lastInsertId();
    $oku   = static fn (): array => db()->query('SELECT * FROM kaynaklar WHERE id = ' . $id)->fetch();
    $ajana = static fn (): bool => (bool) db()->query('SELECT 1 FROM kaynaklar WHERE aktif = 1 AND id = ' . $id . kaynak_durum_kosulu())->fetch();

    bekle($ajana(), 'yeni kaynak ajana veriliyor');
    bekle(kaynak_durum_ozeti($oku())['sinif'] === 'bilinmiyor', 'hiç okunmamış kaynak "çalışıyor" görünmüyor');

    $bekleme = [];

    foreach ([1, 2, 3] as $n) {
        $bekleme[] = kaynak_durum_yaz($id, 'hata', 'HTTP 503')['bekleme_dk'];
    }

    bekle($bekleme === [15, 30, 60], 'üst üste hatada bekleme katlanıyor: ' . implode(', ', $bekleme) . ' dk');
    bekle(!$ajana(), 'bekletilen kaynak ajana verilmiyor (sunucu zorlanmıyor)');
    bekle(kaynak_durum_ozeti($oku())['sinif'] === 'hata' && $oku()['son_hata'] === 'HTTP 503', 'panelde hata ve nedeni görünüyor');
    bekle(kaynak_hata_beklemesi(12) === KAYNAK_HATA_EN_FAZLA_DK, 'bekleme en fazla ' . KAYNAK_HATA_EN_FAZLA_DK . ' dk');

    db()->exec('UPDATE kaynaklar SET sonraki_deneme = NOW() - INTERVAL 1 MINUTE WHERE id = ' . $id);
    bekle($ajana(), 'bekleme dolunca kaynak yeniden deneniyor');

    kaynak_durum_yaz($id, 'tamam');
    $k = $oku();
    bekle((int) $k['hata_sayisi'] === 0 && $k['sonraki_deneme'] === null && kaynak_durum_ozeti($k)['sinif'] === 'tamam', 'ilk başarılı okumada sayaç sıfırlandı');

    db()->exec('UPDATE kaynaklar SET aralik_dk = 30 WHERE id = ' . $id);
    kaynak_durum_yaz($id, 'bos');
    bekle(!$ajana() && (int) $oku()['hata_sayisi'] === 0, 'tarama aralığı 30 dk: aralık dolmadan yeniden taranmıyor; boş besleme hata sayılmıyor');
    db()->exec('DELETE FROM kaynaklar WHERE id = ' . $id);
});

senaryo('T', 'Editör: banda alma, banttan çıkarma, süre ve gerekçe', static function (): void {
    $kritik = 'Sınavkent\'te büyük orman yangını: AFAD tahliye uyarısı yaptı';
    $olagan = 'Sınavkent\'te belediye yeni otobüs hattı açtı';
    ekle([
        haber($kritik, kritik('kamu_guvenligi_uyarisi', ['resmi_kurum' => 'AFAD', 'can_kaybi' => 'yok', 'olay_yeri' => 'Sınavkent'])),
        haber($olagan, ['oncelik' => 'normal', 'olay_turu' => 'diger', 'olay_guncel' => true, 'turkiye_dogrudan' => true, 'dogrulama' => 'tek_kaynak', 'can_kaybi' => 'yok']),
    ]);
    bekle(bant() === [$kritik], 'otomatik bant: yalnızca kamu güvenliği uyarısı');

    $hata = '';

    try {
        oncelik_bant_ayarla((int) satir($olagan)['id'], 1, 2, '');
    } catch (InvalidArgumentException $e) {
        $hata = $e->getMessage();
    }

    bekle($hata !== '', 'gerekçesiz bant kararı reddedildi', $hata);

    oncelik_bant_ayarla((int) satir($olagan)['id'], 1, 2, 'Test: editör banda aldı');
    oncelik_bant_ayarla((int) satir($kritik)['id'], -1, 2, 'Test: editör banttan çıkardı');
    bekle(bant() === [$olagan], 'editör kararı uygulandı: alınan bantta, çıkarılan değil', implode(' | ', bant()));
    bekle(str_contains(oncelik_bant_karari(satir($olagan))['gerekce'][0], 'Editör banda aldı'), 'açıklama editör kararını gösteriyor');

    db()->exec('UPDATE haberler SET bant_bitis = NOW() - INTERVAL 1 MINUTE, guncellendi = guncellendi');
    bekle(bant() === [$kritik], 'süre dolunca bant otomatik karara döndü', implode(' | ', bant()));

    eskit(ONCELIK_BANT_KRITIK_SAAT * 60 + 5, $kritik);
    bekle(bant() === [], 'yeni gelişme gelmeyen kritik haber ' . ONCELIK_BANT_KRITIK_SAAT . ' saat sonra banttan düşüyor');
    bekle(sira()[0]['baslik'] === $kritik, 'ama manşet/üst haberlerde hâlâ önde (kademeli iniş)', sira()[0]['baslik']);
});

temizle();

echo "\n" . str_repeat('─', 60) . "\n";
echo "Geçen: $gecen   Kalan: $kalan\n";

exit($kalan === 0 ? 0 : 1);
