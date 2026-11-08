<?php
declare(strict_types=1);

/**
 * Benzer haberleri birlestirme (08.11.2026).
 *
 * Okur denetimi: ayni gelismenin iki motorin haberi ayri ayri yayinda,
 * ikisi de 200 donuyor ve kendi canonical adresini tasiyordu; enflasyon
 * ve emekli dosyalarinda ayni aciklamanin cok sayida benzer haberi
 * vardi. Yeni haberlerde kopya denetimi calisiyor; bu dosya ESKI
 * icerikler icin:
 *
 *   haber_benzer_ciftler()   ayni gunlerde, basligi buyuk olcude ortak
 *                            haber ciftleri (panel -> Benzer haberler)
 *   haber_birlestir()        biri digerine birlesir: eski adres 301 ile
 *                            yasayan habere gider, eski kayit yayindan
 *                            kalkar (bkz. haber_yonlendirmeleri tablosu)
 *   haber_yonlendirme_hedefi() haber sayfasi, bulunamayan adres icin
 */

/** Benzer sayılmak için en az Jaccard benzerliği ve ortak kelime. */
const HABER_BENZER_ESIK  = 0.5;
const HABER_BENZER_ORTAK = 3;

/** İki haber arasında en fazla bu kadar saat varsa karşılaştırılır. */
const HABER_BENZER_SAAT = 72;

/**
 * Son günlerin benzer haber çiftleri, en benzer önce.
 *
 * @return list<array{a:array<string,mixed>,b:array<string,mixed>,benzerlik:float}>
 */
function haber_benzer_ciftler(int $gun = 30, int $adet = 60): array
{
    $ifade = db()->prepare(
        "SELECT h.id, h.baslik, h.slug, h.yayin_tarihi, h.onem, CHAR_LENGTH(h.icerik) AS uzunluk, k.ad AS kategori_adi
           FROM haberler h LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = 'yayinda' AND h.yayin_tarihi >= NOW() - INTERVAL :gun DAY
          ORDER BY h.yayin_tarihi DESC
          LIMIT 2000"
    );
    $ifade->bindValue('gun', max(1, $gun), PDO::PARAM_INT);
    $ifade->execute();
    $haberler = $ifade->fetchAll();

    foreach ($haberler as &$h) {
        // Kokler haber_ilgililer ile ayni olcu (bkz. haber_baslik_kokleri).
        $h['_kok']   = array_flip(haber_baslik_kokleri((string) $h['baslik']));
        $h['_zaman'] = strtotime((string) $h['yayin_tarihi']) ?: 0;
    }
    unset($h);

    $ciftler = [];
    $sayi    = count($haberler);

    for ($i = 0; $i < $sayi; $i++) {
        $a = $haberler[$i];

        // Yeniden eskiye sirali: zaman penceresi asilinca ic dongu biter.
        for ($j = $i + 1; $j < $sayi && $a['_zaman'] - $haberler[$j]['_zaman'] <= HABER_BENZER_SAAT * 3600; $j++) {
            $b      = $haberler[$j];
            $ortak  = count(array_intersect_key($a['_kok'], $b['_kok']));
            $birlik = count($a['_kok'] + $b['_kok']);

            if ($ortak < HABER_BENZER_ORTAK || $birlik === 0) {
                continue;
            }

            $benzerlik = $ortak / $birlik;

            if ($benzerlik >= HABER_BENZER_ESIK) {
                $ciftler[] = [
                    'a'         => array_diff_key($a, ['_kok' => 1]),
                    'b'         => array_diff_key($b, ['_kok' => 1]),
                    'benzerlik' => $benzerlik,
                ];
            }
        }
    }

    usort($ciftler, static fn (array $x, array $y): int => $y['benzerlik'] <=> $x['benzerlik']);

    return array_slice($ciftler, 0, $adet);
}

/**
 * Önerilen yaşayan haber: daha uzun (daha çok bilgi), eşitse önemi yüksek,
 * o da eşitse daha eski olan (ilk yayımlanan adres korunur).
 *
 * @param array<string,mixed> $a
 * @param array<string,mixed> $b
 * @return array{0:array<string,mixed>,1:array<string,mixed>} [kalan, birlesen]
 */
function haber_birlestirme_onerisi(array $a, array $b): array
{
    $puan = static fn (array $h): array => [(int) $h['uzunluk'], (int) $h['onem'], -(int) strtotime((string) $h['yayin_tarihi'])];

    return $puan($a) >= $puan($b) ? [$a, $b] : [$b, $a];
}

/**
 * $kaynakId haberini $hedefId haberine birleştirir.
 *
 * @throws InvalidArgumentException
 */
function haber_birlestir(int $kaynakId, int $hedefId): void
{
    if ($kaynakId <= 0 || $hedefId <= 0 || $kaynakId === $hedefId) {
        throw new InvalidArgumentException('İki farklı haber seçilmeli.');
    }

    $bul = db()->prepare('SELECT id, slug, durum FROM haberler WHERE id = :id LIMIT 1');
    $bul->execute(['id' => $kaynakId]);
    $kaynak = $bul->fetch();
    $bul->execute(['id' => $hedefId]);
    $hedef = $bul->fetch();

    if (!$kaynak || !$hedef) {
        throw new InvalidArgumentException('Haber bulunamadı.');
    }

    if ($hedef['durum'] !== 'yayinda') {
        throw new InvalidArgumentException('Birleştirilecek ana haber yayında değil.');
    }

    $db = db();
    $db->beginTransaction();

    try {
        $db->prepare(
            'INSERT INTO haber_yonlendirmeleri (eski_slug, eski_id, hedef_id) VALUES (:s, :e, :h)
             ON DUPLICATE KEY UPDATE eski_id = VALUES(eski_id), hedef_id = VALUES(hedef_id)'
        )->execute(['s' => $kaynak['slug'], 'e' => $kaynakId, 'h' => $hedefId]);

        // Daha once bu habere birlestirilmis adresler de yeni hedefe
        // (zincir yonlendirme olmasin: A -> B -> C yerine A -> C).
        $db->prepare('UPDATE haber_yonlendirmeleri SET hedef_id = :h WHERE hedef_id = :e')
           ->execute(['h' => $hedefId, 'e' => $kaynakId]);

        $db->prepare("UPDATE haberler SET durum = 'reddedildi' WHERE id = :id")->execute(['id' => $kaynakId]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/** Birleştirilmiş eski adresin yaşayan haberinin slug'ı; yoksa null. */
function haber_yonlendirme_hedefi(string $slug): ?string
{
    try {
        $ifade = db()->prepare(
            "SELECT h.slug FROM haber_yonlendirmeleri y JOIN haberler h ON h.id = y.hedef_id
              WHERE y.eski_slug = :s AND h.durum = 'yayinda' LIMIT 1"
        );
        $ifade->execute(['s' => $slug]);
        $hedef = $ifade->fetchColumn();
    } catch (PDOException $e) {
        // Tablo henuz yoksa (sema yukseltilmediyse) eski davranis: 404.
        return null;
    }

    return $hedef === false ? null : (string) $hedef;
}
