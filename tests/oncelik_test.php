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
    // Numara bastan baslasin: bazi senaryolar haber tarihini numaradan turetiyor
    // ("NOW() - INTERVAL id MINUTE"); numaralar buyudukce haberler gunler oncesine dusuyordu.
    db()->exec('ALTER TABLE haberler AUTO_INCREMENT = 1');
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
    // 14.11.2026: onay bekleme yok; dogrulanmamis haber yayimlanir ama kritik (manset adayi) olmaz.
    bekle($i['durum'] === HABER_YAYINDA && $i['inceleme'] === '' && (int) $i['oncelik'] === ONCELIK_YUKSEK, 'can kaybı doğrulanmadan "şehit oldu" yazan haber onay beklemeden yayımlandı ama kritik değil (yüksek)', $i['durum'] . '/' . $i['oncelik']);
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

senaryo('I', 'Doğrulanmamış haber onay beklemez ama manşet adayı olmaz; doğrulama gelince olay kritik olur', static function (): void {
    $iddia = 'Sosyal medyada iddia: Denemeköy\'de askerî araç konvoyuna saldırı';

    ekle(olaganlar(3));
    $y = ekle([haber($iddia, kritik('buyuk_teror', ['olay_yeri' => 'Denemeköy', 'dogrulama' => 'iddia', 'resmi_kurum' => '', 'can_kaybi' => 'dogrulanmadi']))]);
    ic('yayinla');   // ana sayfa acilisindaki "taslaklari yayinla" turu da yayimlamamali
    $h = satir($iddia);

    // 14.11.2026 (site sahibi: "bana sormadan haberleri kabul et"): iddia onay beklemez,
    // ama normal sinifta yayimlanir; kritik ya da yuksek sirada yer almaz.
    bekle($h['durum'] === HABER_YAYINDA && $h['inceleme'] === '' && ($y['acil_inceleme'] ?? []) === [], 'iddia onay beklemeden yayımlandı', $h['durum']);
    bekle((int) $h['oncelik'] === ONCELIK_NORMAL && $h['dogrulama'] === 'iddia', 'normal sınıfta; doğrulama durumu "iddia" olarak kayıtlı', (string) $h['oncelik']);
    bekle(oncelik_inceleme_bekleyenler() === [], 'inceleme kuyruğu boş');
    bekle(array_filter(sira(), static fn (array $x): bool => $x['baslik'] === $iddia && $x['etkin'] >= 2) === [], 'ana sayfada kritik ya da yüksek sırada değil');

    // Tek kaynakli kritik haber de (resmi aciklama ya da bagimsiz ikinci kaynak yok) bekler.
    $tek = 'Örnekova\'da büyük patlama: çok sayıda ölü olduğu öne sürüldü';
    ekle([haber($tek, kritik('buyuk_can_kaybi', ['olay_yeri' => 'Örnekova', 'dogrulama' => 'tek_kaynak', 'resmi_kurum' => '']))]);
    $t = satir($tek);
    bekle($t['durum'] === HABER_YAYINDA && (int) $t['oncelik'] === ONCELIK_YUKSEK, 'tek kaynaklı kritik haber onay beklemeden yüksek sınıfta yayımlandı (manşet adayı değil)', $t['durum'] . '/' . $t['oncelik']);

    // Dogrulama gelince: ayni olayin resmi aciklamali haberi kritik olur ve one gecer.
    $resmi = 'Örnekova Valiliği: Patlamada 12 kişi hayatını kaybetti';
    ekle([haber($resmi, kritik('buyuk_can_kaybi', ['olay_yeri' => 'Örnekova', 'resmi_kurum' => 'Örnekova Valiliği', 'can_kaybi' => 'dogrulandi', 'gelisme' => 'yeni_gelisme']))]);
    $r = satir($resmi);
    bekle($r['durum'] === HABER_YAYINDA && (int) $r['oncelik'] === ONCELIK_KRITIK && $r['olay_kimlik'] === $t['olay_kimlik'] && (sira()[0]['baslik'] ?? '') === $resmi,
        'resmî açıklama gelince aynı olayın haberi kritik oldu ve 1. sıraya çıktı', $r['oncelik'] . ' / ' . (sira()[0]['baslik'] ?? ''));

    // Ayni ajansin iki kopyasi bagimsiz sayilmaz.
    $kopya = 'Sınavkent\'te köprü çöktü: ölü ve yaralılar var';
    ekle([haber($kopya, kritik('buyuk_can_kaybi', ['olay_yeri' => 'Sınavkent', 'dogrulama' => 'bagimsiz', 'resmi_kurum' => '', 'bagimsiz_kaynak_sayisi' => 1,
        'kaynak_adi' => 'Haber Sitesi A', 'ek_kaynaklar' => [['ad' => 'Haber Sitesi B', 'url' => 'https://ornek.test/b']]]))]);
    $k = satir($kopya);
    bekle($k['dogrulama'] === 'tek_kaynak' && (int) $k['oncelik'] === ONCELIK_YUKSEK, 'aynı ajansın kopyaları bağımsız kaynak sayılmadı (haber kritik olmadı)');
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

    ayar_yaz(ONCELIK_BANT_AYARI, 'onizleme');
    bekle(haber_son_dakika_bandi() === [] && !oncelik_bant_yayinda(), 'ayar önizlemedeyken sitede bant çıkmıyor (eski düzen sürüyor)');
    ayar_yaz(ONCELIK_BANT_AYARI, 'yayin');
    bekle(oncelik_bant_yayinda(), 'panelden yayına alınınca bant sitede açılıyor');
    ayar_sil(ONCELIK_BANT_AYARI);
    bekle(oncelik_bant_yayinda(), 'ayar hiç yazılmamışsa bant yayında (varsayılan)');
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
    bekle($i['durum'] === HABER_YAYINDA && (int) $i['oncelik'] === ONCELIK_YUKSEK, 'can kaybını kesin veren doğrulanmamış haber onay beklemeden yayımlandı ama kritik değil', $i['durum'] . '/' . $i['oncelik']);
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
    bekle($a['dogrulama'] === 'tek_kaynak' && $a['durum'] === HABER_YAYINDA && (int) $a['oncelik'] === ONCELIK_YUKSEK,
        'AA haberini veren iki site tek kaynak sayıldı; haber yayımlandı ama kritik olmadı', $a['dogrulama'] . '/' . $a['oncelik']);
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

/* ---------------------------------------------------------------------------
 * 13.11.2026: manset sistematigi. Buyuk yayinlarin ortak gundemi (gundem
 * puani) ve manset gorseli kurali (bkz. includes/manset_sinyal.php).
 * ------------------------------------------------------------------------- */

senaryo('U', 'Manşet sistematiği: aynı olayı kaç büyük yayın gündemine aldıysa haber o kadar yükselir', static function (): void {
    require_once __DIR__ . '/../includes/manset_sinyal.php';
    ayar_yaz(MANSET_SINYAL_AYARI, '[]');

    $olagan = ['oncelik' => 'normal', 'olay_turu' => 'diger', 'olay_guncel' => true, 'turkiye_dogrudan' => true, 'dogrulama' => 'tek_kaynak', 'can_kaybi' => 'yok', 'onem' => 60];
    $faiz   = 'Merkez Bankası politika faizini yüzde 40\'a indirdi';
    $kopru  = 'Sınavkent\'te yeni köprü trafiğe açıldı';
    $yasa   = 'Meclis yeni vergi paketini kabul etti';
    $mac    = 'Sınavspor derbide rakibini üç golle yendi';

    $s = manset_sinyal_yaz([
        ['baslik' => 'Merkez Bankası faiz kararını açıkladı: politika faizi yüzde 40', 'yayin' => 'hurriyet.com.tr'],
        ['baslik' => 'Merkez Bankası politika faizini yüzde 40\'a düşürdü', 'yayin' => 'milliyet.com.tr'],
        ['baslik' => 'Son dakika: Merkez Bankası politika faizi yüzde 40 oldu', 'yayin' => 'ntv.com.tr'],
        ['baslik' => 'Merkez Bankası\'ndan faiz kararı: politika faizi yüzde 40\'a indi', 'yayin' => 'haberturk.com'],
        ['baslik' => 'Merkez Bankası politika faizini yüzde 40\'a indirdi', 'yayin' => 'trthaber.com', 'manset' => true],
        ['baslik' => 'Meclis yeni vergi paketini kabul etti: oylama tamamlandı', 'yayin' => 'sozcu.com.tr'],
        ['baslik' => 'Yeni vergi paketi Meclis\'te kabul edildi', 'yayin' => 'sabah.com.tr'],
        ['baslik' => 'Sınavkent\'te yeni köprü trafiğe açıldı', 'yayin' => 'cnnturk.com'],
        ['baslik' => 'Sınavspor derbide rakibini üç golle yendi', 'yayin' => 'hurriyet.com.tr'],
        ['baslik' => 'Sınavspor derbide rakibini üç golle yendi: maçın özeti', 'yayin' => 'milliyet.com.tr'],
        ['baslik' => 'Derbide Sınavspor rakibini üç golle yendi', 'yayin' => 'ntv.com.tr'],
        ['baslik' => 'Tanınmayan yayının haberi sayılmaz: Merkez Bankası politika faizini yüzde 40\'a indirdi', 'yayin' => 'ornek.test'],
    ]);
    bekle($s['kayitli'] === 11, 'listede olmayan yayının başlığı alınmadı (11 başlık kayıtlı)', (string) $s['kayitli']);

    ekle([
        haber($kopru, $olagan),
        haber($yasa, $olagan + ['kategori' => 'politika']),
        haber($faiz, $olagan + ['kategori' => 'ekonomi', 'kaynak_adi' => 'Yerel Ekonomi Sitesi']),
        haber($mac, $olagan + ['kategori' => 'spor']),
    ]);

    $f = satir($faiz);
    $y = satir($yasa);
    $k = satir($kopru);
    bekle((int) $f['manset_sinyal'] === 6 && (int) $f['onem'] === 80, 'beş büyük yayın + TRT manşeti: gündem puanı 6, önem 60 → 80', $f['manset_sinyal'] . ' / ' . $f['onem']);
    bekle(str_contains((string) $f['manset_yayinlar'], 'Hürriyet') && str_contains((string) $f['manset_yayinlar'], 'manşette'), 'hangi yayınların verdiği kayıtlı: ' . $f['manset_yayinlar']);
    bekle((int) $y['manset_sinyal'] === 2 && (int) $y['onem'] === 66, 'iki yayın: önem 60 → 66', $y['manset_sinyal'] . ' / ' . $y['onem']);
    bekle((int) $k['manset_sinyal'] === 1 && (int) $k['onem'] === 60, 'tek yayının verdiği haber yükselmedi (puan 1, önem 60)', $k['manset_sinyal'] . ' / ' . $k['onem']);
    bekle((int) satir($mac)['onem'] === 60, 'spor haberi bu sistematiğin dışında');

    $sira = array_column(sira(), 'baslik');
    bekle($sira[0] === $faiz, 'manşet sırasında beş yayının verdiği haber ilk sırada (haberi yerel bir site yazmış olsa da)', $sira[0]);

    // Olay sonradan buyuyor: uc yayin daha veriyor. Yalnizca fark eklenir.
    $s2 = manset_sinyal_yaz([
        ['baslik' => 'Vergi paketi Meclis\'te kabul edildi: yeni düzenleme neler getiriyor', 'yayin' => 'hurriyet.com.tr'],
        ['baslik' => 'Meclis yeni vergi paketini kabul etti', 'yayin' => 'ntv.com.tr'],
        ['baslik' => 'Meclis yeni vergi paketini kabul etti', 'yayin' => 'haberturk.com'],
    ]);
    $y2 = satir($yasa);
    bekle($s2['yukselen'] === 1 && (int) $y2['manset_sinyal'] === 5 && (int) $y2['onem'] === 78, 'yayındaki haber olay büyüyünce yükseldi: puan 2 → 5, önem 66 → 78 (ek iki kez sayılmadı)', $y2['manset_sinyal'] . ' / ' . $y2['onem']);
    bekle(manset_sinyal_yaz([])['yukselen'] === 0 && (int) satir($yasa)['onem'] === 78, 'aynı liste yeniden gelince puan şişmiyor');

    $acik = implode(' ', oncelik_aciklama(array_merge($y2, ['etkin_sinif' => 1, 'sinif_puani' => 0, 'editor_gecerli' => 0, 'gelisme_dakika' => 5, 'editor_kalan_dakika' => null, 'dalga' => 0, 'etkin_onem' => 78]))['ayrinti']);
    bekle(str_contains($acik, 'Büyük yayınların gündemi') && str_contains($acik, '+18'), '"Neden bu sırada?" gündem puanını ve eki gösteriyor');

    // Kritik haber sistematigin ustunde.
    $sehit = 'Sınavkent\'te bir asker şehit oldu: MSB açıkladı';
    ekle([haber($sehit, kritik('sehit', ['can_kaybi' => 'dogrulandi', 'olay_yeri' => 'Sınavkent', 'onem' => 70]))]);
    bekle(sira()[0]['baslik'] === $sehit, 'kritik haber, gündem puanı yüksek olağan haberin de önünde');

    ayar_yaz(MANSET_SINYAL_AYARI, '[]');
});

senaryo('V', 'Manşet görseli: resimli, denetimden geçmiş (gazete adı yok), yüksek çözünürlüklü', static function (): void {
    require_once __DIR__ . '/../includes/manset_sinyal.php';

    $klasor = haber_gorsel_klasoru();
    @mkdir($klasor, 0775, true);
    $dosyalar = [];
    $gorsel = static function (string $ad, int $genislik) use ($klasor, &$dosyalar): string {
        $url = 'https://ornek.test/gorsel/' . $ad . '.jpg';
        $r   = imagecreatetruecolor($genislik, (int) round($genislik * 0.56));
        imagejpeg($r, $dosyalar[] = $klasor . '/' . sha1($url) . '.jpg', 60);
        imagedestroy($r);

        return $url;
    };

    $h = static fn (array $alan): array => $alan + ['id' => 1, 'oncelik' => ONCELIK_NORMAL, 'gorsel_url' => '', 'gorsel_denetim' => null];

    try {
        $buyuk = $gorsel('buyuk', 1600);
        $kucuk = $gorsel('kucuk', 640);

        $d = manset_gorsel_durumu($h(['gorsel_url' => $buyuk, 'gorsel_denetim' => 1]));
        bekle($d['uygun'], 'denetimden geçmiş 1600 piksellik görsel manşete uygun', $d['neden']);
        bekle(manset_gorsel_durumu($h(['gorsel_url' => $buyuk, 'gorsel_denetim' => 2]))['uygun'], 'logolu görselin yerine konan temiz fotoğraf uygun');

        foreach ([
            'görselsiz haber'                              => $h([]),
            'görseli henüz denetlenmemiş haber'            => $h(['gorsel_url' => $buyuk]),
            'görselinde yayın adı/logosu kalan haber'      => $h(['gorsel_url' => $buyuk, 'gorsel_denetim' => 3]),
            'konu görseline düşmüş haber'                  => $h(['gorsel_url' => $buyuk, 'gorsel_denetim' => 0]),
            'düşük çözünürlüklü (640 piksel) görsel'       => $h(['gorsel_url' => $kucuk, 'gorsel_denetim' => 1]),
            'görseli sunucuya inmemiş (ölçülemeyen) haber' => $h(['gorsel_url' => 'https://ornek.test/gorsel/yok.jpg', 'gorsel_denetim' => 1]),
        ] as $ne => $satir) {
            $d = manset_gorsel_durumu($satir);
            bekle(!$d['uygun'], $ne . ' manşete girmiyor: ' . $d['neden']);
        }

        bekle(manset_gorsel_durumu($h(['oncelik' => ONCELIK_KRITIK, 'gelisme_zamani' => date('Y-m-d H:i:s', time() - 600)]))['uygun'], 'kritik haber görsel beklemeden manşette');
        bekle(manset_gorsel_durumu($h(['editor_manset' => 1, 'editor_bitis' => date('Y-m-d H:i:s', time() + 3600)]))['uygun'], 'editörün manşete aldığı haber görsel beklemeden manşette');

        // Havuz ayrimi: sira korunur, uymayan haber izgaraya duser, kaybolmaz.
        $havuz = [];

        foreach ([[1, $buyuk, 1], [2, $buyuk, null], [3, $kucuk, 1], [4, $buyuk, 2], [5, '', null], [6, $buyuk, 1], [7, $buyuk, 1], [8, $buyuk, 1], [9, $buyuk, 1]] as [$id, $url, $denetim]) {
            $havuz[] = $h(['id' => $id, 'gorsel_url' => $url, 'gorsel_denetim' => $denetim]);
        }

        [$manset, $izgara] = manset_gorsele_gore_ayir($havuz, 5);
        bekle(array_column($manset, 'id') === [1, 4, 6, 7, 8], 'manşet: koşulu tutan ilk beş haber, sırası bozulmadan', implode(',', array_column($manset, 'id')));
        bekle(array_column($izgara, 'id') === [2, 3, 5, 9], 'uymayanlar aynı sırayla ızgarada; hiçbir haber kaybolmadı', implode(',', array_column($izgara, 'id')));

        [$m2, $i2] = manset_gorsele_gore_ayir(array_slice($havuz, 1, 4), 5);
        bekle(count($m2) === 4 && $m2[0]['id'] === 4 && $i2 === [], 'uygun haber azsa manşet boş kalmıyor: sıradaki haberlerle tamamlanıyor', implode(',', array_column($m2, 'id')));
    } finally {
        array_map('unlink', $dosyalar);
    }
});

senaryo('W', 'Kim ne yazdı: aynı olayı hangi yayın hangi başlıkla verdi', static function (): void {
    require_once __DIR__ . '/../includes/kim_ne_yazdi.php';
    require_once __DIR__ . '/../includes/manset_sinyal.php';
    db()->exec('DELETE FROM gundem_basliklari');
    ayar_yaz(KNY_AYARI, '');
    ayar_yaz(MANSET_SINYAL_AYARI, '[]');

    $t = time();
    $g = static fn (string $yayin, string $baslik, int $dk, string $ozet = ''): array =>
        ['yayin' => $yayin, 'baslik' => $baslik, 'zaman' => $t - $dk * 60, 'adres' => 'https://' . $yayin . '/h/' . md5($baslik), 'ozet' => $ozet];

    $liste = [
        $g('trthaber.com', 'Merkez Bankası politika faizini yüzde 40\'a indirdi', 50, str_repeat('Uzun bir özet cümlesi. ', 30)),
        $g('hurriyet.com.tr', 'Merkez Bankası faiz kararını açıkladı: politika faizi yüzde 40', 40, '<b>Karar</b> açıklandı &amp; piyasalar izliyor'),
        $g('sozcu.com.tr', 'Merkez Bankası politika faizini yüzde 40\'a indirdi: dolar nasıl tepki verdi', 30),
        $g('bloomberght.com', 'Merkez Bankası politika faizini yüzde 40\'a indirdi, beklenti aşıldı', 20),
        $g('trthaber.com', 'Meclis yeni vergi paketini kabul etti', 45),
        $g('milliyet.com.tr', 'Meclis yeni vergi paketini kabul etti: oylama tamamlandı', 35),
        $g('ntv.com.tr', 'Yeni vergi paketi Meclis\'te kabul edildi', 25),
        $g('haberturk.com', 'Okullarda ara tatil başladı', 60),
        $g('cnnturk.com', 'Okullarda birinci ara tatil başladı', 55),
        $g('ornek.test', 'Merkez Bankası politika faizini yüzde 40\'a indirdi', 10),
        ['yayin' => 'ntv.com.tr', 'baslik' => 'Bağlantısı olmayan başlık alınmaz', 'zaman' => $t, 'adres' => ''],
    ];

    bekle(kny_kaydet($liste) === 9, 'listede olmayan yayın ve bağlantısız başlık alınmadı (9 başlık)');
    bekle(kny_kaydet($liste) === 0, 'aynı başlıklar yeniden gelince ikinci kez yazılmadı');

    $ozet = (string) db()->query("SELECT ozet FROM gundem_basliklari WHERE yayin = 'trthaber.com' ORDER BY id LIMIT 1")->fetchColumn();
    bekle(mb_strlen($ozet, 'UTF-8') <= KNY_OZET_UZUNLUK + 1, 'yayının özeti en fazla ' . KNY_OZET_UZUNLUK . ' karakter gösteriliyor (' . mb_strlen($ozet, 'UTF-8') . ')');
    bekle((string) db()->query("SELECT ozet FROM gundem_basliklari WHERE yayin = 'hurriyet.com.tr' LIMIT 1")->fetchColumn() === 'Karar açıklandı & piyasalar izliyor', 'özet düz metne çevrildi (HTML yok)');

    bekle(kny_yenile() === 2, 'en az ' . KNY_EN_AZ_YAYIN . ' yayının verdiği iki olay listede; iki yayının verdiği olay değil');

    $olaylar = kny_olaylar();
    bekle(count($olaylar[0]['yayinlar']) === 4 && str_contains($olaylar[0]['baslik'], 'Merkez Bankası'), 'en çok yayının verdiği olay ilk sırada (4 yayın)', $olaylar[0]['baslik']);
    bekle(in_array($olaylar[0]['baslik'], array_column($liste, 'baslik'), true), 'olayın başlığı yayınlardan birinin kendi başlığı (uydurulmadı)');
    bekle($olaylar[0]['yayinlar'] === ['trthaber.com', 'hurriyet.com.tr', 'sozcu.com.tr', 'bloomberght.com'], 'yayınlar sabit sırada (görüşe göre dizilmiyor)', implode(',', $olaylar[0]['yayinlar']));

    $o = kny_olay($olaylar[0]['kimlik']);
    bekle($o !== null && count($o['yayinlar']) === 4, 'olay sayfası: dört yayının başlığı yan yana');
    bekle(str_starts_with((string) $o['yayinlar']['hurriyet.com.tr'][0]['adres'], 'https://hurriyet.com.tr/'), 'her başlık yayının kendi sitesine bağlanıyor');
    sort($o['yazmayanlar']);
    bekle($o['yazmayanlar'] === ['cnnturk.com', 'haberturk.com', 'milliyet.com.tr', 'ntv.com.tr'],
        '"rastlanmayanlar": yalnızca beslemesi okunmuş genel yayınlar (hiç okunmayan Sabah ve ekonomi yayınları listede yok)', implode(',', $o['yazmayanlar']));
    bekle(kny_olay(999999) === null, 'olmayan olay boş döner (sayfa listeye yönlendirir)');

    // Zincir yok: ortak kelimeler uzerinden ilgisiz olaylar tek olayda toplanmaz.
    $k = kny_kumele([
        ['id' => 1, 'yayin' => 'a', 'baslik' => 'Cumhurbaşkanı Erdoğan Katar Emiri ile görüştü', 'zaman' => 1, 'manset' => 0],
        ['id' => 2, 'yayin' => 'b', 'baslik' => 'Cumhurbaşkanı Erdoğan Katar Emiri Al Sani ile Ankara\'da görüştü', 'zaman' => 2, 'manset' => 0],
        ['id' => 3, 'yayin' => 'c', 'baslik' => 'Cumhurbaşkanı Erdoğan iklim zirvesinde liderlerle görüştü', 'zaman' => 3, 'manset' => 0],
        ['id' => 4, 'yayin' => 'd', 'baslik' => 'Brent petrol 90 doların altına geriledi', 'zaman' => 4, 'manset' => 0],
        ['id' => 5, 'yayin' => 'e', 'baslik' => 'Bitcoin 70 bin doların altına geriledi', 'zaman' => 5, 'manset' => 0],
    ]);
    bekle(count($k) === 4 && $k[0]['uyeler'] === [1, 2], 'ayrı olaylar ayrı kaldı (Katar görüşmesi / iklim zirvesi / petrol / Bitcoin)', count($k) . ' olay');

    $z = kny_kumele([
        ['id' => 1, 'yayin' => 'a', 'baslik' => 'Cumhurbaşkanı Erdoğan Katar Emiri ile görüştü', 'zaman' => 1, 'manset' => 0],
        ['id' => 2, 'yayin' => 'b', 'baslik' => 'Cumhurbaşkanı Erdoğan Katar Emiri ile iklim zirvesinde görüştü', 'zaman' => 2, 'manset' => 0],
        ['id' => 3, 'yayin' => 'c', 'baslik' => 'Cumhurbaşkanı Erdoğan iklim zirvesinde liderlerle görüştü', 'zaman' => 3, 'manset' => 0],
    ]);
    bekle(count($z) === 2 && $z[0]['uyeler'] === [1, 2] && $z[1]['uyeler'] === [3], 'ara başlık köprü olmuyor: üçüncü başlık ilk olaya katılmadı', json_encode(array_column($z, 'uyeler')));

    bekle(kny_kutu_html() === '', 'üçten az olay varken ana sayfa kutusu boş: eski "5 gelişme" kutusu gösterilir');
    kny_kaydet([$g('sabah.com.tr', 'Okullarda ara tatil başladı: veliler dikkat', 5)]);
    kny_yenile();
    $kutu = kny_kutu_html();
    bekle(str_contains($kutu, 'Kim ne yazdı?') && substr_count($kutu, '<li>') === 3 && str_contains($kutu, '/kim-ne-yazdi/'), 'üç olay olunca kutu çıkıyor ve olay sayfalarına bağlanıyor');
    bekle(!preg_match('/hükümet|muhalif|sağ|sol/iu', strip_tags($kutu)), 'kutuda yayınlar için eğilim etiketi yok');

    db()->exec('DELETE FROM gundem_basliklari');
    ayar_yaz(KNY_AYARI, '');
});

senaryo('X', 'V Network: gazete gazete görünüm ve gazeteye göre akış', static function (): void {
    require_once __DIR__ . '/../includes/topluluk.php';

    // Bu senaryodaki ornek gazetelerin 2-3 haberi var: "Diger" esigi (canlida 40) burada 2.
    putenv('NW_GAZETE_AYRI_EN_AZ=2');

    bekle(nw_gazete_adi('Hürriyet Gündem') === 'Hürriyet' && nw_gazete_adi('TRT Haber Son Dakika') === 'TRT Haber'
        && nw_gazete_adi('DW Türkçe (video)') === 'DW Türkçe', 'bölüm beslemeleri tek yayında toplanıyor (Hürriyet Gündem → Hürriyet)');
    bekle(nw_gazete_slug('Habertürk') === 'haberturk' && nw_gazete_slug('İHA') === 'iha', 'yayın adının adresteki hali');

    $olagan = ['oncelik' => 'normal', 'olay_turu' => 'diger', 'olay_guncel' => true, 'turkiye_dogrudan' => true, 'dogrulama' => 'tek_kaynak', 'can_kaybi' => 'yok'];
    $konular = ['Belediye yeni otobüs hattı açtı', 'Baraj doluluk oranı açıklandı', 'Üniversite kontenjanları belli oldu', 'Turizm geliri rekor kırdı',
        'Köprü bakım çalışması başladı', 'Sanayi üretimi verisi yayımlandı', 'Tapu harcı düzenlemesi Meclis\'te'];
    $kaynaklar = ['Hürriyet Gündem', 'Hürriyet Dünya', 'Hürriyet Gündem', 'NTV Türkiye', 'NTV Türkiye', 'Sözcü Gündem', 'Tek Haberli Site'];
    $liste = [];

    foreach ($konular as $i => $b) {
        $liste[] = haber('Sınavkent: ' . $b, $olagan + ['kaynak_adi' => $kaynaklar[$i], 'kategori' => ['gundem', 'dunya', 'ekonomi'][$i % 3]]);
    }

    ekle($liste);
    db()->exec("UPDATE haberler SET durum = 'yayinda', yayin_tarihi = NOW() - INTERVAL id MINUTE, guncellendi = guncellendi");

    $g = nw_gazeteler();
    bekle(array_keys($g) === ['hurriyet', 'ntv', 'diger'] && $g['hurriyet']['adet'] === 3 && $g['diger']['adet'] === 2, 'gazete listesi: en çok haberi olan önce; tek haberli kaynaklar "Diğer"de, en sonda', json_encode(array_map(static fn (array $x): int => $x['adet'], $g)));

    // Canlidaki esikle (40): az haberli Turk gazeteleri tek "Diger gazeteler" satirinda; akisi hepsinin haberleri.
    $digerli = (array) json_decode((string) shell_exec('NW_GAZETE_AYRI_EN_AZ=40 ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
        'require "' . __DIR__ . '/../includes/bootstrap.php"; require "' . __DIR__ . '/../includes/topluluk.php"; $g = nw_gazeteler();'
        . 'echo json_encode([array_keys($g), $g["diger"]["adet"] ?? 0, count(nw_akis(30, 0, null, null, "", "diger", true)["ogeler"]), nw_gazete_alani("hurriyet")]);'
    )), true);
    bekle($digerli === [['diger'], 7, 7, 'hurriyet.com.tr'], 'az haberli gazeteler "Diğer gazeteler"de toplanıyor; akışı hepsinin haberleri; logoları korunuyor', json_encode($digerli));

    $o = nw_gazete_ozetleri(10, 2);
    bekle(count($o) === 3 && $o[0]['ad'] === 'Hürriyet' && count($o[0]['haberler']) === 2, '"gazete gazete" görünüm: her yayının son haberleri');

    $akis = nw_akis(20, 0, null, null, '', 'hurriyet');
    $adlar = array_unique(array_map(static fn (array $x): string => (string) $x['tur'], $akis['ogeler']));
    bekle(count($akis['ogeler']) === 3 && $adlar === ['haber'], 'gazete seçilince yalnızca o yayından özetlenen haberler geliyor', count($akis['ogeler']) . ' öğe');
    bekle(nw_akis(20, 0, null, null, '', 'olmayan-gazete')['ogeler'] === [], 'bilinmeyen gazete boş akış verir');
    bekle(count(nw_akis(20, 0, null, null, 'ekonomi')['ogeler']) === 2, 'başlık seçilince o başlığın haberleri geliyor (ekonomi: 2)');

    // Ulke ulke (13.11.2026): ulke kaynagin site adresinden; Turkiye'de ana / yerel ayrimi.
    bekle(nw_kaynak_ulkesi('https://www.theguardian.com/world') === 'gb' && nw_kaynak_ulkesi('https://www.spiegel.de') === 'de'
        && nw_kaynak_ulkesi('https://www.bbc.com/turkce') === 'gb' && nw_kaynak_ulkesi('https://www.haberturk.com') === 'tr'
        && nw_kaynak_ulkesi('https://en.yna.co.kr') === 'kr' && nw_kaynak_ulkesi('https://www.who.int/news') === 'un' && nw_kaynak_ulkesi('') === 'tr', 'ülke site adresinden bulunuyor; bilinmeyen adres Türkiye');
    bekle(nw_gazete_adi('Le Monde (Fransa)') === 'Le Monde' && nw_gazete_adi('The Guardian Health') === 'The Guardian', 'yabancı yayınların bölüm ve ülke ekleri ada katılmıyor');

    db()->exec("DELETE FROM kaynaklar WHERE ad IN ('Test Guardian', 'Test Yerel Gazete')");
    db()->exec("INSERT INTO kaynaklar (ad, site_url, tur, kategori, aktif) VALUES ('Test Guardian', 'https://www.theguardian.com', 'rss', 'dunya', 1)");
    $gId = (int) db()->lastInsertId();
    db()->exec("INSERT INTO kaynaklar (ad, site_url, tur, kategori, sehir, aktif) VALUES ('Test Yerel Gazete', 'https://www.ornekyerel.com.tr', 'rss', 'gundem', 'kayseri', 1)");
    $yId = (int) db()->lastInsertId();
    db()->exec("UPDATE haberler SET kaynak_adi = 'Test Guardian', kaynak_id = $gId, guncellendi = guncellendi WHERE kaynak_adi = 'NTV Türkiye'");
    db()->exec("UPDATE haberler SET kaynak_adi = 'Test Yerel Gazete', kaynak_id = $yId, guncellendi = guncellendi WHERE kaynak_adi IN ('Hürriyet Dünya', 'Sözcü Gündem')");

    // Istek basina onbellek: yeni surecte oku.
    $gruplar = json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
        'require "' . __DIR__ . '/../includes/bootstrap.php"; require "' . __DIR__ . '/../includes/topluluk.php";'
        . 'echo json_encode(array_map(fn($g) => [$g["kod"], array_keys($g["ana"]), array_keys($g["yerel"])], nw_gazete_gruplari()));'
    )), true);
    bekle($gruplar === [['tr', ['hurriyet', 'diger'], ['test-yerel-gazete']], ['gb', ['test-guardian'], []]],
        'gruplar: Türkiye (ana: Hürriyet, yerel: yerel gazete), sonra Birleşik Krallık', json_encode($gruplar, JSON_UNESCAPED_UNICODE));
    db()->exec("DELETE FROM kaynaklar WHERE id IN ($gId, $yId)");

    $yalniz = nw_akis(30, 0, null, null, '', '', true);
    bekle(array_unique(array_column($yalniz['ogeler'], 'tur')) === ['haber'], 'akışta yalnızca haber var (üye paylaşımı ve yorum kartı yok)');
    bekle(str_contains(nw_konu_ikonu('ekonomi'), '<svg') && str_contains(nw_bayrak_html('de'), '/assets/bayrak/de.svg') && nw_bayrak_html('xx') === '',
        'başlık ikonu ve ülke bayrağı üretiliyor; bilinmeyen ülkeye bayrak yok');
    bekle(str_contains(nw_gazete_simge_html('hurriyet', 'Hürriyet'), '>H<') , 'gazete simgesi yüklenemezse adın baş harfi görünür');

    // Spor dallari (14.11.2026): Futbol, Basketbol, Diger (ValSpor kosullariyla).
    ekle([
        haber('Sınavspor Süper Lig maçında rakibini iki golle geçti', $olagan + ['kategori' => 'spor', 'etiketler' => 'futbol, Süper Lig']),
        haber('Sınavkent basketbol takımı EuroLeague maçını kazandı', $olagan + ['kategori' => 'spor', 'etiketler' => 'basketbol, EuroLeague']),
        haber('Milli tenisçi Wimbledon tenis turnuvasında çeyrek finalde', $olagan + ['kategori' => 'spor', 'etiketler' => 'tenis, Wimbledon']),
    ]);
    db()->exec("UPDATE haberler SET durum = 'yayinda', yayin_tarihi = COALESCE(yayin_tarihi, NOW()), guncellendi = guncellendi");
    // Baslik listesi (nw_konular) istek basina onbellekte: yeni surecte oku.
    $dalBasliklari = static fn (string $dal): array => (array) json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
        'require "' . __DIR__ . '/../includes/bootstrap.php"; require "' . __DIR__ . '/../includes/topluluk.php";'
        . 'echo json_encode(array_map(fn($o) => (string) $o["veri"]["baslik"], nw_akis(30, 0, null, null, "spor", "", true, ' . var_export($dal, true) . ')["ogeler"]));'
    )), true);
    bekle(count($dalBasliklari('')) === 3, 'spor başlığı: dal seçilmeden bütün spor haberleri', implode(' | ', $dalBasliklari('')));
    bekle($dalBasliklari('futbol') === ['Sınavspor Süper Lig maçında rakibini iki golle geçti'], 'Futbol: yalnızca futbol haberi', implode(' | ', $dalBasliklari('futbol')));
    bekle($dalBasliklari('basketbol') === ['Sınavkent basketbol takımı EuroLeague maçını kazandı'], 'Basketbol: yalnızca basketbol haberi', implode(' | ', $dalBasliklari('basketbol')));
    bekle($dalBasliklari('diger') === ['Milli tenisçi Wimbledon tenis turnuvasında çeyrek finalde'], 'Diğer: diğer sporların haberi', implode(' | ', $dalBasliklari('diger')));
    bekle(nw_spor_dal_kosulu('olmayan') === '' && count($dalBasliklari('olmayan')) === 3, 'bilinmeyen dal süzmez');

    // "Son haberler" (14.11.2026): once Turkiye, onemli gundem; yabanci yayin, spor ve onemsiz haber yok.
    db()->exec("INSERT INTO kaynaklar (ad, site_url, tur, kategori, aktif) VALUES ('Test Spiegel', 'https://www.spiegel.de/', 'rss', 'gundem', 1)");
    $sId = (int) db()->lastInsertId();
    db()->exec("UPDATE haberler SET onem = 70, kaynak_id = NULL, guncellendi = guncellendi");
    db()->exec("UPDATE haberler SET onem = 30, guncellendi = guncellendi WHERE baslik LIKE '%Tapu harcı%'");
    db()->exec("UPDATE haberler SET kaynak_id = $sId, guncellendi = guncellendi WHERE baslik LIKE '%Turizm geliri%'");
    $sonBasliklar = array_map(static fn (array $o): string => (string) $o['veri']['baslik'], nw_akis(40, 0, null, null, '', '', true, '', true)['ogeler']);
    $var = static fn (string $parca): bool => array_filter($sonBasliklar, static fn (string $b): bool => str_contains($b, $parca)) !== [];
    bekle($var('otobüs hattı') && $var('Sanayi üretimi'), '"Son haberler": Türkiye gündeminin önemli haberleri geliyor', implode(' | ', $sonBasliklar));
    bekle(!$var('Turizm geliri'), '"Son haberler": yabancı gazetenin haberi yok (ülke gruplarında durur)');
    bekle($var('Tapu harcı'), '"Son haberler": son yüklenenlerin tamamı (önem puanı düşük haber de var)');

    // "Onemli haberler" (14.11.2026): o gunun gundemi; once buyuk yayinlarin ana sayfasindakiler, sonra onem puani.
    db()->exec("UPDATE haberler SET manset_sinyal = 18, guncellendi = guncellendi WHERE baslik LIKE '%Sanayi üretimi%'");
    db()->exec("UPDATE haberler SET onem = 90, guncellendi = guncellendi WHERE baslik LIKE '%otobüs hattı%'");
    $onemliBasliklar = static fn (): array => array_map(static fn (array $o): string => (string) $o['veri']['baslik'], nw_akis(40, 0, null, null, '', '', true, '', true, 'onemli')['ogeler']);
    $onemliler = $onemliBasliklar();
    bekle(str_contains($onemliler[0] ?? '', 'otobüs hattı') && str_contains($onemliler[1] ?? '', 'Sanayi üretimi'),
        '"Önemli haberler": önem puanı ve gazetelerin yer vermesi puanı yükseltiyor (90, sonra 70 + 18)', implode(' | ', $onemliler));
    // Manset eki: ayni onemdeki iki haberden mansette olan once.
    db()->exec("UPDATE haberler SET manset_yayinlar = 'Hürriyet, Sabah (manşette)', guncellendi = guncellendi WHERE baslik LIKE '%Tapu harcı%'");
    db()->exec("UPDATE haberler SET onem = 70, guncellendi = guncellendi WHERE baslik LIKE '%Tapu harcı%'");
    bekle(str_contains($onemliBasliklar()[2] ?? '', 'Tapu harcı'), '"Önemli haberler": gazetelerin manşetindeki haber aynı önemdeki diğerlerinin önünde', implode(' | ', $onemliBasliklar()));
    db()->exec("UPDATE haberler SET onem = 30, manset_yayinlar = '', guncellendi = guncellendi WHERE baslik LIKE '%Tapu harcı%'");
    // Tazelik (14.11.2026): 14 saatlik haber, onemi yuksek olsa da bugunun taze haberlerinin arkasinda.
    db()->exec("UPDATE haberler SET yayin_tarihi = NOW() - INTERVAL 14 HOUR, guncellendi = guncellendi WHERE baslik LIKE '%otobüs hattı%'");
    $eskili = $onemliBasliklar();
    bekle(str_contains($eskili[0] ?? '', 'Sanayi üretimi') && !str_contains(implode('|', array_slice($eskili, 0, 2)), 'otobüs hattı'),
        '"Önemli haberler": 14 saatlik haber en üstte kalmıyor, taze önemli haberler önde', implode(' | ', $eskili));
    db()->exec("UPDATE haberler SET yayin_tarihi = NOW() - INTERVAL id MINUTE, guncellendi = guncellendi WHERE baslik LIKE '%otobüs hattı%'");
    bekle(array_filter($onemliler, static fn (string $b): bool => str_contains($b, 'Turizm geliri')) === [], '"Önemli haberler": yabancı gazete haberi yok');
    $onemli1 = nw_akis(2, 0, null, null, '', '', true, '', true, 'onemli');
    $onemli2 = nw_akis(2, 0, null, null, '', '', true, '', true, 'onemli', 2);
    bekle($onemli1['atla'] === 2 && $onemli2['ogeler'] !== [] && array_intersect(array_column(array_column($onemli1['ogeler'], 'veri'), 'id'), array_column(array_column($onemli2['ogeler'], 'veri'), 'id')) === [],
        '"Önemli haberler" sürekli akış: sayfalanıyor, sonraki sayfa aynı haberi yinelemiyor');
    // Genel "Son haberler" sirasi (14.11.2026): Valentra Haber ana sayfasinin kurali; ?atla ile sayfalanir.
    $gundemTum    = array_map(static fn (array $o): string => (string) $o['veri']['baslik'], nw_akis(40, 0, null, null, '', '', true, '', true, 'gundem')['ogeler']);
    $yer          = static fn (string $parca): int => (int) array_key_first(array_filter($gundemTum, static fn (string $b): bool => str_contains($b, $parca)) ?: [999 => '']);
    $gundemSirasi = nw_akis(2, 0, null, null, '', '', true, '', true, 'gundem');
    $gundemDevam  = nw_akis(2, 0, null, null, '', '', true, '', true, 'gundem', 2);
    bekle($yer('Sanayi üretimi') < $yer('Tapu harcı') && $yer('otobüs hattı') < $yer('Tapu harcı') && $yer('Tapu harcı') < 999,
        '"Son haberler" sırası: gazetelerin yer verdiği ve önemli haberler üstte, önemsiz haber aşağıda (ama listede)', implode(' | ', $gundemTum));
    bekle($gundemSirasi['atla'] === 2 && $gundemDevam['ogeler'] !== []
        && array_intersect(array_column(array_column($gundemSirasi['ogeler'], 'veri'), 'id'), array_column(array_column($gundemDevam['ogeler'], 'veri'), 'id')) === [],
        '"Son haberler" sayfalanıyor: sonraki sayfa aynı haberi yinelemiyor');
    $gazeteOnemli = nw_akis(40, 0, null, null, '', 'hurriyet', true, '', false, 'onemli');
    bekle($gazeteOnemli['ogeler'] !== [] && str_contains((string) $gazeteOnemli['ogeler'][0]['veri']['baslik'], 'otobüs hattı'),
        'gazete seçiliyken "Önemli haberler": o gazetenin en önemli haberi en üstte', (string) ($gazeteOnemli['ogeler'][0]['veri']['baslik'] ?? '-'));
    db()->exec("UPDATE haberler SET manset_sinyal = 0, guncellendi = guncellendi");
    bekle(!$var('Süper Lig') && !$var('EuroLeague') && !$var('Köprü bakım'), '"Son haberler": spor ve dünya başlığındaki haber yok');
    bekle(count(nw_akis(40, 0, null, null, '', '', true)['ogeler']) > count($sonBasliklar), 'başlık ve gazete akışları değişmedi (süzgeç yalnızca "Son haberler"de)');
    db()->exec("DELETE FROM kaynaklar WHERE id = $sId");
    bekle((int) db()->query("SELECT ('https://www.bbc.com/turkce' REGEXP " . db()->quote(nw_yabanci_kaynak_ifadesi()) . ") + ('https://feeds.bbci.co.uk/news' REGEXP " . db()->quote(nw_yabanci_kaynak_ifadesi())
        . ") + 10 * ('https://www.hurriyet.com.tr/rss' REGEXP " . db()->quote(nw_yabanci_kaynak_ifadesi()) . ")")->fetchColumn() === 2,
        'yabancı yayın adresi SQL\'de de tanınıyor; Türk yayını yabancı sayılmıyor');

    // Akis kartinda 5-6 satirlik ozet (14.11.2026).
    $uzunOzet = nw_haber_ozeti(['ozet' => 'Merkez Bankası faiz kararını açıkladı.', 'icerik_basi' => "## Karar\nMerkez Bankası faiz kararını açıkladı. Para Politikası Kurulu politika faizini sabit tuttu. "
        . str_repeat('Kurul, enflasyon görünümündeki gelişmeleri yakından izleyeceğini bildirdi. Piyasalar kararı beklentilere paralel buldu. ', 6)]);
    bekle(mb_strlen($uzunOzet) >= 400 && mb_strlen($uzunOzet) <= 660 && str_starts_with($uzunOzet, 'Merkez Bankası faiz kararını açıkladı. Para Politikası')
        && str_ends_with($uzunOzet, '.') && !str_contains($uzunOzet, '##'), 'kart özeti 5-6 satır: kısa özet haber metninden cümle sınırında tamamlanıyor, özet yinelenmiyor', mb_strlen($uzunOzet) . ' karakter');
    bekle(nw_haber_ozeti(['ozet' => 'Kısa özet.', 'icerik_basi' => '']) === 'Kısa özet.', 'metin yoksa özet olduğu gibi (uydurma tamamlama yok)');

    // Gazete simgesi yayinin kendi sitesinden (14.11.2026): video beslemesinin adresi (YouTube) simge olmaz.
    bekle(nw_simge_alani('https://www.youtube.com/channel/abc') === '' && nw_simge_alani('https://www.sozcu.com.tr/rss') === 'sozcu.com.tr'
        && NW_GAZETE_ALANLARI['ntv'] === 'ntv.com.tr' && nw_gazete_simge_dosyasi('ntv', 'ntv.com.tr') !== nw_gazete_simge_dosyasi('ntv', 'youtube.com'),
        'gazete simgesi yayının kendi sitesinden; YouTube adresi simge kaynağı değil, adres değişince eski simge geçersiz');

    // Gazetelerin mansetleri (14.11.2026): her yayinin manset listesindeki basliklar once.
    require_once __DIR__ . '/../includes/kim_ne_yazdi.php';
    db()->exec('DELETE FROM gundem_basliklari');
    kny_kaydet([
        ['yayin' => 'hurriyet.com.tr', 'baslik' => 'Hürriyet gündem haberi', 'adres' => 'https://www.hurriyet.com.tr/g1', 'manset' => false, 'zaman' => time() - 60],
        ['yayin' => 'hurriyet.com.tr', 'baslik' => 'Hürriyet manşet haberi', 'adres' => 'https://www.hurriyet.com.tr/m1', 'manset' => true, 'zaman' => time() - 3600],
        ['yayin' => 'ntv.com.tr', 'baslik' => 'NTV gündem haberi', 'adres' => 'https://www.ntv.com.tr/g1', 'manset' => false, 'zaman' => time() - 120],
    ]);
    // Ayni haber sonradan manset beslemesinden de gelirse manset isareti yukselir.
    kny_kaydet([['yayin' => 'ntv.com.tr', 'baslik' => 'NTV gündem haberi', 'adres' => 'https://www.ntv.com.tr/g1', 'manset' => true, 'zaman' => time() - 120]]);
    bekle((int) db()->query("SELECT manset FROM gundem_basliklari WHERE adres = 'https://www.ntv.com.tr/g1'")->fetchColumn() === 1
        && (int) db()->query('SELECT COUNT(*) FROM gundem_basliklari')->fetchColumn() === 3, 'aynı haber manşet beslemesinden de gelince manşet işareti yükseliyor, satır çoğalmıyor');
    db()->exec("UPDATE gundem_basliklari SET manset = 0 WHERE adres = 'https://www.ntv.com.tr/g1'");
    $mansetler = nw_gazete_mansetleri();
    bekle(count($mansetler) === 2 && $mansetler[0]['ad'] === 'Hürriyet' && $mansetler[0]['manset'] && $mansetler[0]['basliklar'][0]['baslik'] === 'Hürriyet manşet haberi'
        && !$mansetler[1]['manset'], 'gazetelerin manşetleri: manşet listesi olan yayın ve manşet başlığı önce; olmayan yayında gündem başlıkları');
    $mansetHtml = nw_gazete_mansetleri_html();
    bekle(str_contains($mansetHtml, 'https://www.hurriyet.com.tr/m1') && str_contains($mansetHtml, 'rel="noopener nofollow"') && str_contains($mansetHtml, 'Öne çıkanlar'),
        'manşet başlığı gazetenin kendi sayfasına bağlanıyor; manşet beslemesi olmayan yayın "öne çıkanlar" diye ayrılıyor');
    bekle(nw_gazete_adi('Sözcü Gazetesi') === 'Sözcü' && nw_gazete_adi('Sözcü Gündem') === 'Sözcü' && nw_gazete_adi('Dünya Gazetesi') === 'Dünya Gazetesi',
        'Sözcü\'nün iki adı tek gazetede; "Gazetesi" eki başka yayınlarda korunuyor');
    bekle(nw_gazete_adi('Hürriyet Ana Sayfa') === 'Hürriyet' && nw_gazete_adi('Habertürk Manşet') === 'Habertürk', 'ana sayfa / manşet beslemeleri yayının adında toplanıyor');
    db()->exec('DELETE FROM gundem_basliklari');

    // Sag kenar kutulari (14.11.2026): kaynaklari beklemez, veri yoksa da cizilir.
    $eko = nw_ekonomi_kutusu_html();
    bekle(str_contains($eko, 'Döviz') && str_contains($eko, 'Kripto paralar') && str_contains($eko, 'bolum=doviz') && !str_contains($eko, '—'),
        'ekonomi kutusu: döviz, borsa, altın, kripto bağlantıları; değer yokken çizgi yerine açıklama');
    $spor = nw_spor_kutusu_html();
    bekle(strpos($spor, 'Canlı skorlar') < strpos($spor, 'Puan durumları') && str_contains($spor, 'bolum=canli-skor') && str_contains($spor, 'bolum=fikstur'),
        'spor kutusu: en başta canlı skorlar, sonra puan durumları ve fikstür');
    bekle(nw_spor_skor_satirlari_html([['canli' => true, 'durum' => 'LIVE', 'durum_yazi' => "34'", 'saat' => '20:00', 'ev' => 'A <b>', 'dep' => 'B', 'ev_gol' => 1, 'dep_gol' => 0, 'adres' => '/x']])
        === '<a class="nw-skor canli" href="/x"><span class="nw-skor-durum">34&#039;</span><span class="nw-skor-takim">A &lt;b&gt;</span><b class="nw-skor-sonuc">1 - 0</b><span class="nw-skor-takim">B</span></a>',
        'canlı skor satırı: dakika, takımlar, skor; takım adı güvenli yazılıyor');
    require_once __DIR__ . '/../includes/spor_panel.php';
    bekle(array_key_first(SPOR_BOLUMLERI) === 'canli-skor' && str_contains(spor_bolum_yolu('canli-skor'), 'bolum=canli-skor'), 'spor bölümlerinde ilk sırada Canlı Skorlar');
});

senaryo('Y', 'Google ile giriş: kimlik doğrulama, yeni üye, e-postayla eşleşen üyelik, engeller', static function (): void {
    require_once __DIR__ . '/../includes/yorumlar.php';
    require_once __DIR__ . '/../includes/uye_google.php';

    db()->exec("DELETE FROM uyeler WHERE eposta LIKE '%@ornek.test'");
    $istemci = '1234567890-abcdef.apps.googleusercontent.com';
    $eskiI = ayar_oku(GOOGLE_GIRIS_ISTEMCI_AYARI, '');
    $eskiG = ayar_oku(GOOGLE_GIRIS_GIZLI_AYARI, '');

    ayar_yaz(GOOGLE_GIRIS_ISTEMCI_AYARI, '');
    bekle(!google_giris_acik() && google_giris_dugmesi('/network') === '', 'kurulmadan düğme sitede görünmüyor');
    ayar_yaz(GOOGLE_GIRIS_ISTEMCI_AYARI, $istemci);
    ayar_yaz(GOOGLE_GIRIS_GIZLI_AYARI, 'test-gizli');
    bekle(google_giris_acik() && str_contains(google_giris_dugmesi('/network'), '/uye-google.php?donus=%2Fnetwork'), 'kurulunca düğme çıkıyor ve dönüş yolunu taşıyor');
    bekle(google_giris_yolu('https://kotu.test/x') === '/uye-google.php', 'site dışı dönüş adresi alınmıyor (açık yönlendirme yok)');

    // Google'in jeton ucunun yerine gecen sahte yanit.
    $jeton = static fn (array $yuk): string => 'x.' . rtrim(strtr(base64_encode((string) json_encode($yuk)), '+/', '-_'), '=') . '.y';
    $yanit = static fn (array $yuk, int $kod = 200): callable => static fn (array $alanlar): array => [$kod, (string) json_encode(['id_token' => $jeton($yuk)])];
    $gecerli = ['aud' => $istemci, 'iss' => 'https://accounts.google.com', 'exp' => time() + 600, 'sub' => '1001', 'email' => 'Ayse@Ornek.test', 'email_verified' => true, 'name' => 'Ayşe Yılmaz'];

    $k = google_giris_kimlik('kod', $yanit($gecerli));
    bekle($k === ['sub' => '1001', 'eposta' => 'ayse@ornek.test', 'ad' => 'Ayşe Yılmaz'], 'geçerli kimlik jetonu okunuyor');

    foreach ([
        'başka uygulamaya verilmiş jeton (aud)' => ['aud' => 'baska.apps.googleusercontent.com'],
        'Google dışı veren (iss)'               => ['iss' => 'https://kotu.test'],
        'süresi dolmuş jeton'                   => ['exp' => time() - 3600],
        'e-postası doğrulanmamış hesap'         => ['email_verified' => false],
    ] as $ne => $degisen) {
        $red = false;

        try {
            google_giris_kimlik('kod', $yanit($degisen + $gecerli));
        } catch (InvalidArgumentException $e) {
            $red = true;
        }

        bekle($red, $ne . ' reddediliyor');
    }

    $red = false;

    try {
        google_giris_kimlik('kod', $yanit($gecerli, 400));
    } catch (InvalidArgumentException $e) {
        $red = true;
    }

    bekle($red, 'Google kodu kabul etmezse (HTTP 400) giriş olmuyor');

    $u1 = google_giris_uye($k);
    $satir = db()->query('SELECT * FROM uyeler WHERE id = ' . $u1['id'])->fetch();
    bekle($u1['yeni'] && $satir['ad'] === 'AyşeYılmaz' && $satir['eposta_dogrulandi'] !== null && $satir['google_kimlik'] === '1001',
        'yeni üye açıldı: rumuz Google adından, e-posta doğrulanmış', (string) $satir['ad']);
    bekle(google_giris_uye($k) === ['id' => $u1['id'], 'yeni' => false], 'aynı Google hesabı ikinci kez aynı üyeye giriyor');

    $u2 = google_giris_uye(['sub' => '1002', 'eposta' => 'ayse2@ornek.test', 'ad' => 'Ayşe Yılmaz']);
    $ad2 = (string) db()->query('SELECT ad FROM uyeler WHERE id = ' . $u2['id'])->fetchColumn();
    bekle($ad2 !== 'AyşeYılmaz' && str_starts_with($ad2, 'AyşeYılmaz'), 'aynı adlı ikinci kişiye benzersiz rumuz verildi', $ad2);

    // E-posta ve sifreyle uye olmus kisi Google ile gelirse AYNI hesap.
    db()->prepare("INSERT INTO uyeler (ad, eposta, sifre) VALUES ('eskiuye', 'eski@ornek.test', :s)")->execute(['s' => password_hash('gizli-sifre', PASSWORD_DEFAULT)]);
    $eskiId = (int) db()->lastInsertId();
    $u3 = google_giris_uye(['sub' => '1003', 'eposta' => 'eski@ornek.test', 'ad' => 'Eski Üye']);
    bekle($u3 === ['id' => $eskiId, 'yeni' => false] && (string) db()->query('SELECT google_kimlik FROM uyeler WHERE id = ' . $eskiId)->fetchColumn() === '1003',
        'aynı e-postalı mevcut üyelik Google hesabına bağlandı (ikinci hesap açılmadı)');

    bekle(!password_verify('gizli-sifre', (string) db()->query('SELECT sifre FROM uyeler WHERE id = ' . $eskiId)->fetchColumn()),
        'e-postası doğrulanmamış üyelik bağlanırken eski şifre geçersiz kılındı (adresi önceden başkası kaydetmiş olabilir)');

    db()->prepare("INSERT INTO uyeler (ad, eposta, sifre, eposta_dogrulandi) VALUES ('dogrulanmisuye', 'dogru@ornek.test', :s, NOW())")->execute(['s' => password_hash('benim-sifrem', PASSWORD_DEFAULT)]);
    $dogruId = (int) db()->lastInsertId();
    google_giris_uye(['sub' => '1004', 'eposta' => 'dogru@ornek.test', 'ad' => 'Doğru']);
    bekle(password_verify('benim-sifrem', (string) db()->query('SELECT sifre FROM uyeler WHERE id = ' . $dogruId)->fetchColumn()),
        'e-postası doğrulanmış üyenin şifresi korunuyor (hem şifreyle hem Google ile girebilir)');

    $red = false;

    try {
        google_giris_uye(['sub' => '9999', 'eposta' => 'eski@ornek.test', 'ad' => 'Başkası']);
    } catch (InvalidArgumentException $e) {
        $red = true;
    }

    bekle($red, 'başka bir Google hesabına bağlı üyelik devralınamıyor');

    db()->exec("UPDATE uyeler SET durum = 'engelli' WHERE id = " . $eskiId);
    $red = false;

    try {
        google_giris_uye(['sub' => '1003', 'eposta' => 'eski@ornek.test', 'ad' => 'Eski Üye']);
    } catch (InvalidArgumentException $e) {
        $red = true;
    }

    bekle($red, 'engellenmiş üye Google ile de giremiyor');

    db()->exec("DELETE FROM uyeler WHERE eposta LIKE '%@ornek.test'");
    ayar_yaz(GOOGLE_GIRIS_ISTEMCI_AYARI, $eskiI);
    ayar_yaz(GOOGLE_GIRIS_GIZLI_AYARI, $eskiG);
});

senaryo('Z', 'Ziyaret sayımı: her IP bir kişi; bot şüphelileri ayrı listede', static function (): void {
    require_once __DIR__ . '/../includes/ziyaret.php';
    require_once __DIR__ . '/../includes/ziyaret_rapor.php';

    db()->exec("DELETE FROM ziyaretler WHERE yol LIKE '/sinama%'");
    $e = db()->prepare('INSERT INTO ziyaretler (zaman, ziyaretci, ip, yol, insan, tarayici_id, cihaz) VALUES (NOW() - INTERVAL ? SECOND, ?, ?, ?, ?, ?, ?)');
    $e->execute([60, 'aaaaaaaaaaaaaaaa', '85.100.1.1', '/sinama1', 1, '1111111111111111', 'mobil']);
    $e->execute([50, 'bbbbbbbbbbbbbbbb', '85.100.1.1', '/sinama2', 1, '2222222222222222', 'masaustu']);
    $e->execute([40, 'cccccccccccccccc', '88.200.2.2', '/sinama3', 1, '3333333333333333', 'mobil']);

    for ($i = 0; $i < 4; $i++) {
        $e->execute([30, 'dddddddddddddddd', '45.9.9.9', '/sinama-d' . $i, 0, '', 'masaustu']);
    }
    $e->execute([20, 'eeeeeeeeeeeeeeee', '47.74.1.1', '/sinama-b', 1, '5555555555555555', 'masaustu']);

    bekle((int) db()->query("SELECT COUNT(DISTINCT " . zr_kimlik('z') . ") FROM ziyaretler z WHERE z.yol IN ('/sinama1', '/sinama2', '/sinama3')")->fetchColumn() === 2,
        'aynı IP\'den iki ayrı tarayıcı tek kişi; iki IP iki kişi');

    $bot = array_column(zr_bot_suphelileri(zr_aralik(['aralik' => 'bugun'])), null, 'ip');
    bekle(isset($bot['45.9.9.9']) && str_contains(implode(' ', $bot['45.9.9.9']['nedenler']), 'doğrulanmadı'), 'hiçbir sayfası doğrulanmayan adres şüpheli listesinde');
    bekle(isset($bot['47.74.1.1']) && str_contains(implode(' ', $bot['47.74.1.1']['nedenler']), 'bulut'), 'bulut / veri merkezi adresi şüpheli listesinde');
    bekle(!isset($bot['85.100.1.1']) && !isset($bot['88.200.2.2']), 'olağan okuyucu şüpheli listesinde değil');
    bekle(count(zr_gezinti('85.100.1.1')) === 2 && zr_gezinti("1' OR '1") === [], 'gezinti IP ile açılıyor; geçersiz kimlik sorguya girmiyor');

    db()->exec("DELETE FROM ziyaretler WHERE yol LIKE '/sinama%'");
});

temizle();

echo "\n" . str_repeat('─', 60) . "\n";
echo "Geçen: $gecen   Kalan: $kalan\n";

exit($kalan === 0 ? 0 : 1);
