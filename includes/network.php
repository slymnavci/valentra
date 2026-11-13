<?php
declare(strict_types=1);

/**
 * V Network sosyal alan (31.10.2026).
 *
 * Site sahibinin istegi: "V Network biraz daha Facebook tarzi olsun:
 * insanlar tanisabilsin, birbirleriyle sohbet edebilsin, yorum
 * yapabilsin, haberlerin altina yazilar yazabilsin; kufur vs yasak, bunu
 * bildiren bir yazi olsun; tikladigimizda farkli bir sayfaya gidelim;
 * haberler sirayla gelsin, altina yorum yazabilelim; baska insanlari
 * takip edebilelim, biz de takipci kazanalim."
 *
 *   - Akis: ValHaber'in paylastigi haberler ve uyelerin kendi
 *     paylasimlari, en yeni ustte. "Takip ettiklerim" sekmesinde yalnizca
 *     haberler, takip edilenlerin ve uyenin kendi paylasimlari.
 *   - Profil: ad, tanitim yazisi, takipci/takip sayisi, paylasimlar.
 *   - Takip: tek yonlu. Karsilikli takiplesen iki uye "arkadas"; yalnizca
 *     arkadaslar ozel mesajlasabiliyor (istenmeyen mesaj ve taciz
 *     korumasi; Topluluk Kurallari'nda yaziyor).
 *   - Yayin politikasi yorumlarla ayni (includes/yorumlar.php): kufur
 *     filtresi, sikayet, uc sikayette gizleme, panelden yonetim.
 */

require_once __DIR__ . '/yorumlar.php';
require_once __DIR__ . '/network_foto.php';
require_once __DIR__ . '/bildirimler.php';

const GONDERI_EN_FAZLA = 2000;
const GONDERI_ARALIK   = 30;
const MESAJ_EN_FAZLA   = 2000;
const MESAJ_ARALIK     = 2;
const HAKKINDA_EN_FAZLA = 300;

/** Sayfa adresleri: /network, /network/kisiler, /network/u/5 ... */
function nw_yol(string $alt = '', array $sorgu = []): string
{
    $sorgu = array_filter($sorgu, static fn ($d): bool => $d !== '' && $d !== null && $d !== 0);

    return '/network' . ($alt !== '' ? '/' . $alt : '') . ($sorgu !== [] ? '?' . http_build_query($sorgu) : '');
}

function nw_profil_yolu(int $uyeId): string
{
    return nw_yol('u/' . $uyeId);
}

function nw_gonderi_yolu(int $gonderiId): string
{
    return nw_yol('g/' . $gonderiId);
}

function nw_ip(): string
{
    return mb_substr(function_exists('ziyaret_adresi') ? ziyaret_adresi() : (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** Duz metni ortak kurallara gore temizler ve sinar; temiz metni dondurur. */
function nw_metin_sina(string $metin, int $enFazla, string $ne): string
{
    $metin = trim(preg_replace("/\r\n?/", "\n", $metin) ?? '');
    $metin = preg_replace("/\n{3,}/", "\n\n", $metin) ?? $metin;
    $uzunluk = mb_strlen($metin, 'UTF-8');

    if ($uzunluk < 1) {
        throw new InvalidArgumentException($ne . ' boş olamaz.');
    }

    if ($uzunluk > $enFazla) {
        throw new InvalidArgumentException($ne . ' en fazla ' . $enFazla . ' karakter olabilir.');
    }

    if (yorum_uygunsuz_mu($metin)) {
        throw new InvalidArgumentException($ne . ' küfür ya da hakaret içerdiği için gönderilmedi. Lütfen saygılı bir dille yeniden yazın.');
    }

    return $metin;
}

/** Ayni uyenin son kaydindan bu yana gecen saniye (tablo/sutun sabit). */
function nw_son_kayittan_beri(string $tablo, string $sutun, int $uyeId): ?int
{
    $ifade = db()->prepare('SELECT TIMESTAMPDIFF(SECOND, MAX(olusturuldu), NOW()) FROM ' . $tablo . ' WHERE ' . $sutun . ' = :u');
    $ifade->execute(['u' => $uyeId]);
    $gecen = $ifade->fetchColumn();

    return $gecen === null || $gecen === false ? null : (int) $gecen;
}

/* --- Paylasimlar --------------------------------------------------------- */

/**
 * Paylasim: yazi, istege bagli video baglantisi ve en fazla dort fotograf
 * ($fotoJson: tarayicinin kuculttugu data: adresleri, bkz.
 * includes/network_foto.php). Fotograf ya da video varsa yazi bos olabilir.
 */
function gonderi_ekle(int $uyeId, string $metin, string $videoBaglanti = '', string $fotoJson = ''): int
{
    $video  = nw_video_gomme($videoBaglanti);
    $fotolar = nw_foto_listesi($fotoJson, $uyeId);

    if (trim($metin) === '' && ($video !== '' || $fotolar !== [])) {
        $metin = '';
    } else {
        $metin = nw_metin_sina($metin, GONDERI_EN_FAZLA, 'Paylaşım');
    }

    $gecen = nw_son_kayittan_beri('gonderiler', 'uye_id', $uyeId);

    if ($gecen !== null && $gecen < GONDERI_ARALIK) {
        throw new InvalidArgumentException('Çok sık paylaşım yapıyorsunuz; biraz sonra yeniden deneyin.');
    }

    $kaydedilen = [];
    db()->beginTransaction();

    try {
        db()->prepare('INSERT INTO gonderiler (uye_id, metin, video_url, ip) VALUES (:u, :m, :v, :ip)')
            ->execute(['u' => $uyeId, 'm' => $metin, 'v' => $video, 'ip' => nw_ip()]);
        $id = (int) db()->lastInsertId();

        $ekle = db()->prepare('INSERT INTO gonderi_fotolari (gonderi_id, uye_id, dosya, genislik, yukseklik) VALUES (:g, :u, :d, :w, :h)');

        foreach ($fotolar as $veri) {
            $foto = nw_foto_kaydet($veri);
            $kaydedilen[] = $foto['dosya'];
            $ekle->execute(['g' => $id, 'u' => $uyeId, 'd' => $foto['dosya'], 'w' => $foto['genislik'], 'h' => $foto['yukseklik']]);
        }

        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();

        foreach ($kaydedilen as $dosya) {
            nw_foto_sil($dosya);
        }

        throw $e;
    }

    return $id;
}

/**
 * Paylasimlarin fotograflari.
 *
 * @param list<int> $idler
 * @return array<int, list<array{dosya:string,genislik:int,yukseklik:int}>>
 */
function gonderi_fotolari(array $idler): array
{
    $idler = array_values(array_unique(array_filter(array_map('intval', $idler))));
    $sonuc = [];

    if ($idler === []) {
        return $sonuc;
    }

    try {
        foreach (db()->query('SELECT gonderi_id, dosya, genislik, yukseklik FROM gonderi_fotolari
                               WHERE gonderi_id IN (' . implode(',', $idler) . ') ORDER BY id') as $f) {
            $sonuc[(int) $f['gonderi_id']][] = $f;
        }
    } catch (PDOException $e) {
        return [];
    }

    return $sonuc;
}

function gonderi_bul(int $id): ?array
{
    $ifade = db()->prepare(
        "SELECT g.*, u.ad, u.foto, u.tur, u.rol FROM gonderiler g JOIN uyeler u ON u.id = g.uye_id
          WHERE g.id = :id AND g.durum = 'yayinda' AND u.durum = 'aktif'"
    );
    $ifade->execute(['id' => $id]);
    $satir = $ifade->fetch();

    return is_array($satir) ? $satir : null;
}

/** Uye kendi paylasimini siler; $uyeId null ise yonetici (herkesinkini). */
function gonderi_kendi_sil(int $id, ?int $uyeId): bool
{
    $sahip = $uyeId !== null ? ' AND g.uye_id = :u' : '';
    $deger = $uyeId !== null ? ['id' => $id, 'u' => $uyeId] : ['id' => $id];

    $fotolar = db()->prepare('SELECT f.dosya FROM gonderi_fotolari f JOIN gonderiler g ON g.id = f.gonderi_id WHERE g.id = :id' . $sahip);
    $fotolar->execute($deger);
    $dosyalar = $fotolar->fetchAll(PDO::FETCH_COLUMN);

    $ifade = db()->prepare('DELETE g FROM gonderiler g WHERE g.id = :id' . $sahip);
    $ifade->execute($deger);

    if ($ifade->rowCount() === 0) {
        return false;
    }

    foreach ($dosyalar as $dosya) {
        nw_foto_sil((string) $dosya);
    }

    return true;
}

function gonderi_sikayet(int $id, int $uyeId): void
{
    $ekle = db()->prepare('INSERT IGNORE INTO gonderi_sikayetleri (gonderi_id, uye_id) VALUES (:g, :u)');
    $ekle->execute(['g' => $id, 'u' => $uyeId]);

    if ($ekle->rowCount() === 0) {
        return;
    }

    db()->prepare(
        "UPDATE gonderiler SET sikayet = sikayet + 1, durum = IF(sikayet + 1 >= :esik, 'gizli', durum) WHERE id = :g"
    )->execute(['g' => $id, 'esik' => YORUM_GIZLEME_ESIGI]);
}

/* --- Takip --------------------------------------------------------------- */

function takip_et(int $eden, int $edilen): void
{
    if ($eden === $edilen) {
        throw new InvalidArgumentException('Kendinizi takip edemezsiniz.');
    }

    if (nw_uye_bul($edilen) === null) {
        throw new InvalidArgumentException('Üye bulunamadı.');
    }

    $ekle = db()->prepare('INSERT IGNORE INTO takipler (takip_eden, takip_edilen) VALUES (:a, :b)');
    $ekle->execute(['a' => $eden, 'b' => $edilen]);

    if ($ekle->rowCount() > 0) {
        bildirim_ekle($edilen, $eden, 'takip');
    }
}

function takip_birak(int $eden, int $edilen): void
{
    db()->prepare('DELETE FROM takipler WHERE takip_eden = :a AND takip_edilen = :b')
        ->execute(['a' => $eden, 'b' => $edilen]);
    bildirim_sil($edilen, $eden, 'takip');
}

/** @return array{takip:bool, takipci:bool} a, b'yi takip ediyor mu; b, a'yi takip ediyor mu */
function takip_durumu(int $a, int $b): array
{
    $ifade = db()->prepare(
        'SELECT
            EXISTS(SELECT 1 FROM takipler WHERE takip_eden = :a1 AND takip_edilen = :b1),
            EXISTS(SELECT 1 FROM takipler WHERE takip_eden = :b2 AND takip_edilen = :a2)'
    );
    $ifade->execute(['a1' => $a, 'b1' => $b, 'b2' => $b, 'a2' => $a]);
    $satir = $ifade->fetch(PDO::FETCH_NUM);

    return ['takip' => (bool) $satir[0], 'takipci' => (bool) $satir[1]];
}

function arkadas_mi(int $a, int $b): bool
{
    $d = takip_durumu($a, $b);

    return $a !== $b && $d['takip'] && $d['takipci'];
}

/* --- Uyeler ve profil ---------------------------------------------------- */

function nw_uye_bul(int $id): ?array
{
    $ifade = db()->prepare(
        "SELECT u.id, u.ad, u.hakkinda, u.foto, u.rol, u.tur, u.olusturuldu,
                (SELECT COUNT(*) FROM takipler t WHERE t.takip_edilen = u.id) AS takipci,
                (SELECT COUNT(*) FROM takipler t WHERE t.takip_eden = u.id) AS takip,
                (SELECT COUNT(*) FROM gonderiler g WHERE g.uye_id = u.id AND g.durum = 'yayinda') AS gonderi
           FROM uyeler u WHERE u.id = :id AND u.durum = 'aktif' AND u.tur <> 'yz'"
    );
    $ifade->execute(['id' => $id]);
    $satir = $ifade->fetch();

    return is_array($satir) ? $satir : null;
}

/**
 * Kisiler sayfasi: arama, en cok takip edilenler ya da yeni uyeler.
 *
 * @return list<array<string,mixed>>
 */
function nw_kisiler(string $ara, string $sira, int $limit = 60): array
{
    // YZ hesaplari sitede gorunmuyor, yalnizca begeni birakiyor (includes/topluluk.php).
    $kosul = "u.durum = 'aktif' AND u.tur <> 'yz'";
    $deger = [];

    if ($ara !== '') {
        $kosul .= ' AND u.ad LIKE :ara';
        $deger['ara'] = '%' . addcslashes($ara, '%_\\') . '%';
    }

    $ifade = db()->prepare(
        "SELECT u.id, u.ad, u.hakkinda, u.foto, u.tur, u.rol, u.olusturuldu,
                (SELECT COUNT(*) FROM takipler t WHERE t.takip_edilen = u.id) AS takipci
           FROM uyeler u WHERE $kosul
          ORDER BY " . ($sira === 'yeni' ? 'u.id DESC' : 'takipci DESC, u.id DESC') . '
          LIMIT ' . max(1, min(200, $limit))
    );
    $ifade->execute($deger);

    return $ifade->fetchAll();
}

/** @return list<array<string,mixed>> uyenin takipcileri ya da takip ettikleri */
function nw_takip_listesi(int $uyeId, bool $takipciler): array
{
    $ifade = db()->prepare(
        "SELECT u.id, u.ad, u.hakkinda, u.foto, u.tur, u.rol FROM takipler t
           JOIN uyeler u ON u.id = " . ($takipciler ? 't.takip_eden' : 't.takip_edilen') . "
          WHERE " . ($takipciler ? 't.takip_edilen' : 't.takip_eden') . " = :u AND u.durum = 'aktif' AND u.tur <> 'yz'
          ORDER BY t.olusturuldu DESC LIMIT 300"
    );
    $ifade->execute(['u' => $uyeId]);

    return $ifade->fetchAll();
}

/** Uyenin takip ettiklerinin kimlikleri (listede takip dugmesi icin). */
function nw_takip_edilenler(int $uyeId): array
{
    $ifade = db()->prepare('SELECT takip_edilen FROM takipler WHERE takip_eden = :u');
    $ifade->execute(['u' => $uyeId]);

    return array_map('intval', $ifade->fetchAll(PDO::FETCH_COLUMN));
}

function nw_profil_kaydet(int $uyeId, string $ad, string $hakkinda): void
{
    $simdiki = db()->prepare('SELECT ad, rol FROM uyeler WHERE id = :id');
    $simdiki->execute(['id' => $uyeId]);
    $simdiki = $simdiki->fetch() ?: ['ad' => '', 'rol' => 'uye'];

    // Rumuz yalnizca degistiyse sinaniyor: kurallardan (31.10.2026) once
    // alinmis bosluklu adlar degistirilmeden kaydedilebilsin.
    $ad = trim($ad);

    if ($ad !== (string) $simdiki['ad']) {
        $ad = uye_rumuz_sina($ad, $uyeId, $simdiki['rol'] === 'yonetici');
    }

    $hakkinda = trim(preg_replace('/\s+/u', ' ', $hakkinda) ?? '');

    if (mb_strlen($hakkinda, 'UTF-8') > HAKKINDA_EN_FAZLA) {
        throw new InvalidArgumentException('Tanıtım yazısı en fazla ' . HAKKINDA_EN_FAZLA . ' karakter olabilir.');
    }

    if ($hakkinda !== '' && yorum_uygunsuz_mu($hakkinda)) {
        throw new InvalidArgumentException('Tanıtım yazısı küfür ya da hakaret içeremez.');
    }

    db()->prepare('UPDATE uyeler SET ad = :ad, hakkinda = :h WHERE id = :id')
        ->execute(['ad' => $ad, 'h' => $hakkinda, 'id' => $uyeId]);
}

/** Profil resmini degistirir ($veri bos ise kaldirir). */
function nw_profil_foto(int $uyeId, string $veri): void
{
    $eski = db()->prepare('SELECT foto FROM uyeler WHERE id = :id');
    $eski->execute(['id' => $uyeId]);
    $eskiDosya = (string) $eski->fetchColumn();
    $yeni = $veri !== '' ? nw_foto_kaydet($veri, true)['dosya'] : '';

    db()->prepare('UPDATE uyeler SET foto = :f WHERE id = :id')->execute(['f' => $yeni, 'id' => $uyeId]);

    if ($eskiDosya !== '') {
        nw_foto_sil($eskiDosya);
    }
}

/**
 * Site yoneticisi panelden uyeyi duzenler (01.11.2026, site sahibi: "admin
 * panelinden vnetwork kullanicilarini duzenle yetkisi ver"; bkz.
 * admin/yorumlar.php?sekme=uyeler&duzenle=ID).
 *
 * Rumuz yalnizca degistiyse sinaniyor (ayrilmis rumuzlar panelde serbest).
 * Sifre bos birakilirsa degismiyor; yeni sifre ya da durdurma acik
 * oturumlari kapatiyor. Hatalar InvalidArgumentException (Turkce mesaj).
 *
 * @param array<string,mixed> $veri ad, eposta, hakkinda, sifre, durum, rol, dogrulandi, foto_kaldir
 */
function nw_panel_uye_guncelle(int $uyeId, array $veri): void
{
    $simdiki = db()->prepare('SELECT ad, eposta, foto, eposta_dogrulandi FROM uyeler WHERE id = :id');
    $simdiki->execute(['id' => $uyeId]);
    $simdiki = $simdiki->fetch();

    if (!$simdiki) {
        throw new InvalidArgumentException('Üye bulunamadı.');
    }

    $ad = trim((string) ($veri['ad'] ?? ''));

    if ($ad !== (string) $simdiki['ad']) {
        $ad = uye_rumuz_sina($ad, $uyeId, true);
    }

    $eposta = mb_strtolower(trim((string) ($veri['eposta'] ?? '')), 'UTF-8');

    if (!filter_var($eposta, FILTER_VALIDATE_EMAIL) || strlen($eposta) > 190) {
        throw new InvalidArgumentException('Geçerli bir e-posta adresi yazın.');
    }

    $hakkinda = trim(preg_replace('/\s+/u', ' ', (string) ($veri['hakkinda'] ?? '')) ?? '');

    if (mb_strlen($hakkinda, 'UTF-8') > HAKKINDA_EN_FAZLA) {
        throw new InvalidArgumentException('Tanıtım yazısı en fazla ' . HAKKINDA_EN_FAZLA . ' karakter olabilir.');
    }

    $sifre = (string) ($veri['sifre'] ?? '');

    if ($sifre !== '' && mb_strlen($sifre, 'UTF-8') < UYE_SIFRE_EN_AZ) {
        throw new InvalidArgumentException('Yeni şifre en az ' . UYE_SIFRE_EN_AZ . ' karakter olmalı.');
    }

    $durum = ($veri['durum'] ?? '') === 'engelli' ? 'engelli' : 'aktif';
    $rol   = ($veri['rol'] ?? '') === 'yonetici' ? 'yonetici' : 'uye';

    // E-posta degistiyse eski dogrulama yeni adres icin gecerli degil;
    // kutu isaretliyse yonetici yeni adresi de dogrulanmis sayiyor.
    $dogrulandi = !empty($veri['dogrulandi']);
    $dogrulamaSql = $dogrulandi
        ? ($eposta === (string) $simdiki['eposta'] ? 'COALESCE(eposta_dogrulandi, NOW())' : 'NOW()')
        : 'NULL';

    $alanlar = ['ad' => $ad, 'e' => $eposta, 'h' => $hakkinda, 'd' => $durum, 'r' => $rol, 'id' => $uyeId];
    $sifreSql = '';

    if ($sifre !== '') {
        $sifreSql = ', sifre = :s';
        $alanlar['s'] = password_hash($sifre, PASSWORD_DEFAULT);
    }

    try {
        db()->prepare("UPDATE uyeler SET ad = :ad, eposta = :e, hakkinda = :h, durum = :d, rol = :r,
                              eposta_dogrulandi = $dogrulamaSql$sifreSql WHERE id = :id")
            ->execute($alanlar);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23000') {
            throw new InvalidArgumentException('Bu e-posta adresi başka bir üyede.');
        }

        throw $e;
    }

    if ($sifre !== '' || $durum === 'engelli') {
        db()->prepare('DELETE FROM uye_oturumlari WHERE uye_id = :id')->execute(['id' => $uyeId]);
    }

    if (!empty($veri['foto_kaldir']) && (string) $simdiki['foto'] !== '') {
        nw_profil_foto($uyeId, '');
    }
}

/** Yonetici: uyeligi durdurur (giris yapamaz, icerigi gorunmez) ya da acar. */
function nw_uye_durum_degistir(int $uyeId, bool $durdur): void
{
    $hedef = db()->prepare('SELECT rol FROM uyeler WHERE id = :id');
    $hedef->execute(['id' => $uyeId]);

    if ($hedef->fetchColumn() === 'yonetici') {
        throw new InvalidArgumentException('Yönetici hesabı buradan durdurulamaz.');
    }

    db()->prepare('UPDATE uyeler SET durum = :d WHERE id = :id')
        ->execute(['d' => $durdur ? 'engelli' : 'aktif', 'id' => $uyeId]);

    if ($durdur) {
        db()->prepare('DELETE FROM uye_oturumlari WHERE uye_id = :id')->execute(['id' => $uyeId]);
    }
}

/**
 * Yonetici: yeni uye acar (site sahibi, 01.11.2026: "admin tum yetkilere
 * sahip olsun; uye ekle, uye cikar da dahil").
 *
 * Kayit formundaki denetimler (rumuz, e-posta, sifre uzunlugu) aynen;
 * kayit sayaci (saatte bes kayit) yoneticiye uygulanmiyor. E-posta
 * dogrulanmis sayiliyor: hesabi yonetici acti, uye paylasim yapabilsin.
 */
function nw_uye_ekle(string $ad, string $eposta, string $sifre): int
{
    $eposta = mb_strtolower(trim($eposta), 'UTF-8');
    $ad     = uye_rumuz_sina($ad);

    if (!filter_var($eposta, FILTER_VALIDATE_EMAIL) || strlen($eposta) > 190) {
        throw new InvalidArgumentException('Geçerli bir e-posta adresi yazın.');
    }

    if (mb_strlen($sifre, 'UTF-8') < UYE_SIFRE_EN_AZ) {
        throw new InvalidArgumentException('Şifre en az ' . UYE_SIFRE_EN_AZ . ' karakter olmalı.');
    }

    try {
        db()->prepare("INSERT INTO uyeler (ad, eposta, eposta_dogrulandi, sifre, kayit_ip) VALUES (:ad, :e, NOW(), :s, 'yonetici')")
            ->execute(['ad' => $ad, 'e' => $eposta, 's' => password_hash($sifre, PASSWORD_DEFAULT)]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23000') {
            throw new InvalidArgumentException('Bu e-posta adresiyle zaten bir üyelik var.');
        }

        throw $e;
    }

    return (int) db()->lastInsertId();
}

/**
 * Yonetici: uyeyi kalici olarak siler (01.11.2026, "uye cikar").
 *
 * Paylasimlari, yorumlari, begenileri, takipleri, mesajlari ve
 * bildirimleri veritabaninda kendiliginden gidiyor (uyeler'e bagli butun
 * tablolar ON DELETE CASCADE); diskteki profil ve paylasim fotograflari
 * burada siliniyor. Yonetici hesabi silinemez: once panelden ya da
 * profilden yoneticiligi alinmali. Geri alinamaz; gecici durdurma icin
 * nw_uye_durum_degistir.
 *
 * @return string silinen uyenin rumuzu
 */
function nw_uye_sil(int $uyeId): string
{
    $hedef = db()->prepare('SELECT ad, rol, foto FROM uyeler WHERE id = :id');
    $hedef->execute(['id' => $uyeId]);
    $uye = $hedef->fetch();

    if (!is_array($uye)) {
        throw new InvalidArgumentException('Üye bulunamadı.');
    }

    if ($uye['rol'] === 'yonetici') {
        throw new InvalidArgumentException('Yönetici hesabı silinemez; önce yöneticiliği alın.');
    }

    $fotolar = db()->prepare('SELECT dosya FROM gonderi_fotolari WHERE uye_id = :id');
    $fotolar->execute(['id' => $uyeId]);
    $dosyalar = $fotolar->fetchAll(PDO::FETCH_COLUMN);

    if ((string) $uye['foto'] !== '') {
        $dosyalar[] = (string) $uye['foto'];
    }

    db()->prepare('DELETE FROM uyeler WHERE id = :id')->execute(['id' => $uyeId]);

    foreach ($dosyalar as $dosya) {
        nw_foto_sil((string) $dosya);
    }

    return (string) $uye['ad'];
}

/* --- Mesajlar ------------------------------------------------------------ */

function mesaj_gonder(int $gonderen, int $alici, string $metin): int
{
    $karsi = nw_uye_bul($alici);

    if ($gonderen === $alici || $karsi === null) {
        throw new InvalidArgumentException('Üye bulunamadı.');
    }

    if (($karsi['tur'] ?? 'uye') !== 'uye') {
        throw new InvalidArgumentException('Yapay zekâ ve resmî hesaplara özel mesaj gönderilemez.');
    }

    // Yonetici herkese yazabiliyor; uyeler de yoneticiye (site yonetimine
    // ulasmak ve yoneticinin mesajina cevap vermek icin). Takiplesme sarti yok.
    $ben      = aktif_uye();
    $yonetici = ($karsi['rol'] ?? '') === 'yonetici'
        || ((int) ($ben['id'] ?? 0) === $gonderen && uye_yonetici_mi($ben));

    if (!$yonetici && !arkadas_mi($gonderen, $alici)) {
        throw new InvalidArgumentException('Yalnızca karşılıklı takipleştiğiniz üyelerle mesajlaşabilirsiniz.');
    }

    $metin = nw_metin_sina($metin, MESAJ_EN_FAZLA, 'Mesaj');
    $gecen = nw_son_kayittan_beri('mesajlar', 'gonderen_id', $gonderen);

    if ($gecen !== null && $gecen < MESAJ_ARALIK) {
        throw new InvalidArgumentException('Çok hızlı mesaj gönderiyorsunuz.');
    }

    db()->prepare('INSERT INTO mesajlar (gonderen_id, alici_id, metin) VALUES (:g, :a, :m)')
        ->execute(['g' => $gonderen, 'a' => $alici, 'm' => $metin]);

    return (int) db()->lastInsertId();
}

/**
 * Sohbet listesi: her karsi taraf icin son mesaj ve okunmamis sayisi.
 *
 * @return list<array<string,mixed>>
 */
function mesaj_sohbetleri(int $uyeId): array
{
    $ifade = db()->prepare(
        "SELECT k.karsi AS id, u.ad, u.foto, m.metin, m.olusturuldu, m.gonderen_id,
                (SELECT COUNT(*) FROM mesajlar o WHERE o.alici_id = :u1 AND o.gonderen_id = k.karsi AND o.okundu = 0) AS okunmamis
           FROM (
                SELECT IF(gonderen_id = :u2, alici_id, gonderen_id) AS karsi, MAX(id) AS son
                  FROM mesajlar WHERE gonderen_id = :u3 OR alici_id = :u4
                 GROUP BY karsi
           ) k
           JOIN mesajlar m ON m.id = k.son
           JOIN uyeler u ON u.id = k.karsi AND u.durum = 'aktif'
          ORDER BY m.id DESC LIMIT 100"
    );
    $ifade->execute(['u1' => $uyeId, 'u2' => $uyeId, 'u3' => $uyeId, 'u4' => $uyeId]);

    return $ifade->fetchAll();
}

/** Iki uye arasindaki son mesajlar (eskiden yeniye); okunmamislari okundu yapar. */
function mesaj_sohbet(int $uyeId, int $karsi, int $limit = 200): array
{
    db()->prepare('UPDATE mesajlar SET okundu = 1 WHERE alici_id = :u AND gonderen_id = :k AND okundu = 0')
        ->execute(['u' => $uyeId, 'k' => $karsi]);

    $ifade = db()->prepare(
        'SELECT * FROM (
            SELECT id, gonderen_id, metin, olusturuldu FROM mesajlar
             WHERE (gonderen_id = :a1 AND alici_id = :b1) OR (gonderen_id = :b2 AND alici_id = :a2)
             ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)) . '
         ) t ORDER BY id'
    );
    $ifade->execute(['a1' => $uyeId, 'b1' => $karsi, 'b2' => $karsi, 'a2' => $uyeId]);

    return $ifade->fetchAll();
}

function mesaj_okunmamis(int $uyeId): int
{
    try {
        $ifade = db()->prepare('SELECT COUNT(*) FROM mesajlar WHERE alici_id = :u AND okundu = 0');
        $ifade->execute(['u' => $uyeId]);

        return (int) $ifade->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/* --- Akis ---------------------------------------------------------------- */

/**
 * Akis: haberler (ValHaber) ve uye paylasimlari, en yeni ustte.
 *
 * Sayfalama zamana gore (?once=<unix>): iki kaynaktan da o andan eski
 * olanlar aliniyor, birlestirilip siralaniyor. $uyeId verilirse
 * "Takip ettiklerim": yalnizca o uyenin ve takip ettiklerinin
 * paylasimlari (haberler her iki akista da var).
 *
 * @return array{ogeler: list<array<string,mixed>>, sonraki: int}
 */
function nw_akis(int $adet, int $once, ?int $takipEdenUye = null, ?int $yalnizUye = null, string $konu = '', string $gazete = ''): array
{
    $once  = $once > 0 ? $once : time() + 60;
    $ogeler = [];

    // Gazete secildiyse (13.11.2026, bkz. nw_gazeteler) yalnizca o yayindan
    // ozetlenen haberler; uye paylasimlari ve yorum kartlari bu akista yok.
    $gazeteKaynaklari = $gazete !== '' ? (nw_gazeteler()[$gazete]['kaynaklar'] ?? []) : [];
    $gazeteKosulu     = $gazeteKaynaklari !== []
        ? ' AND h.kaynak_adi IN (' . implode(', ', array_map(static fn (string $k): string => db()->quote($k), $gazeteKaynaklari)) . ')'
        : ($gazete !== '' ? ' AND 1 = 0' : '');

    // Konu secildiyse (06.11.2026, bkz. nw_konular) yalnizca o konunun
    // haberleri ve o haberlere yazilan yorumlar; uye paylasimlarinin konusu
    // yok, konu akisinda gorunmuyorlar.
    $konu = isset(nw_konular()[$konu]) ? $konu : '';

    // Profil akisinda haber yok, yalnizca o uyenin paylasimlari.
    if ($yalnizUye === null) {
        $ifade = db()->prepare(
            "SELECT h.id, h.baslik, h.slug, h.ozet, h.gorsel_url, h.iframe_url, h.yayin_tarihi,
                    h.kaynak_adi, h.kaynak_url,
                    UNIX_TIMESTAMP(h.yayin_tarihi) AS zaman, k.ad AS kategori_adi, k.slug AS kategori_slug
               FROM haberler h LEFT JOIN kategoriler k ON k.id = h.kategori_id
              WHERE h.durum = :d AND " . ($konu !== '' ? 'k.slug = ' . db()->quote($konu) : "(k.slug IS NULL OR k.slug <> 'yemek-tarifleri')") . "
                AND h.yayin_tarihi < FROM_UNIXTIME(:once)" . $gazeteKosulu . "
              ORDER BY h.yayin_tarihi DESC, h.id DESC LIMIT :l"
        );
        $ifade->bindValue('d', HABER_YAYINDA);
        $ifade->bindValue('once', $once, PDO::PARAM_INT);
        $ifade->bindValue('l', $adet, PDO::PARAM_INT);
        $ifade->execute();

        foreach ($ifade->fetchAll() as $h) {
            $ogeler[] = ['tur' => 'haber', 'zaman' => (int) $h['zaman'], 'veri' => $h];
        }
    }

    $kosul = '';
    $deger = ['once' => $once];

    if ($yalnizUye !== null) {
        $kosul = ' AND g.uye_id = :yu';
        $deger['yu'] = $yalnizUye;
    } elseif ($takipEdenUye !== null) {
        $kosul = ' AND (g.uye_id = :tu1 OR g.uye_id IN (SELECT takip_edilen FROM takipler WHERE takip_eden = :tu2))';
        $deger['tu1'] = $takipEdenUye;
        $deger['tu2'] = $takipEdenUye;
    }

    try {
        if ($konu !== '' || $gazete !== '') {
            throw new RangeException('konu ve gazete akisinda uye paylasimi yok');
        }

        $ifade = db()->prepare(
            "SELECT g.id, g.uye_id, g.metin, g.video_url, g.olusturuldu, UNIX_TIMESTAMP(g.olusturuldu) AS zaman, u.ad, u.foto, u.tur, u.rol
               FROM gonderiler g JOIN uyeler u ON u.id = g.uye_id
              WHERE g.durum = 'yayinda' AND u.durum = 'aktif' AND g.olusturuldu < FROM_UNIXTIME(:once) $kosul
              ORDER BY g.olusturuldu DESC, g.id DESC LIMIT " . (int) $adet
        );
        $ifade->execute($deger);

        foreach ($ifade->fetchAll() as $g) {
            $ogeler[] = ['tur' => 'gonderi', 'zaman' => (int) $g['zaman'], 'veri' => $g];
        }
    } catch (PDOException | RangeException $e) {
        // gonderiler tablosu henuz yoksa (ya da konu secildiyse) yalnizca haberler.
    }

    // Haberlerin altina yazilan yorumlar da akista ayri kart (site sahibinin
    // istegi, 01.11.2026: "haberlerin altina yazilan yorumlar network'e
    // gitsin, oradan gorelim"). Takip sekmesinde takip edilenlerin, profilde
    // o uyenin yorumlari.
    $kosul = str_replace('g.uye_id', 'y.uye_id', $kosul);

    try {
        if ($gazete !== '') {
            throw new PDOException('gazete akisinda yorum karti yok');
        }

        $ifade = db()->prepare(
            "SELECT y.id, y.uye_id, y.metin, y.olusturuldu, UNIX_TIMESTAMP(y.olusturuldu) AS zaman,
                    u.ad, u.foto, u.tur, u.rol,
                    h.id AS haber_id, h.baslik, h.gorsel_url, k.ad AS kategori_adi
               FROM yorumlar y
               JOIN uyeler u ON u.id = y.uye_id
               JOIN haberler h ON h.id = y.haber_id
               LEFT JOIN kategoriler k ON k.id = h.kategori_id
              WHERE y.haber_id IS NOT NULL AND y.durum = 'yayinda' AND u.durum = 'aktif' AND u.tur <> 'yz'
                AND h.durum = :d AND y.olusturuldu < FROM_UNIXTIME(:once) $kosul"
              . ($konu !== '' ? ' AND k.slug = ' . db()->quote($konu) : '') . "
              ORDER BY y.olusturuldu DESC, y.id DESC LIMIT " . (int) $adet
        );
        $ifade->execute($deger + ['d' => HABER_YAYINDA]);

        foreach ($ifade->fetchAll() as $y) {
            $ogeler[] = ['tur' => 'yorum', 'zaman' => (int) $y['zaman'], 'veri' => $y];
        }
    } catch (PDOException $e) {
        // yorumlar tablosu henuz yoksa yorum karti yok.
    }

    usort($ogeler, static fn (array $a, array $b): int => $b['zaman'] <=> $a['zaman']);
    $ogeler = array_slice($ogeler, 0, $adet);
    $son    = $ogeler !== [] ? end($ogeler)['zaman'] : 0;

    return ['ogeler' => $ogeler, 'sonraki' => count($ogeler) === $adet ? $son : 0];
}

/**
 * Akisin ustundeki konu secici (06.11.2026, site sahibi: "V Network'e
 * girdigimizde okuyucu hangi haberleri okuyacagini secebilsin; ustte
 * basliktan haber turunu secsin, ona gore haber gostersin"). Menudeki
 * haber basliklari, menu sirasiyla; haberi olmayan baslik gosterilmiyor.
 *
 * @return array<string,string> slug => ad
 */
function nw_konular(): array
{
    static $konular = null;

    if ($konular === null) {
        $konular = [];

        try {
            foreach (haber_akis_kategorileri() as $k) {
                if ($k['adet'] > 0) {
                    $konular[$k['slug']] = $k['ad'];
                }
            }
        } catch (PDOException $e) {
            $konular = [];
        }
    }

    return $konular;
}

/* --- Gazeteler (13.11.2026) ---------------------------------------------
 *
 * Site sahibinin istegi: "Network sayfasinda haberlerin hangi gazeteden
 * alindigini gazete gazete gosterelim; solda gazete adlari, tikladigimizda o
 * gazetenin ozet haberleri gelsin. Ayrica ana basliklar: ekonomi dedigimizde
 * ekonomi haberleri, gundem dedigimizde gundem asagi dogru gelsin."
 *
 * "Gazete" haberin KAYNAGIDIR (haberler.kaynak_adi): Valentra o yayinin
 * haberini kendi cumleleriyle ozetledi, kaynak baglantisi haberde. Kaynak
 * listesindeki bolum beslemeleri ("Hurriyet Gundem", "Hurriyet Dunya", "TRT
 * Haber Son Dakika") tek yayinda toplanir.
 * ---------------------------------------------------------------------- */

/** Bir gazete listede gorunmek icin son gunlerde en az bu kadar haber. */
const NW_GAZETE_EN_AZ_HABER = 2;

/** Listede gosterilen en fazla gazete. */
const NW_GAZETE_EN_FAZLA = 40;

/** Kaynak adindan yayin adi: "Hürriyet Gündem" -> "Hürriyet". */
function nw_gazete_adi(string $kaynakAdi): string
{
    $ad = trim((string) preg_replace('/\s*\((video|YouTube)\)\s*$/iu', '', trim($kaynakAdi)));
    $ad = trim((string) preg_replace('/\s+(Son Dakika|Manşet|Yerel|Genel)$/u', '', $ad));

    return kaynak_gorunen_ad($ad);
}

/** Yayin adinin adresteki hali: "Habertürk" -> "haberturk". */
function nw_gazete_slug(string $ad): string
{
    $s = strtr(mb_strtolower(strtr($ad, ['İ' => 'i', 'I' => 'ı']), 'UTF-8'), [
        'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u',
    ]);

    return trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');
}

/**
 * Son gunlerde haberi ozetlenen yayinlar, haber sayisina gore.
 *
 * @return array<string,array{ad:string,adet:int,kaynaklar:list<string>}> slug => yayin
 */
function nw_gazeteler(int $gun = 3): array
{
    static $onbellek = [];

    if (isset($onbellek[$gun])) {
        return $onbellek[$gun];
    }

    $liste = [];

    try {
        $satirlar = db()->query(
            "SELECT h.kaynak_adi, COUNT(*) AS adet
               FROM haberler h LEFT JOIN kategoriler k ON k.id = h.kategori_id
              WHERE h.durum = " . db()->quote(HABER_YAYINDA) . " AND h.kaynak_adi <> ''
                AND (k.slug IS NULL OR k.slug <> 'yemek-tarifleri')
                AND h.yayin_tarihi >= NOW() - INTERVAL " . max(1, min(14, $gun)) . " DAY
              GROUP BY h.kaynak_adi"
        )->fetchAll();
    } catch (PDOException $e) {
        return $onbellek[$gun] = [];
    }

    foreach ($satirlar as $s) {
        $ad   = nw_gazete_adi((string) $s['kaynak_adi']);
        $slug = nw_gazete_slug($ad);

        if ($slug === '') {
            continue;
        }

        $liste[$slug] ??= ['ad' => $ad, 'adet' => 0, 'kaynaklar' => []];
        $liste[$slug]['adet'] += (int) $s['adet'];
        $liste[$slug]['kaynaklar'][] = (string) $s['kaynak_adi'];
    }

    $liste = array_filter($liste, static fn (array $g): bool => $g['adet'] >= NW_GAZETE_EN_AZ_HABER);
    uasort($liste, static fn (array $a, array $b): int => [$b['adet'], $a['ad']] <=> [$a['adet'], $b['ad']]);

    return $onbellek[$gun] = array_slice($liste, 0, NW_GAZETE_EN_FAZLA, true);
}

/**
 * "Gazete gazete" gorunumu: her yayinin son haberleri.
 *
 * @return list<array{slug:string,ad:string,adet:int,haberler:list<array<string,mixed>>}>
 */
function nw_gazete_ozetleri(int $gazeteAdet = 14, int $haberAdet = 4): array
{
    $gazeteler = nw_gazeteler();

    if ($gazeteler === []) {
        return [];
    }

    $sahip = [];

    foreach ($gazeteler as $slug => $g) {
        foreach ($g['kaynaklar'] as $k) {
            $sahip[$k] = $slug;
        }
    }

    try {
        $satirlar = db()->query(
            "SELECT h.id, h.baslik, h.slug, h.ozet, h.gorsel_url, h.gorsel_denetim, h.kaynak_adi, h.yayin_tarihi,
                    k.ad AS kategori_adi, k.slug AS kategori_slug
               FROM haberler h LEFT JOIN kategoriler k ON k.id = h.kategori_id
              WHERE h.durum = " . db()->quote(HABER_YAYINDA) . " AND h.kaynak_adi <> ''
                AND (k.slug IS NULL OR k.slug <> 'yemek-tarifleri')
                AND h.yayin_tarihi >= NOW() - INTERVAL 3 DAY
              ORDER BY h.yayin_tarihi DESC, h.id DESC LIMIT 900"
        )->fetchAll();
    } catch (PDOException $e) {
        return [];
    }

    $haberler = [];

    foreach ($satirlar as $h) {
        $slug = $sahip[(string) $h['kaynak_adi']] ?? '';

        if ($slug !== '' && count($haberler[$slug] ?? []) < $haberAdet) {
            $haberler[$slug][] = $h;
        }
    }

    $liste = [];

    foreach (array_slice($gazeteler, 0, $gazeteAdet, true) as $slug => $g) {
        if (($haberler[$slug] ?? []) !== []) {
            $liste[] = ['slug' => $slug, 'ad' => $g['ad'], 'adet' => $g['adet'], 'haberler' => $haberler[$slug]];
        }
    }

    return $liste;
}

/* --- Sag kenar: gundem -------------------------------------------------- */

/*
 * Site sahibinin istegi (31.10.2026): "sagda en populer konular, en cok
 * etkilesim alan haberler gibi basliklar olsun". Ikisi de gercek veriden:
 * son iki gunun haber etiketleri ve haberlerin okuyucu, begeni ve yorum
 * sayilari. Veri yoksa kutu cizilmiyor.
 */

/**
 * Son iki gunun en cok haberde gecen etiketleri.
 *
 * @return list<array{etiket:string,adet:int}>
 */
function nw_populer_konular(int $limit = 10): array
{
    try {
        $ifade = db()->prepare(
            'SELECT etiketler FROM haberler WHERE durum = :d AND yayin_tarihi >= NOW() - INTERVAL 2 DAY'
        );
        $ifade->execute(['d' => HABER_YAYINDA]);
    } catch (PDOException $e) {
        return [];
    }

    $sayim = [];

    foreach ($ifade->fetchAll(PDO::FETCH_COLUMN) as $ham) {
        foreach (etiketleri_coz((string) $ham) as $etiket) {
            $anahtar = mb_strtolower($etiket, 'UTF-8');
            $sayim[$anahtar] ??= ['etiket' => $etiket, 'adet' => 0];
            $sayim[$anahtar]['adet']++;
        }
    }

    // Tek haberde gecen etiket "populer" degil.
    $sayim = array_filter($sayim, static fn (array $s): bool => $s['adet'] >= 2);
    usort($sayim, static fn (array $a, array $b): int => $b['adet'] <=> $a['adet']);

    return array_slice($sayim, 0, $limit);
}

/**
 * Son iki gunun en cok etkilesim alan haberleri.
 *
 * Puan: son 24 saatin farkli okuyucusu + 5 x begeni + 10 x yorum (yorum
 * en zahmetli etkilesim). Okuyucu sayisi ana sayfanin kullandigi
 * onbellekten (haber_okunma_birlesimi), her istekte ziyaretler sayilmiyor.
 *
 * @return list<array<string,mixed>>
 */
function nw_etkilesim_haberleri(int $limit = 6): array
{
    // Siralamada "okuyucu" takma adi yazilmiyor: ORDER BY'da zo.okuyucu
    // sutununa cozuluyor (okunmamis haberde NULL) ve sira bozuluyordu.
    try {
        $ifade = db()->prepare(
            "SELECT h.id, h.baslik, h.slug,
                    COALESCE(zo.okuyucu, 0) AS okuyucu,
                    (SELECT COUNT(*) FROM begeniler b WHERE b.hedef_tur = 'haber' AND b.hedef_id = h.id) AS begeni,
                    (SELECT COUNT(*) FROM yorumlar y WHERE y.haber_id = h.id AND y.durum = 'yayinda') AS yorum
               FROM haberler h " . haber_okunma_birlesimi() . "
              WHERE h.durum = :d AND h.yayin_tarihi >= NOW() - INTERVAL 2 DAY
              ORDER BY (COALESCE(zo.okuyucu, 0) + 5 * begeni + 10 * yorum) DESC, h.yayin_tarihi DESC
              LIMIT " . max(1, min(20, $limit))
        );
        $ifade->execute(['d' => HABER_YAYINDA]);
        $liste = $ifade->fetchAll();

        // Gorunen okuyucu sayisina YZ begenilerinin payi (yz_okunma_ekleri).
        $ek = yz_okunma_ekleri(array_map(static fn (array $h): int => (int) $h['id'], $liste));

        foreach ($liste as &$h) {
            $h['okuyucu'] = (int) $h['okuyucu'] + ($ek[(int) $h['id']] ?? 0);
        }
        unset($h);

        return array_values(array_filter(
            $liste,
            static fn (array $h): bool => (int) $h['okuyucu'] + (int) $h['begeni'] + (int) $h['yorum'] > 0
        ));
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Son iki gunun en cok begenilen haberleri (site sahibinin istegi,
 * 01.11.2026: "network'te bir baslik da en cok begenilen haberler olsun").
 *
 * @return list<array<string,mixed>>
 */
function nw_begenilen_haberler(int $limit = 6): array
{
    try {
        $ifade = db()->prepare(
            "SELECT h.id, h.baslik, COUNT(*) AS begeni,
                    (SELECT COUNT(*) FROM yorumlar y WHERE y.haber_id = h.id AND y.durum = 'yayinda') AS yorum
               FROM haberler h
               JOIN begeniler b ON b.hedef_tur = 'haber' AND b.hedef_id = h.id
                    AND b.tepki IN ('" . implode("','", NW_OLUMLU_TEPKILER) . "')
              WHERE h.durum = :d AND h.yayin_tarihi >= NOW() - INTERVAL 2 DAY AND h.yayin_tarihi <= NOW()
              GROUP BY h.id, h.baslik, h.yayin_tarihi
              ORDER BY begeni DESC, h.yayin_tarihi DESC
              LIMIT " . max(1, min(20, $limit))
        );
        $ifade->execute(['d' => HABER_YAYINDA]);

        return $ifade->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/* --- Begeni ve okunma ---------------------------------------------------- */

/*
 * TEPKILER (site sahibi, 02.11.2026: "begeni yaninda uzuntu ve alkis da
 * olsun; baska varsa onlari da ekle"). Turk haber sitelerindeki yaygin
 * set. Uye bir icerige tek tepki veriyor (begeniler tablosu, tepki
 * sutunu); ayni tepkiye yeniden basmak geri aliyor, baskasina basmak
 * degistiriyor. Toplam "begeni" sayisi butun tepkileri sayiyor; "En cok
 * begenilen haberler" yalnizca olumlu tepkileri (NW_OLUMLU_TEPKILER).
 *
 * anahtar => [simge, dugme adi, verdikten sonraki ad]
 */
const NW_TEPKILER = [
    'begen'  => ['👍', 'Beğen', 'Beğendin'],
    'alkis'  => ['👏', 'Alkış', 'Alkışladın'],
    'sevgi'  => ['❤️', 'Sevgi', 'Sevdin'],
    'saskin' => ['😮', 'Şaşkın', 'Şaşırdın'],
    'uzgun'  => ['😢', 'Üzgün', 'Üzüldün'],
    'kizgin' => ['😠', 'Kızgın', 'Kızdın'],
];
const NW_OLUMLU_TEPKILER = ['begen', 'alkis', 'sevgi'];

/**
 * Tepkiyi verir, değiştirir ya da geri alır; uyenin yeni tepkisini
 * dondurur ('' = tepkisi yok).
 */
function begeni_degistir(string $tur, int $hedefId, int $uyeId, string $tepki = 'begen'): string
{
    if (!in_array($tur, ['haber', 'gonderi'], true) || !isset(NW_TEPKILER[$tepki])) {
        throw new InvalidArgumentException('Geçersiz işlem.');
    }

    $simdiki = db()->prepare('SELECT tepki FROM begeniler WHERE hedef_tur = :t AND hedef_id = :h AND uye_id = :u');
    $simdiki->execute(['t' => $tur, 'h' => $hedefId, 'u' => $uyeId]);
    $eski = $simdiki->fetchColumn();

    // Baska bir tepki vardi: degistir (bildirim zaten gitmisti).
    if ($eski !== false && $eski !== $tepki) {
        db()->prepare('UPDATE begeniler SET tepki = :k WHERE hedef_tur = :t AND hedef_id = :h AND uye_id = :u')
            ->execute(['k' => $tepki, 't' => $tur, 'h' => $hedefId, 'u' => $uyeId]);

        return $tepki;
    }

    $sil = db()->prepare('DELETE FROM begeniler WHERE hedef_tur = :t AND hedef_id = :h AND uye_id = :u');
    $sil->execute(['t' => $tur, 'h' => $hedefId, 'u' => $uyeId]);

    if ($sil->rowCount() > 0) {
        if ($tur === 'gonderi') {
            bildirim_sil(bildirim_gonderi_sahibi($hedefId), $uyeId, 'begeni', $hedefId);
        }

        return '';
    }

    $var = $tur === 'haber'
        ? db()->prepare('SELECT 1 FROM haberler WHERE id = :id AND durum = ' . db()->quote(HABER_YAYINDA))
        : db()->prepare("SELECT 1 FROM gonderiler WHERE id = :id AND durum = 'yayinda'");
    $var->execute(['id' => $hedefId]);

    if ($var->fetchColumn() === false) {
        throw new InvalidArgumentException('İçerik bulunamadı.');
    }

    db()->prepare('INSERT IGNORE INTO begeniler (hedef_tur, hedef_id, uye_id, tepki) VALUES (:t, :h, :u, :k)')
        ->execute(['t' => $tur, 'h' => $hedefId, 'u' => $uyeId, 'k' => $tepki]);

    // Paylasimin sahibine bildirim (haberin sahibi yok).
    if ($tur === 'gonderi') {
        bildirim_ekle(bildirim_gonderi_sahibi($hedefId), $uyeId, 'begeni', $hedefId);
    }

    return $tepki;
}

/**
 * Tepki sayilari ve uyenin tepkileri.
 *
 * sayi: toplam (butun tepkiler); tepki: hedef => [tepki => adet];
 * benim: hedef => uyenin tepkisi (bos degil = tepki vermis).
 *
 * @param list<int> $idler
 * @return array{sayi: array<int,int>, tepki: array<int,array<string,int>>, benim: array<int,string>}
 */
function begeni_bilgisi(string $tur, array $idler, ?int $uyeId): array
{
    $idler = array_values(array_unique(array_filter(array_map('intval', $idler))));
    $sonuc = ['sayi' => [], 'tepki' => [], 'benim' => []];

    if ($idler === []) {
        return $sonuc;
    }

    $liste = implode(',', $idler);

    try {
        foreach (db()->query('SELECT hedef_id, tepki, COUNT(*) AS n FROM begeniler WHERE hedef_tur = ' . db()->quote($tur)
                 . " AND hedef_id IN ($liste) GROUP BY hedef_id, tepki") as $s) {
            $hid = (int) $s['hedef_id'];
            $sonuc['sayi'][$hid] = ($sonuc['sayi'][$hid] ?? 0) + (int) $s['n'];
            $sonuc['tepki'][$hid][(string) $s['tepki']] = (int) $s['n'];
        }

        if ($uyeId !== null) {
            foreach (db()->query('SELECT hedef_id, tepki FROM begeniler WHERE hedef_tur = ' . db()->quote($tur)
                     . " AND uye_id = " . (int) $uyeId . " AND hedef_id IN ($liste)") as $s) {
                $sonuc['benim'][(int) $s['hedef_id']] = (string) $s['tepki'];
            }
        }
    } catch (PDOException $e) {
        // begeniler tablosu (ya da tepki sutunu) henuz yoksa sifir; sema
        // yukseltmesi bir sonraki istekte calisiyor.
    }

    return $sonuc;
}

/**
 * YZ begenilerinin gorunen okunma sayisina payi (site sahibinin istegi,
 * 01.11.2026: "begeni oldugunda okunma sayilari da artsin"). Begenen
 * okumustur; okuyan da begenenden cok olur: her YZ begenisi 3-6 okuma
 * (carpan haber id'sinden, sabit). Yalnizca V Network'te GOSTERILEN
 * sayiya ekleniyor; ziyaretler tablosuna ve yonetim istatistiklerine
 * dokunulmuyor (onlar gercek okuyucuyu gosteriyor).
 *
 * @param list<int> $idler haber id'leri
 * @return array<int,int>
 */
function yz_okunma_ekleri(array $idler): array
{
    $idler = array_values(array_unique(array_filter(array_map('intval', $idler))));
    $sonuc = [];

    if ($idler === []) {
        return $sonuc;
    }

    try {
        foreach (db()->query(
            "SELECT b.hedef_id, COUNT(*) AS n FROM begeniler b JOIN uyeler u ON u.id = b.uye_id
              WHERE b.hedef_tur = 'haber' AND u.tur = 'yz' AND b.hedef_id IN (" . implode(',', $idler) . ")
              GROUP BY b.hedef_id"
        ) as $s) {
            $id = (int) $s['hedef_id'];
            $sonuc[$id] = (int) $s['n'] * (3 + crc32('okunma' . $id) % 4);
        }
    } catch (PDOException $e) {
        return [];
    }

    return $sonuc;
}

/**
 * Haberlerin okunma sayisi: haberi acan farkli okuyucu (botlar ve hic
 * gosterilmeyen onyuklemeler haric; ziyaret sayaciyla ayni olcut, bkz.
 * includes/ziyaret.php). Sitede ve V Network'te okunanlar birlikte; V
 * Network'te gorunen sayiya YZ begenilerinin payi da ekleniyor.
 *
 * @param list<int> $idler
 * @return array<int,int>
 */
function okunma_sayilari(array $idler): array
{
    require_once __DIR__ . '/ziyaret.php';

    $idler = array_values(array_unique(array_filter(array_map('intval', $idler))));

    if ($idler === []) {
        return [];
    }

    $yeni   = ziyaret_yeni_sema();
    $kimlik = $yeni ? ziyaret_kimlik_ifadesi() : 'ziyaretci';
    $insan  = $yeni ? ziyaret_insan_kosulu() : '1=1';
    $sonuc  = [];

    try {
        foreach (db()->query('SELECT haber_id, COUNT(DISTINCT ' . $kimlik . ') AS n FROM ziyaretler
                               WHERE haber_id IN (' . implode(',', $idler) . ') AND ' . $insan . ' GROUP BY haber_id') as $s) {
            $sonuc[(int) $s['haber_id']] = (int) $s['n'];
        }
    } catch (PDOException $e) {
        $sonuc = [];
    }

    foreach (yz_okunma_ekleri($idler) as $id => $ek) {
        $sonuc[$id] = ($sonuc[$id] ?? 0) + $ek;
    }

    return $sonuc;
}
