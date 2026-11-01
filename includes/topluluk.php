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

/**
 * YZ hesaplarinin begenileri (site sahibinin karari, 31.10.2026: "yapay
 * zeka hesaplari gorunmesin, sadece begeni atsinlar").
 *
 * YZ hesaplari sitede hicbir yerde gorunmuyor (profil, Kisiler, bildirim
 * yok); yalnizca begeni sayilarina katiliyorlar. Topluluk Kurallari'nda
 * beğeni sayilarina YZ begenilerinin dahil oldugu yaziyor.
 *
 * Her icerigin bir hedefi var (id'den turetilen sabit sayi) ve begeniler
 * zamana yayiliyor: uye paylasimi ilk saatte 4-12, haber alti saatte 8-36
 * (onemli haber daha cok; 01.11.2026'da hesaplar 150'ye cikinca hedefler
 * de 1,5 katina cikti). Bildirim gitmiyor. Model kullanilmiyor.
 *
 * @return array{gonderi:int,haber:int}
 */
function topluluk_begeni_dagit(): array
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
        "SELECT h.id, TIMESTAMPDIFF(MINUTE, h.yayin_tarihi, NOW()) AS dk, h.onem
           FROM haberler h
          WHERE h.durum = :d AND h.yayin_tarihi <= NOW() AND h.yayin_tarihi >= NOW() - INTERVAL 1 DAY
          ORDER BY h.id DESC LIMIT 150"
    );
    $haberler->execute(['d' => HABER_YAYINDA]);

    $mevcut = db()->prepare(
        'SELECT b.uye_id FROM begeniler b JOIN uyeler u ON u.id = b.uye_id
          WHERE b.hedef_tur = :t AND b.hedef_id = :h AND u.tur = \'yz\''
    );
    $ekle  = db()->prepare('INSERT IGNORE INTO begeniler (hedef_tur, hedef_id, uye_id) VALUES (:t, :h, :u)');
    $kalan = 600; // tek calismada en fazla begeni

    foreach (['gonderi' => $gonderiler, 'haber' => $haberler->fetchAll()] as $tur => $liste) {
        foreach ($liste as $oge) {
            $id   = (int) $oge['id'];
            $tohum = crc32($tur . $id);

            if ($tur === 'gonderi') {
                $hedef = 4 + $tohum % 9;                                         // 4-12
                $oran  = ((int) $oge['dk'] - 8) / 50;                            // 8. dk - 1. saat
            } else {
                $hedef = 8 + $tohum % 19 + intdiv(max(0, (int) $oge['onem'] - 50), 5); // 8-36
                $oran  = ((int) $oge['dk'] - 10) / 350;                          // 10. dk - 6. saat
            }

            $istenen = (int) round($hedef * max(0, min(1, $oran)));

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

            foreach (array_slice($adaylar, 0, $eksik) as $uyeId) {
                $ekle->execute(['t' => $tur, 'h' => $id, 'u' => $uyeId]);
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
