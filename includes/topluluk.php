<?php
declare(strict_types=1);

/**
 * V Network yapay zeka ve kurum hesaplari (31.10.2026).
 *
 * Site sahibinin istegi: "V Network'te 100 uye yapalim, biraz kalabalik
 * gorunsun". Gercek kisi gibi gorunen sahte hesap acilmadi: ziyaretci
 * onlari takip eder, tanismaya calisir. Bunun yerine:
 *
 *   - yz:    100 yapay zeka hesabi (ajan/veri/topluluk_hesaplari.json).
 *            Sitede hicbir yerde gorunmuyor (profil, Kisiler, bildirim
 *            yok); yalnizca begeni birakiyor (topluluk_begeni_dagit).
 *            Topluluk Kurallari'nda begeni sayilarina dahil oldugu yaziyor.
 *   - kurum: ValHaber, ValSpor gibi Valentra hesaplari ("Resmi" rozeti).
 *
 * Yapay zekanin yazdigi paylasim ve yorumlar kapatildi (site sahibinin
 * karari, 01.11.2026: "yapay zeka yorumlarini kapatalim, sadece begeni
 * artirsin yeter"). Ajan (ajan/topluluk.php) yalnizca hesaplari esitliyor
 * ve begeni dagitimini durtuyor; model kullanilmiyor.
 *
 * Ikisi de giris yapamaz (sifreleri kimsenin bilmedigi rastgele bir
 * degerin ozeti), ozel mesaj alip gonderemez, gercek uyeleri takip etmez.
 */

require_once __DIR__ . '/network.php';

const TOPLULUK_TURLERI = ['yz', 'kurum'];

/** Hesabin sabit e-posta kimligi (gorunmez; esitlemede anahtar). */
function topluluk_eposta(string $rumuz): string
{
    return 'topluluk-' . substr(sha1(mb_strtolower($rumuz, 'UTF-8')), 0, 16) . '@uye.valentra.invalid';
}

/**
 * Hesap listesini siteyle esitler: eksikleri acar, tanitim yazisini gunceller.
 *
 * @param list<array{rumuz:string,tur:string,hakkinda?:string}> $hesaplar
 * @return array{eklenen:int,guncellenen:int,hatalar:list<string>}
 */
function topluluk_hesaplari_esitle(array $hesaplar): array
{
    $sonuc = ['eklenen' => 0, 'guncellenen' => 0, 'hatalar' => []];
    $bul   = db()->prepare('SELECT id, tur FROM uyeler WHERE eposta = :e');
    // Kimsenin bilmedigi bir sifrenin ozeti: bu hesaplarla giris yapilamaz.
    // Istek basina bir kez (yuzlerce hesapta her biri icin ayri ozet
    // paylasimli sunucuda zaman asimina yol aciyordu).
    $sifre = null;

    foreach (array_slice($hesaplar, 0, 1000) as $h) {
        $rumuz    = trim((string) ($h['rumuz'] ?? ''));
        $tur      = (string) ($h['tur'] ?? '');
        $hakkinda = mb_substr(trim((string) ($h['hakkinda'] ?? '')), 0, HAKKINDA_EN_FAZLA, 'UTF-8');

        if (!in_array($tur, TOPLULUK_TURLERI, true)) {
            $sonuc['hatalar'][] = $rumuz . ': geçersiz tür';
            continue;
        }

        $eposta = topluluk_eposta($rumuz);
        $bul->execute(['e' => $eposta]);
        $var = $bul->fetch();

        if (is_array($var)) {
            db()->prepare('UPDATE uyeler SET hakkinda = :h, tur = :t WHERE id = :id')
                ->execute(['h' => $hakkinda, 't' => $tur, 'id' => (int) $var['id']]);
            $sonuc['guncellenen']++;
            continue;
        }

        try {
            // Kurum adlari (ValHaber...) uyelere kapali; burada serbest.
            $rumuz = uye_rumuz_sina($rumuz, 0, $tur === 'kurum');
        } catch (InvalidArgumentException $e) {
            $sonuc['hatalar'][] = $rumuz . ': ' . $e->getMessage();
            continue;
        }

        db()->prepare(
            "INSERT INTO uyeler (ad, hakkinda, eposta, sifre, tur, kayit_ip) VALUES (:ad, :h, :e, :s, :t, 'topluluk')"
        )->execute([
            'ad' => $rumuz,
            'h'  => $hakkinda,
            'e'  => $eposta,
            's'  => $sifre ??= password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            't'  => $tur,
        ]);
        $sonuc['eklenen']++;
    }

    return $sonuc;
}

/**
 * Ajanin durum bilgisi: hesaplar ve YZ hesaplarinin bugunku begeni sayisi.
 *
 * @return array<string,mixed>
 */
function topluluk_malzeme(): array
{
    $hesaplar = db()->query(
        "SELECT u.id, u.ad AS rumuz, u.tur FROM uyeler u
          WHERE u.tur IN ('yz','kurum') AND u.durum = 'aktif' ORDER BY u.id"
    )->fetchAll();

    $begeni = (int) db()->query(
        "SELECT COUNT(*) FROM begeniler b JOIN uyeler u ON u.id = b.uye_id
          WHERE u.tur = 'yz' AND b.olusturuldu >= CURDATE()"
    )->fetchColumn();

    return ['hesaplar' => $hesaplar, 'begeni_bugun' => $begeni];
}

/*
 * YZ TEPKILERI (site sahibi, 03.11.2026: "yapay zeka da habere gore tepki
 * versin"). YZ hesaplari onceden her icerige yalnizca "begen" birakiyordu.
 * Artik haberin basligina, ozetine ve konusuna gore bir tepki dagilimi
 * seciliyor; her hesap bu dagilimdan, hesap ve haberle sabit bir tohumla
 * tepki cekiyor (ayni hesap ayni habere hep ayni tepkiyi verir). Model
 * kullanilmiyor: anahtar sozcuklar, sirayla ilk eslesen profil.
 *
 * Profil => [tepki => agirlik]; toplam 100.
 */
const TOPLULUK_TEPKI_PROFILLERI = [
    'yas'    => ['uzgun' => 62, 'sevgi' => 12, 'kizgin' => 10, 'saskin' => 8, 'begen' => 8],
    'ofke'   => ['kizgin' => 48, 'uzgun' => 27, 'saskin' => 15, 'begen' => 10],
    'basari' => ['alkis' => 48, 'begen' => 27, 'sevgi' => 17, 'saskin' => 8],
    'sevgi'  => ['sevgi' => 50, 'begen' => 25, 'alkis' => 15, 'saskin' => 10],
    'saskin' => ['saskin' => 50, 'begen' => 30, 'alkis' => 12, 'uzgun' => 8],
    'spor'   => ['begen' => 50, 'alkis' => 32, 'sevgi' => 10, 'saskin' => 8],
    'genel'  => ['begen' => 72, 'saskin' => 10, 'alkis' => 10, 'sevgi' => 4, 'uzgun' => 4],
    'gonderi'=> ['begen' => 62, 'sevgi' => 22, 'alkis' => 10, 'saskin' => 6],
];

/** Profillerin anahtar sozcukleri (kucuk harf); sira onemli: ilk eslesen kazanir. */
const TOPLULUK_TEPKI_SOZCUKLERI = [
    // Kurtarma ve kavusma once: "enkazdan bebek kurtarildi" yas haberi degil.
    'sevgi'  => ['kurtarıldı', 'kurtardı', 'kavuştu', 'sağ çıkarıldı', 'hayata tutun'],
    'yas'    => ['öldü', 'ölü', 'ölüm', 'hayatını kaybet', 'can kaybı', 'şehit', 'cenaze', 'vefat', 'yaralı',
                 // "kaza" degil: "kazandi" da eslesirdi.
                 'kazası', 'kazada', 'kaza ', 'deprem', 'yangın', 'sel ', 'selde', 'facia', 'enkaz', 'intihar', 'boğul', 'çöktü', 'kayboldu'],
    'ofke'   => ['taciz', 'istismar', 'cinayet', 'katil', 'rüşvet', 'yolsuzluk', 'dolandır', 'şiddet', 'vahşet',
                 'terör', 'saldırı', 'darp', 'tecavüz', 'zam geldi', 'zamlandı', 'skandal', 'işkence'],
    'basari' => ['şampiyon', 'kazandı', 'zafer', 'galibiyet', 'madalya', 'kupa', 'ödül', 'başarı', 'birinci oldu',
                 'final', 'yendi', 'tarihi gece', 'rekor kırdı'],
    'sevgi2' => ['bebek', 'doğdu', 'yardım', 'bağış', 'gönüllü', 'düğün', 'bayram', 'köpek', 'kedi',
                 'sokak hayvan', 'şefkat'],
    'saskin' => ['ilk kez', 'dünyada ilk', 'şaşırt', 'beklenmedik', 'keşfedildi', 'inanılmaz', 'rekor', 'görülmemiş'],
];

/** Haberin YZ tepki profili (bkz. TOPLULUK_TEPKI_PROFILLERI). */
function topluluk_tepki_profili(string $metin, string $kategori): string
{
    $metin = mb_strtolower(str_replace(['I', 'İ'], ['ı', 'i'], $metin), 'UTF-8');

    foreach (TOPLULUK_TEPKI_SOZCUKLERI as $profil => $sozcukler) {
        foreach ($sozcukler as $sozcuk) {
            if (str_contains($metin, $sozcuk)) {
                return rtrim($profil, '2');
            }
        }
    }

    return $kategori === 'spor' ? 'spor' : 'genel';
}

/** Profilden, hesap ve icerikle sabit tohumla bir tepki. */
function topluluk_tepki_sec(string $profil, string $tur, int $hedefId, int $uyeId): string
{
    $agirliklar = TOPLULUK_TEPKI_PROFILLERI[$profil] ?? TOPLULUK_TEPKI_PROFILLERI['genel'];
    $zar = crc32($tur . $hedefId . ':' . $uyeId) % array_sum($agirliklar);

    foreach ($agirliklar as $tepki => $agirlik) {
        if ($zar < $agirlik) {
            return $tepki;
        }

        $zar -= $agirlik;
    }

    return 'begen';
}

/**
 * Icerigin tepki profili veritabanindan (haber: baslik, ozet, konu; uye
 * paylasimi: 'gonderi').
 */
function topluluk_icerik_profili(string $tur, int $hedefId): string
{
    if ($tur !== 'haber') {
        return 'gonderi';
    }

    $ifade = db()->prepare(
        'SELECT h.baslik, h.ozet, k.slug FROM haberler h LEFT JOIN kategoriler k ON k.id = h.kategori_id WHERE h.id = :id'
    );
    $ifade->execute(['id' => $hedefId]);
    $h = $ifade->fetch();

    return is_array($h) ? topluluk_tepki_profili($h['baslik'] . ' ' . $h['ozet'], (string) ($h['slug'] ?? '')) : 'genel';
}

/**
 * Tek seferlik (03.11.2026): son iki gunun haberlerindeki YZ "begen"lerini
 * habere gore tepkiye cevirir; yeni kurala gecis aninda sayfadaki eski
 * haberler de dogru gorunsun. Ayarlar tablosundaki isaretle bir kez.
 */
function topluluk_tepkileri_donustur(): void
{
    if (ayar_oku('yz_tepki_donusumu', '') === '1') {
        return;
    }

    ayar_yaz('yz_tepki_donusumu', '1');

    $satirlar = db()->query(
        "SELECT b.hedef_tur, b.hedef_id, b.uye_id FROM begeniler b
           JOIN uyeler u ON u.id = b.uye_id AND u.tur = 'yz'
          WHERE b.tepki = 'begen' AND b.olusturuldu >= NOW() - INTERVAL 2 DAY"
    )->fetchAll();

    $guncelle = db()->prepare('UPDATE begeniler SET tepki = :k WHERE hedef_tur = :t AND hedef_id = :h AND uye_id = :u');
    $profiller = [];

    foreach ($satirlar as $b) {
        $tur = (string) $b['hedef_tur'];
        $id  = (int) $b['hedef_id'];
        $profiller[$tur . $id] ??= topluluk_icerik_profili($tur, $id);
        $tepki = topluluk_tepki_sec($profiller[$tur . $id], $tur, $id, (int) $b['uye_id']);

        if ($tepki !== 'begen') {
            $guncelle->execute(['k' => $tepki, 't' => $tur, 'h' => $id, 'u' => (int) $b['uye_id']]);
        }
    }
}

/**
 * YZ hesaplarinin begenileri (site sahibinin karari, 31.10.2026: "yapay
 * zeka hesaplari gorunmesin, sadece begeni atsinlar").
 *
 * YZ hesaplari sitede hicbir yerde gorunmuyor (profil, Kisiler, bildirim
 * yok); yalnizca begeni sayilarina katiliyorlar. Topluluk Kurallari'nda
 * beğeni sayilarina YZ begenilerinin dahil oldugu yaziyor.
 *
 * Her icerigin bir hedefi var (id'den turetilen sabit sayi) ve begeniler
 * zamana yayiliyor: uye paylasimi ilk uc saatte 8-24, haber on iki saatte
 * 16-72 (onemli haber daha cok). Hedefler hesap sayisiyla buyudu: 100
 * hesapta 3-8 / 5-24, 150'de 4-12 / 8-36, 450'de (01.11.2026, site
 * sahibi: "begenileri artir") bugunku degerler; sayilar arttikca suresi
 * de uzatildi ki ani sicrama olmasin. Bildirim gitmiyor. Model kullanilmiyor.
 *
 * $hemen: yoneticinin "Begeni dalgasi" dugmesi (01.11.2026): zamana
 * yayma beklenmeden her icerik hedefine hemen ulasir.
 *
 * @return array{gonderi:int,haber:int}
 */
function topluluk_begeni_dagit(bool $hemen = false): array
{
    $sonuc = ['gonderi' => 0, 'haber' => 0];
    $yzler = array_map('intval', db()->query(
        "SELECT id FROM uyeler WHERE tur = 'yz' AND durum = 'aktif'"
    )->fetchAll(PDO::FETCH_COLUMN));

    if ($yzler === []) {
        return $sonuc;
    }

    $gonderiler = db()->query(
        "SELECT g.id, TIMESTAMPDIFF(MINUTE, g.olusturuldu, NOW()) AS dk, 0 AS onem
           FROM gonderiler g JOIN uyeler u ON u.id = g.uye_id
          WHERE g.durum = 'yayinda' AND u.durum = 'aktif' AND u.tur <> 'yz'
            AND g.olusturuldu >= NOW() - INTERVAL 2 DAY
          ORDER BY g.id DESC LIMIT 80"
    )->fetchAll();

    $haberler = db()->prepare(
        "SELECT h.id, TIMESTAMPDIFF(MINUTE, h.yayin_tarihi, NOW()) AS dk, h.onem,
                CONCAT(h.baslik, ' ', h.ozet) AS metin, k.slug AS konu
           FROM haberler h LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = :d AND h.yayin_tarihi <= NOW() AND h.yayin_tarihi >= NOW() - INTERVAL 1 DAY
          ORDER BY h.id DESC LIMIT 150"
    );
    $haberler->execute(['d' => HABER_YAYINDA]);

    $mevcut = db()->prepare(
        'SELECT b.uye_id FROM begeniler b JOIN uyeler u ON u.id = b.uye_id
          WHERE b.hedef_tur = :t AND b.hedef_id = :h AND u.tur = \'yz\''
    );
    $ekle  = db()->prepare('INSERT IGNORE INTO begeniler (hedef_tur, hedef_id, uye_id, tepki) VALUES (:t, :h, :u, :k)');
    $kalan = 1500; // tek calismada en fazla begeni

    topluluk_tepkileri_donustur();

    foreach (['gonderi' => $gonderiler, 'haber' => $haberler->fetchAll()] as $tur => $liste) {
        foreach ($liste as $oge) {
            $id   = (int) $oge['id'];
            $tohum = crc32($tur . $id);

            if ($tur === 'gonderi') {
                $hedef = 8 + $tohum % 17;                                        // 8-24
                $oran  = ((int) $oge['dk'] - 8) / 172;                           // 8. dk - 3. saat
            } else {
                $hedef = 16 + $tohum % 41 + intdiv(max(0, (int) $oge['onem'] - 50), 3); // 16-72
                $oran  = ((int) $oge['dk'] - 10) / 710;                          // 10. dk - 12. saat
            }

            $istenen = (int) round($hedef * ($hemen ? 1 : max(0, min(1, $oran))));

            if ($istenen <= 0) {
                continue;
            }

            $mevcut->execute(['t' => $tur, 'h' => $id]);
            $begenenler = array_map('intval', $mevcut->fetchAll(PDO::FETCH_COLUMN));
            $eksik      = min($kalan, $istenen - count($begenenler));

            if ($eksik <= 0) {
                continue;
            }

            $adaylar = array_values(array_diff($yzler, $begenenler));
            shuffle($adaylar);
            $profil = $tur === 'haber'
                ? topluluk_tepki_profili((string) $oge['metin'], (string) ($oge['konu'] ?? ''))
                : 'gonderi';

            foreach (array_slice($adaylar, 0, $eksik) as $uyeId) {
                $ekle->execute(['t' => $tur, 'h' => $id, 'u' => $uyeId, 'k' => topluluk_tepki_sec($profil, $tur, $id, $uyeId)]);
                $sonuc[$tur] += $ekle->rowCount();
                $kalan--;
            }

            if ($kalan <= 0) {
                return $sonuc;
            }
        }
    }

    return $sonuc;
}

/**
 * Yoneticinin "Beğeni gönder" dugmesi (site sahibinin istegi, 01.11.2026:
 * "begenileri artirmak icin bir buton koyalim; bastigimizda kullanicilar
 * gelsin ve begeni yapsinlar"): tek bir habere ya da paylasima, henuz
 * begenmemis YZ hesaplarindan $adet begeni. Bildirim gitmiyor.
 *
 * @return int eklenen begeni
 */
function topluluk_begeni_ekle(string $tur, int $hedefId, int $adet): int
{
    if (!in_array($tur, ['haber', 'gonderi'], true) || $hedefId <= 0 || $adet <= 0) {
        throw new InvalidArgumentException('Geçersiz işlem.');
    }

    $var = $tur === 'haber'
        ? db()->prepare('SELECT 1 FROM haberler WHERE id = :id AND durum = ' . db()->quote(HABER_YAYINDA))
        : db()->prepare("SELECT 1 FROM gonderiler WHERE id = :id AND durum = 'yayinda'");
    $var->execute(['id' => $hedefId]);

    if ($var->fetchColumn() === false) {
        throw new InvalidArgumentException('İçerik bulunamadı.');
    }

    $adaylar = db()->prepare(
        "SELECT u.id FROM uyeler u
          WHERE u.tur = 'yz' AND u.durum = 'aktif'
            AND NOT EXISTS (SELECT 1 FROM begeniler b WHERE b.hedef_tur = :t AND b.hedef_id = :h AND b.uye_id = u.id)
          ORDER BY RAND() LIMIT " . min(500, $adet)
    );
    $adaylar->execute(['t' => $tur, 'h' => $hedefId]);

    $ekle   = db()->prepare('INSERT IGNORE INTO begeniler (hedef_tur, hedef_id, uye_id, tepki) VALUES (:t, :h, :u, :k)');
    $sayi   = 0;
    $profil = topluluk_icerik_profili($tur, $hedefId);

    foreach ($adaylar->fetchAll(PDO::FETCH_COLUMN) as $uyeId) {
        $ekle->execute(['t' => $tur, 'h' => $hedefId, 'u' => (int) $uyeId, 'k' => topluluk_tepki_sec($profil, $tur, $hedefId, (int) $uyeId)]);
        $sayi += $ekle->rowCount();
    }

    return $sayi;
}

/**
 * Begeni dagitimini sayfa isteginin sonunda calistirir; en fazla bes
 * dakikada bir (ayni anda gelen iki istekten yalnizca biri kazaniyor).
 * Hata sayfayi etkilemez.
 */
function topluluk_begeni_zamanla(): void
{
    // Onbellekten donen isteklerde veritabanina gitmemek icin once dosya saati
    // (sayfa onbelleginin yazilabilir dizininde; silinirse veritabani karar verir).
    $isaret = __DIR__ . '/../onbellek/sayfa/topluluk_begeni.zaman';

    if (is_file($isaret) && filemtime($isaret) > time() - 300) {
        return;
    }

    register_shutdown_function(static function () use ($isaret): void {
        @touch($isaret);

        try {
            db()->exec("INSERT IGNORE INTO ayarlar (anahtar, deger) VALUES ('topluluk_begeni_son', '0')");
            $al = db()->prepare(
                "UPDATE ayarlar SET deger = :simdi
                  WHERE anahtar = 'topluluk_begeni_son' AND CAST(deger AS UNSIGNED) < :esik"
            );
            $al->execute(['simdi' => (string) time(), 'esik' => time() - 300]);

            if ($al->rowCount() === 1) {
                topluluk_begeni_dagit();
            }
        } catch (Throwable $e) {
            error_log('topluluk_begeni_dagit: ' . $e->getMessage());
        }
    });
}
