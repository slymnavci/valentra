<?php
declare(strict_types=1);

/**
 * Haber yorumlari (Valentra Network, 31.10.2026; bkz. includes/uyelik.php).
 *
 * YAYIN POLITIKASI (site sahibinin onay sorusuna cevap vermeden "Facebook
 * ve Instagram'daki gibi" demesi uzerine): kufur/hakaret filtresinden
 * gecen yorum hemen yayinda. Okurlar sikayet edebiliyor; UC farkli uyenin
 * sikayeti yorumu kendiliginden gizliyor. Panelde (admin/yorumlar.php)
 * gizli ve sikayetli yorumlar ayri sekmede; yonetici geri acar, siler ya
 * da uyeyi engeller. 5651 sayili kanun geregi site bildirilen icerigi
 * kaldirmakla yukumlu: sikayet ve panel bu yuzden var.
 *
 * Yorum duz metin: HTML yok, baglantilar tiklanamaz (yazi olarak kalir),
 * en fazla YORUM_EN_FAZLA karakter. Ayni uye YORUM_ARALIK saniyede bir
 * yorum yazabiliyor (sel/spam korumasi).
 */

require_once __DIR__ . '/uyelik.php';

const YORUM_EN_AZ       = 2;
const YORUM_EN_FAZLA    = 1000;
const YORUM_ARALIK      = 20;
const YORUM_GIZLEME_ESIGI = 3;

/**
 * Kufur ve agir hakaret listesi (kelime koku). Kucuk harfe cevrilmis ve
 * Turkce harfleri sadelestirilmis metinde kelime basinda aranir; "sik"
 * gibi masum kelimelere takilan kisa kokler listede yok.
 */
const YORUM_YASAK_KOKLER = [
    'amk', 'aq ', 'amina', 'aminak', 'amcik', 'orospu', 'orosbu', 'pic ', 'piclik', 'yavsak',
    'siktir', 'sikeyim', 'sikerim', 'sikik', 'sokarim', 'gotveren', 'got veren', 'ibne',
    'pezevenk', 'kahpe', 'serefsiz', 'gavat', 'kaltak', 'yarrak', 'yarak', 'dalyarak',
    'anani sik', 'bacini sik', 'fuck', 'motherfucker',
];

function yorum_sadelestir(string $metin): string
{
    $kucuk = mb_strtolower(strtr($metin, ['I' => 'ı', 'İ' => 'i']), 'UTF-8');

    return ' ' . strtr($kucuk, ['ı' => 'i', 'ğ' => 'g', 'ü' => 'u', 'ş' => 's', 'ö' => 'o', 'ç' => 'c', 'â' => 'a', 'î' => 'i', 'û' => 'u']) . ' ';
}

function yorum_uygunsuz_mu(string $metin): bool
{
    // Harf tekrarlari ("siiiktir") ve araya konan nokta/yildiz ayiklaniyor;
    // harf ve rakam disindaki her sey bosluk: kelime basi " kok" ile bulunur.
    $sade = yorum_sadelestir($metin);
    $sade = preg_replace('/([a-z])\1{2,}/', '$1', $sade) ?? $sade;
    $sik  = ' ' . trim(preg_replace('/[^a-z0-9 ]+/', ' ', preg_replace('/[.*_\-]+/', '', $sade) ?? $sade) ?? '') . ' ';
    $sade = ' ' . trim(preg_replace('/[^a-z0-9]+/', ' ', $sade) ?? '') . ' ';

    foreach (YORUM_YASAK_KOKLER as $kok) {
        // Sonu bosluklu kok ("aq ") yalnizca tam kelime olarak sayiliyor.
        $aranan = ' ' . trim(yorum_sadelestir($kok)) . (str_ends_with($kok, ' ') ? ' ' : '');

        if (str_contains($sade, $aranan) || str_contains($sik, $aranan)) {
            return true;
        }
    }

    return false;
}

/**
 * Yorum ekler; yorumun kimligini dondurur. Kurala uymayan yorumda
 * InvalidArgumentException (ziyaretciye gosterilecek mesaj).
 */
function yorum_ekle(int $haberId, int $uyeId, string $metin, string $tur = 'haber'): int
{
    // Uye paylasiminin yorumu (V Network, 31.10.2026): $haberId paylasimin kimligi.
    $sutun = yorum_hedef_sutunu($tur);

    $metin = trim(preg_replace("/\r\n?/", "\n", $metin) ?? '');
    $metin = preg_replace("/\n{3,}/", "\n\n", $metin) ?? $metin;
    $uzunluk = mb_strlen($metin, 'UTF-8');

    if ($uzunluk < YORUM_EN_AZ) {
        throw new InvalidArgumentException('Yorum boş olamaz.');
    }

    if ($uzunluk > YORUM_EN_FAZLA) {
        throw new InvalidArgumentException(YORUM_HATALARI[1]);
    }

    if (yorum_uygunsuz_mu($metin)) {
        throw new InvalidArgumentException('Yorumunuz küfür ya da hakaret içerdiği için yayımlanmadı. Lütfen saygılı bir dille yeniden yazın.');
    }

    $haber = $tur === 'gonderi'
        ? db()->prepare("SELECT id FROM gonderiler WHERE id = :id AND durum = :d")
        : db()->prepare('SELECT id FROM haberler WHERE id = :id AND durum = :d');
    $haber->execute(['id' => $haberId, 'd' => $tur === 'gonderi' ? 'yayinda' : HABER_YAYINDA]);

    if ($haber->fetchColumn() === false) {
        throw new InvalidArgumentException('Haber bulunamadı.');
    }

    $son = db()->prepare('SELECT TIMESTAMPDIFF(SECOND, MAX(olusturuldu), NOW()) FROM yorumlar WHERE uye_id = :u');
    $son->execute(['u' => $uyeId]);
    $gecen = $son->fetchColumn();

    if ($gecen !== null && $gecen !== false && (int) $gecen < YORUM_ARALIK) {
        throw new InvalidArgumentException('Çok hızlı yorum yazıyorsunuz; birkaç saniye sonra yeniden deneyin.');
    }

    $ip = function_exists('ziyaret_adresi') ? ziyaret_adresi() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    db()->prepare('INSERT INTO yorumlar (' . $sutun . ', uye_id, metin, ip) VALUES (:h, :u, :m, :ip)')
        ->execute(['h' => $haberId, 'u' => $uyeId, 'm' => $metin, 'ip' => mb_substr($ip, 0, 45)]);
    $yorumId = (int) db()->lastInsertId();

    // Paylasimin sahibine bildirim (V Network, 31.10.2026).
    if ($tur === 'gonderi') {
        require_once __DIR__ . '/bildirimler.php';
        bildirim_ekle(bildirim_gonderi_sahibi($haberId), $uyeId, 'yorum', $haberId, $yorumId);
    }

    return $yorumId;
}

/**
 * Bir haberin yayindaki yorumlari, eskiden yeniye.
 *
 * @return list<array<string,mixed>>
 */
function yorum_listesi(int $haberId, int $limit = 200, string $tur = 'haber'): array
{
    $sutun = yorum_hedef_sutunu($tur);

    try {
        $ifade = db()->prepare(
            "SELECT y.id, y.uye_id, y.metin, y.olusturuldu, u.ad, u.foto
               FROM yorumlar y
               JOIN uyeler u ON u.id = y.uye_id
              WHERE y.$sutun = :h AND y.durum = 'yayinda' AND u.durum = 'aktif'
              ORDER BY y.olusturuldu, y.id
              LIMIT :l"
        );
        $ifade->bindValue('h', $haberId, PDO::PARAM_INT);
        $ifade->bindValue('l', $limit, PDO::PARAM_INT);
        $ifade->execute();

        return $ifade->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Akis icin: her haberin yorum sayisi ve en yeni iki yorumu (eskiden
 * yeniye). Tek sorguda sayilar, tek sorguda son yorumlar.
 *
 * @param list<int> $haberIdleri
 * @return array<int, array{sayi:int, son:list<array<string,mixed>>}>
 */
function yorum_ozetleri(array $haberIdleri, int $sonAdet = 2, string $tur = 'haber'): array
{
    $sutun = yorum_hedef_sutunu($tur);
    $haberIdleri = array_values(array_unique(array_filter(array_map('intval', $haberIdleri))));
    $sonuc       = [];

    foreach ($haberIdleri as $id) {
        $sonuc[$id] = ['sayi' => 0, 'son' => []];
    }

    if ($haberIdleri === []) {
        return $sonuc;
    }

    $yer = implode(',', $haberIdleri);

    try {
        foreach (db()->query(
            "SELECT y.$sutun AS hedef, COUNT(*) AS sayi
               FROM yorumlar y JOIN uyeler u ON u.id = y.uye_id
              WHERE y.$sutun IN ($yer) AND y.durum = 'yayinda' AND u.durum = 'aktif'
              GROUP BY y.$sutun"
        ) as $s) {
            $sonuc[(int) $s['hedef']]['sayi'] = (int) $s['sayi'];
        }

        // Her haberin en yeni N yorumu (pencere fonksiyonu MariaDB 10.2+).
        foreach (db()->query(
            "SELECT * FROM (
                SELECT y.id, y.$sutun AS hedef, y.uye_id, y.metin, y.olusturuldu, u.ad, u.foto,
                       ROW_NUMBER() OVER (PARTITION BY y.$sutun ORDER BY y.olusturuldu DESC, y.id DESC) AS sira
                  FROM yorumlar y JOIN uyeler u ON u.id = y.uye_id
                 WHERE y.$sutun IN ($yer) AND y.durum = 'yayinda' AND u.durum = 'aktif'
             ) t WHERE t.sira <= " . max(1, $sonAdet) . '
             ORDER BY t.olusturuldu, t.id'
        ) as $s) {
            $sonuc[(int) $s['hedef']]['son'][] = $s;
        }
    } catch (PDOException $e) {
        // Tablo yoksa yorumsuz goster.
    }

    return $sonuc;
}

/** Yorumun bagli oldugu sutun: haber ya da uye paylasimi (sabit liste, SQL'e giriyor). */
function yorum_hedef_sutunu(string $tur): string
{
    return $tur === 'gonderi' ? 'gonderi_id' : 'haber_id';
}

/** Sikayet; esige ulasan yorum gizleniyor. Ayni uye bir yorumu bir kez sikayet edebilir. */
function yorum_sikayet(int $yorumId, int $uyeId): void
{
    $ekle = db()->prepare('INSERT IGNORE INTO yorum_sikayetleri (yorum_id, uye_id) VALUES (:y, :u)');
    $ekle->execute(['y' => $yorumId, 'u' => $uyeId]);

    if ($ekle->rowCount() === 0) {
        return;
    }

    db()->prepare(
        "UPDATE yorumlar
            SET sikayet = sikayet + 1,
                durum = IF(sikayet + 1 >= :esik, 'gizli', durum)
          WHERE id = :y"
    )->execute(['y' => $yorumId, 'esik' => YORUM_GIZLEME_ESIGI]);
}

/** Uye kendi yorumunu siler; $uyeId null ise yonetici (herkesinkini). */
function yorum_kendi_sil(int $yorumId, ?int $uyeId): bool
{
    $ifade = db()->prepare('DELETE FROM yorumlar WHERE id = :y' . ($uyeId !== null ? ' AND uye_id = :u' : ''));
    $ifade->execute($uyeId !== null ? ['y' => $yorumId, 'u' => $uyeId] : ['y' => $yorumId]);

    return $ifade->rowCount() > 0;
}

/**
 * Yorum isleminin sonucu: adresteki kisa kod -> ziyaretciye gosterilecek
 * mesaj. Hata metinleri yorum_ekle'nin firlattiklari; adreste yalnizca
 * siralari (h0, h1...) tasiniyor.
 */
const YORUM_SONUCLARI = [
    'eklendi'    => ['basari', 'Yorumunuz yayımlandı.'],
    'silindi'    => ['bilgi',  'Yorumunuz silindi.'],
    'bildirildi' => ['bilgi',  'Bildiriminiz alındı, teşekkürler. Yorum site yönetimince incelenecek.'],
    'jeton'      => ['hata',   'Sayfanın süresi doldu; lütfen yeniden deneyin.'],
    'dogrula'    => ['hata',   'Yorum yazabilmek için önce e-posta adresinizi doğrulayın (bağlantı e-posta kutunuzda; gelmediyse V Network\'ün üstündeki şeritten yeniden isteyin).'],
];

const YORUM_HATALARI = [
    'Yorum boş olamaz.',
    'Yorum en fazla 1000 karakter olabilir.',
    'Yorumunuz küfür ya da hakaret içerdiği için yayımlanmadı. Lütfen saygılı bir dille yeniden yazın.',
    'Haber bulunamadı.',
    'Çok hızlı yorum yazıyorsunuz; birkaç saniye sonra yeniden deneyin.',
];

function yorum_hata_kodu(string $mesaj): string
{
    $sira = array_search($mesaj, YORUM_HATALARI, true);

    return $sira === false ? 'h' : 'h' . $sira;
}

/** @return array{0:string,1:string}|null [tur, mesaj] */
function yorum_sonuc_mesaji(string $kod): ?array
{
    if (isset(YORUM_SONUCLARI[$kod])) {
        return YORUM_SONUCLARI[$kod];
    }

    if (preg_match('/^h(\d*)$/', $kod, $e)) {
        return ['hata', YORUM_HATALARI[(int) $e[1]] ?? 'Yorum gönderilemedi; lütfen yeniden deneyin.'];
    }

    return null;
}

/** Yorum metni: duz metin, satir sonlari korunuyor. */
function yorum_metin_html(string $metin): string
{
    return nl2br(e($metin), false);
}

/** "3 dk", "2 sa", "5 g" ya da tarih; Facebook/Instagram gibi kisa sure. */
function yorum_zaman(string $tarih): string
{
    $zaman = strtotime($tarih);

    if ($zaman === false) {
        return '';
    }

    $fark = max(0, time() - $zaman);

    return match (true) {
        $fark < 60      => 'şimdi',
        $fark < 3600    => intdiv($fark, 60) . ' dk',
        $fark < 86400   => intdiv($fark, 3600) . ' sa',
        $fark < 604800  => intdiv($fark, 86400) . ' g',
        default         => tarih_bicimle($tarih, false),
    };
}

/**
 * Uyenin yuvarlak simgesi: profil resmi varsa resim, yoksa renkli zemin
 * uzerinde adinin bas harfi.
 */
function uye_simge_html(array $uye, int $boyut = 34): string
{
    $ad   = (string) ($uye['ad'] ?? '');
    $foto = function_exists('nw_foto_adresi') ? nw_foto_adresi((string) ($uye['foto'] ?? '')) : '';
    $stil = 'width:' . $boyut . 'px;height:' . $boyut . 'px;font-size:' . round($boyut * .42) . 'px';

    if ($foto !== '') {
        return '<img class="uye-simge" src="' . e($foto) . '" alt="" width="' . $boyut . '" height="' . $boyut . '" loading="lazy" style="' . $stil . '" onerror="this.removeAttribute(\'src\')">';
    }

    return '<span class="uye-simge" style="--uye-renk: ' . e(uye_renk($ad)) . ';' . $stil . '" aria-hidden="true">' . e(uye_bas_harf($ad)) . '</span>';
}

/** Uyenin bas harfi (yuvarlak simge icin). */
function uye_bas_harf(string $ad): string
{
    return mb_strtoupper(mb_substr(trim($ad), 0, 1, 'UTF-8'), 'UTF-8') ?: '?';
}

/** Ada gore sabit bir simge rengi (her uye hep ayni renkte). */
function uye_renk(string $ad): string
{
    $renkler = ['#d7161f', '#0f7b4a', '#1d4ed8', '#7c3aed', '#c2410c', '#0e7490', '#be185d', '#4d7c0f'];

    return $renkler[abs(crc32(mb_strtolower($ad, 'UTF-8'))) % count($renkler)];
}
