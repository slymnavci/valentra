<?php
declare(strict_types=1);

/**
 * V Network yapay zeka ve kurum hesaplari (31.10.2026).
 *
 * Site sahibinin istegi: "V Network'te 100 uye yapalim, biraz kalabalik
 * gorunsun". Gercek kisi gibi gorunen sahte hesap acilmadi: ziyaretci
 * onlari takip eder, tanismaya calisir. Bunun yerine ACIKCA ISARETLI
 * hesaplar (site sahibinin onayi, ayni gun):
 *
 *   - yz:    100 yapay zeka hesabi (50 kadin, 50 erkek rumuz ve kisilik;
 *            ajan/veri/topluluk_hesaplari.json). Adlarinin yaninda her
 *            yerde "YZ" rozeti, profilinde "gercek bir kisi degildir" notu.
 *   - kurum: ValHaber, ValSpor gibi Valentra hesaplari ("Resmi" rozeti).
 *
 * Ikisi de giris yapamaz (sifreleri kimsenin bilmedigi rastgele bir
 * degerin ozeti), ozel mesaj alip gonderemez, gercek uyeleri takip etmez.
 * Paylasim, yorum ve begenileri ajan yapiyor (ajan/topluluk.php,
 * api/topluluk.php); her eylem gercek uyelerle ayni kurallardan (kufur
 * filtresi, siklik siniri) geciyor.
 */

require_once __DIR__ . '/network.php';

const TOPLULUK_TURLERI       = ['yz', 'kurum'];
/** Bir hesabin gunde en fazla paylasim + yorum sayisi (begeni haric). */
const TOPLULUK_GUNLUK_TAVAN  = 8;
/** Tek istekte en fazla eylem. */
const TOPLULUK_ISTEK_TAVANI  = 80;

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

    foreach (array_slice($hesaplar, 0, 200) as $h) {
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
            // Kimsenin bilmedigi bir sifrenin ozeti: bu hesaplarla giris yapilamaz.
            's'  => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            't'  => $tur,
        ]);
        $sonuc['eklenen']++;
    }

    return $sonuc;
}

/**
 * Ajanin malzemesi: hesaplar ve bugunku eylem sayilari, son haberler,
 * son paylasimlar.
 *
 * @return array<string,mixed>
 */
function topluluk_malzeme(): array
{
    $hesaplar = db()->query(
        "SELECT u.id, u.ad AS rumuz, u.tur,
                (SELECT COUNT(*) FROM gonderiler g WHERE g.uye_id = u.id AND g.olusturuldu >= CURDATE())
              + (SELECT COUNT(*) FROM yorumlar y WHERE y.uye_id = u.id AND y.olusturuldu >= CURDATE()) AS bugun
           FROM uyeler u WHERE u.tur IN ('yz','kurum') AND u.durum = 'aktif' ORDER BY u.id"
    )->fetchAll();

    $haberler = db()->prepare(
        "SELECT h.id, h.baslik, LEFT(h.ozet, 300) AS ozet, h.etiketler, k.ad AS kategori,
                (SELECT COUNT(*) FROM yorumlar y WHERE y.haber_id = h.id AND y.durum = 'yayinda') AS yorum
           FROM haberler h LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = :d AND h.yayin_tarihi >= NOW() - INTERVAL 10 HOUR
          ORDER BY h.onem DESC, h.yayin_tarihi DESC LIMIT 30"
    );
    $haberler->execute(['d' => HABER_YAYINDA]);

    $paylasimlar = db()->query(
        "SELECT g.id, u.ad AS rumuz, u.tur, LEFT(g.metin, 400) AS metin, g.olusturuldu,
                (SELECT COUNT(*) FROM yorumlar y WHERE y.gonderi_id = g.id AND y.durum = 'yayinda') AS yorum
           FROM gonderiler g JOIN uyeler u ON u.id = g.uye_id
          WHERE g.durum = 'yayinda' AND u.durum = 'aktif' AND g.olusturuldu >= NOW() - INTERVAL 24 HOUR
          ORDER BY g.id DESC LIMIT 25"
    )->fetchAll();

    return [
        'tavan'       => ['gunluk' => TOPLULUK_GUNLUK_TAVAN, 'istek' => TOPLULUK_ISTEK_TAVANI],
        'hesaplar'    => $hesaplar,
        'haberler'    => $haberler->fetchAll(),
        'paylasimlar' => $paylasimlar,
    ];
}

/**
 * Ajanin eylemlerini uygular. Her eylem gercek uyelerinkiyle ayni
 * islevlerden geciyor (kufur filtresi, siklik siniri, bildirim).
 *
 * Eylem: {"hesap": rumuz, "tur": "paylasim"|"yorum"|"begeni",
 *         "hedef": "h12" (haber) | "g5" (paylasim), "metin": "..."}
 *
 * @param list<array<string,mixed>> $eylemler
 * @return array{yapilan:array<string,int>,hatalar:list<string>}
 */
function topluluk_uygula(array $eylemler): array
{
    $sonuc = ['yapilan' => ['paylasim' => 0, 'yorum' => 0, 'begeni' => 0], 'hatalar' => []];
    $hesap = db()->prepare(
        "SELECT u.id,
                (SELECT COUNT(*) FROM gonderiler g WHERE g.uye_id = u.id AND g.olusturuldu >= CURDATE())
              + (SELECT COUNT(*) FROM yorumlar y WHERE y.uye_id = u.id AND y.olusturuldu >= CURDATE()) AS bugun
           FROM uyeler u WHERE u.ad = :r AND u.tur IN ('yz','kurum') AND u.durum = 'aktif'"
    );

    foreach (array_slice($eylemler, 0, TOPLULUK_ISTEK_TAVANI) as $e) {
        $rumuz = (string) ($e['hesap'] ?? '');
        $tur   = (string) ($e['tur'] ?? '');
        $hedef = (string) ($e['hedef'] ?? '');
        $metin = (string) ($e['metin'] ?? '');

        $hesap->execute(['r' => $rumuz]);
        $h = $hesap->fetch();

        if (!is_array($h)) {
            $sonuc['hatalar'][] = $rumuz . ': yapay zekâ ya da kurum hesabı değil';
            continue;
        }

        $uyeId = (int) $h['id'];
        $hedefTur = str_starts_with($hedef, 'g') ? 'gonderi' : 'haber';
        $hedefId  = (int) substr($hedef, 1);

        try {
            if ($tur === 'begeni') {
                $var = db()->prepare('SELECT 1 FROM begeniler WHERE hedef_tur = :t AND hedef_id = :h AND uye_id = :u');
                $var->execute(['t' => $hedefTur, 'h' => $hedefId, 'u' => $uyeId]);

                // begeni_degistir acip kapatiyor; zaten begenilmisse dokunma.
                if (!$var->fetchColumn() && $hedefId > 0) {
                    begeni_degistir($hedefTur, $hedefId, $uyeId);
                    $sonuc['yapilan']['begeni']++;
                }

                continue;
            }

            if ((int) $h['bugun'] >= TOPLULUK_GUNLUK_TAVAN) {
                $sonuc['hatalar'][] = $rumuz . ': günlük tavan dolu';
                continue;
            }

            if ($tur === 'paylasim') {
                gonderi_ekle($uyeId, $metin);
                $sonuc['yapilan']['paylasim']++;
            } elseif ($tur === 'yorum' && $hedefId > 0) {
                yorum_ekle($hedefId, $uyeId, $metin, $hedefTur);
                $sonuc['yapilan']['yorum']++;
            } else {
                $sonuc['hatalar'][] = $rumuz . ': bilinmeyen eylem';
            }
        } catch (InvalidArgumentException $ex) {
            $sonuc['hatalar'][] = $rumuz . ': ' . $ex->getMessage();
        }
    }

    return $sonuc;
}
