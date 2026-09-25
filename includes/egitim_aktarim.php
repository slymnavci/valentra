<?php
declare(strict_types=1);

/**
 * Eğitim platformunun suleymanavci.com.tr'den Valentra'ya aktarımı.
 *
 * Eski sitedeki gecici, salt okunur uc (api/disa_aktar.php) yonetici
 * oturumu ve uygulama anahtariyla cagriliyor. Aktarim PARCA PARCA:
 * panel sayfasindaki betik her adimda tek bir tablo sayfasi ya da tek
 * bir dosya istiyor. Paylasimli hostingde tek istekte her seyi cekmek
 * zaman asimina ugrardi; parcali aktarim kesilirse kaldigi yerden
 * yeniden baslatilabilir (tablolarda REPLACE, materyallerde boyut karsilastirmasi).
 *
 * Finansal modul (fin_* tablolari, private-data/) tasinmiyor; eski
 * uc onlari hic vermiyor.
 */

require_once __DIR__ . '/http_ortak.php';
require_once __DIR__ . '/ayarlar.php';

const EGITIM_KOK = __DIR__ . '/../egitim';

/**
 * @param array<string,string>|null $json POST govdesi (JSON)
 * @return array{tamam:bool,kod:int,govde:string,veri:array<mixed>,hata:string}
 */
function egitim_istek(string $url, ?array $json = null, array $basliklar = [], int $zamanAsimi = 60): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, http_ortak_secenekler($zamanAsimi));

    $b = array_merge(['Accept: application/json'], $basliklar);

    if ($json !== null) {
        $b[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $b);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    // Buyuk PDF'ler olabilir; gzip acma devre disi degil ama boyut siniri yok.

    $govde = curl_exec($ch);
    $kod   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hata  = curl_error($ch);
    $no    = curl_errno($ch);
    curl_close($ch);

    if (!is_string($govde)) {
        return ['tamam' => false, 'kod' => 0, 'govde' => '', 'veri' => [], 'hata' => http_hata_acikla($no, $hata)];
    }

    $veri = json_decode($govde, true);
    $veri = is_array($veri) ? $veri : [];

    if ($kod < 200 || $kod >= 300) {
        return ['tamam' => false, 'kod' => $kod, 'govde' => $govde, 'veri' => $veri,
                'hata' => 'Eski site HTTP ' . $kod . (isset($veri['hata']) ? ': ' . $veri['hata'] : '')];
    }

    return ['tamam' => true, 'kod' => $kod, 'govde' => $govde, 'veri' => $veri, 'hata' => ''];
}

/**
 * Eski sitede yonetici olarak oturum acar ve ozeti alir.
 *
 * @return array{tamam:bool,hata:string,token:string,ozet:array<string,mixed>}
 */
function egitim_baglan(string $adres, string $kullanici, string $sifre, string $anahtar): array
{
    $adres = rtrim($adres, '/');
    $giris = egitim_istek($adres . '/api/giris_dogrula.php', ['kullanici_adi' => $kullanici, 'sifre' => $sifre],
                          ['X-App-Key: ' . $anahtar]);

    $token = (string) ($giris['veri']['kullanici']['token'] ?? '');

    if (!$giris['tamam'] || $token === '') {
        return ['tamam' => false, 'hata' => 'Eski sitede oturum açılamadı: ' . ($giris['hata'] ?: 'yanıt beklenmedik.'), 'token' => '', 'ozet' => []];
    }

    if (($giris['veri']['kullanici']['rol'] ?? '') !== 'yonetici') {
        return ['tamam' => false, 'hata' => 'Bu hesap yönetici değil; aktarım yönetici hesabıyla yapılmalı.', 'token' => '', 'ozet' => []];
    }

    // Buyuk materyal klasorlerinde liste birkac dakika surebilir.
    $ozet = egitim_istek($adres . '/api/disa_aktar.php?islem=ozet', null, egitim_basliklar($anahtar, $token), 240);

    if (!$ozet['tamam']) {
        return ['tamam' => false, 'token' => '', 'ozet' => [],
                'hata' => $ozet['kod'] === 404
                    ? 'Eski sitede dışa aktarma ucu yok (api/disa_aktar.php). Önce o sitenin güncellemesinin yayına çıkması gerekiyor.'
                    : 'Özet alınamadı: ' . $ozet['hata']];
    }

    return ['tamam' => true, 'hata' => '', 'token' => $token, 'ozet' => $ozet['veri']];
}

/** @return list<string> */
function egitim_basliklar(string $anahtar, string $token): array
{
    return ['X-App-Key: ' . $anahtar, 'X-Session-Token: ' . $token];
}

/**
 * Bir tablonun bir sayfasini aktarir. Ilk sayfada tablo yoksa eski
 * sitenin kendi tanimiyla olusturulur.
 *
 * @return array{tamam:bool,hata:string,satir:int}
 */
function egitim_tablo_aktar(string $adres, string $anahtar, string $token, string $tablo, int $sayfa): array
{
    if (preg_match('/^[a-z_]+$/', $tablo) !== 1) {
        return ['tamam' => false, 'hata' => 'Geçersiz tablo adı.', 'satir' => 0];
    }

    $adres = rtrim($adres, '/');
    $b     = egitim_basliklar($anahtar, $token);

    if ($sayfa === 0) {
        $sema = egitim_istek($adres . '/api/disa_aktar.php?islem=sema&ad=' . $tablo, null, $b);

        if (!$sema['tamam']) {
            return ['tamam' => false, 'hata' => $tablo . ' tanımı alınamadı: ' . $sema['hata'], 'satir' => 0];
        }

        $sql = (string) ($sema['veri']['sema'] ?? '');

        if ($sql === '') {
            return ['tamam' => true, 'hata' => '', 'satir' => 0];   // eski sitede tablo yok
        }

        // Yalnizca bu tabloyu olusturan bir ifade kabul ediliyor.
        if (preg_match('/^CREATE TABLE `' . $tablo . '` \(/', $sql) !== 1) {
            return ['tamam' => false, 'hata' => $tablo . ' tanımı beklenmedik biçimde.', 'satir' => 0];
        }

        db()->exec(preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $sql, 1));
    }

    $yanit = egitim_istek($adres . '/api/disa_aktar.php?islem=tablo&ad=' . $tablo . '&sayfa=' . $sayfa, null, $b, 90);

    if (!$yanit['tamam']) {
        return ['tamam' => false, 'hata' => $tablo . ' okunamadı: ' . $yanit['hata'], 'satir' => 0];
    }

    $satirlar = (array) ($yanit['veri']['satirlar'] ?? []);

    if ($satirlar === []) {
        return ['tamam' => true, 'hata' => '', 'satir' => 0];
    }

    // Sutun adlari hedef tablodakilerle kesisiyor: eski sitede olup
    // burada olmayan (ya da tersi) bir sutun aktarimi dusurmesin.
    $hedef = db()->query('SHOW COLUMNS FROM `' . $tablo . '`')->fetchAll(PDO::FETCH_COLUMN);
    $sutunlar = array_values(array_intersect(array_keys((array) $satirlar[0]), $hedef));

    if ($sutunlar === []) {
        return ['tamam' => false, 'hata' => $tablo . ': ortak sütun yok.', 'satir' => 0];
    }

    $sql = 'REPLACE INTO `' . $tablo . '` (`' . implode('`,`', $sutunlar) . '`) VALUES ('
         . implode(',', array_fill(0, count($sutunlar), '?')) . ')';
    $ifade = db()->prepare($sql);

    db()->beginTransaction();

    try {
        foreach ($satirlar as $satir) {
            $ifade->execute(array_map(static fn (string $s) => $satir[$s] ?? null, $sutunlar));
        }

        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();

        return ['tamam' => false, 'hata' => $tablo . ' yazılamadı: ' . $e->getMessage(), 'satir' => 0];
    }

    return ['tamam' => true, 'hata' => '', 'satir' => count($satirlar)];
}

/**
 * Tek bir dosyayi aktarir; ayni boyutta zaten varsa atlar.
 *
 * Dosya DOGRUDAN DISKE indiriliyor (gecici dosya, sonra yerine
 * tasiniyor): materyaller 80 MB'a kadar PDF olabiliyor, bellege almak
 * PHP bellek sinirini asardi. Eksik inen dosya boyutundan anlasiliyor
 * ve yerine konmuyor.
 *
 * @return array{tamam:bool,hata:string,atlandi:bool}
 */
function egitim_dosya_aktar(string $adres, string $anahtar, string $token, string $yol, int $boyut): array
{
    if (preg_match('#^(content|materyaller)/[^\0]+$#', $yol) !== 1 || str_contains($yol, '..')) {
        return ['tamam' => false, 'hata' => 'Geçersiz dosya yolu: ' . $yol, 'atlandi' => false];
    }

    $hedef = EGITIM_KOK . '/' . $yol;

    // content/*.json her seferinde yeniden aliniyor (kucukler ve ayni
    // boyutta degismis olabilirler); materyaller boyut tutuyorsa atlaniyor.
    if (str_starts_with($yol, 'materyaller/') && is_file($hedef) && filesize($hedef) === $boyut) {
        return ['tamam' => true, 'hata' => '', 'atlandi' => true];
    }

    $klasor = dirname($hedef);

    if (!is_dir($klasor) && !@mkdir($klasor, 0755, true) && !is_dir($klasor)) {
        return ['tamam' => false, 'hata' => $yol . ': klasör oluşturulamadı (' . $klasor . ').', 'atlandi' => false];
    }

    $gecici = $hedef . '.indiriliyor';
    $fh = @fopen($gecici, 'wb');

    if ($fh === false) {
        return ['tamam' => false, 'hata' => $yol . ': yazılamadı; egitim/ klasörünün yazma iznini kontrol edin.', 'atlandi' => false];
    }

    $ch = curl_init(rtrim($adres, '/') . '/api/disa_aktar.php?islem=dosya&yol=' . rawurlencode($yol));
    curl_setopt_array($ch, http_ortak_secenekler(600));
    curl_setopt($ch, CURLOPT_HTTPHEADER, egitim_basliklar($anahtar, $token));
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_FILE, $fh);
    curl_setopt($ch, CURLOPT_ENCODING, '');

    $sonuc = curl_exec($ch);
    $kod   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hata  = curl_error($ch);
    $no    = curl_errno($ch);
    curl_close($ch);
    fclose($fh);

    clearstatcache(true, $gecici);
    $inen = is_file($gecici) ? (int) filesize($gecici) : 0;

    if ($sonuc === false || $kod !== 200 || $inen !== $boyut) {
        @unlink($gecici);
        $neden = $sonuc === false ? http_hata_acikla($no, $hata)
               : ($kod !== 200 ? 'eski site HTTP ' . $kod : 'eksik indi (' . $inen . ' / ' . $boyut . ' bayt)');

        return ['tamam' => false, 'hata' => $yol . ': ' . $neden, 'atlandi' => false];
    }

    if (!@rename($gecici, $hedef)) {
        @unlink($gecici);

        return ['tamam' => false, 'hata' => $yol . ': yerine konamadı.', 'atlandi' => false];
    }

    return ['tamam' => true, 'hata' => '', 'atlandi' => false];
}

/**
 * Egitim platformunda yonetici hesabi olusturur ya da (varsa) sifresini
 * ve rolunu gunceller. Tablolar yoksa egitim platformunun kendi sema
 * kurulumu calistiriliyor (egitim/api/db.php).
 *
 * Sabit sifreli varsayilan hesap YOK (depo herkese acik); sifreyi
 * Valentra yoneticisi panelde kendisi yaziyor.
 *
 * @return array{tamam:bool,mesaj:string}
 */
function egitim_yonetici_kaydet(string $kullaniciAdi, string $ad, string $eposta, string $sifre): array
{
    $kullaniciAdi = strtolower(trim($kullaniciAdi));
    $ad = trim($ad);
    $eposta = strtolower(trim($eposta));

    if (preg_match('/^[a-z0-9_.]{3,40}$/', $kullaniciAdi) !== 1) {
        return ['tamam' => false, 'mesaj' => 'Kullanıcı adı 3–40 karakter; küçük harf, rakam, nokta, alt çizgi.'];
    }

    if ($ad === '') {
        return ['tamam' => false, 'mesaj' => 'Ad soyad boş olamaz.'];
    }

    if ($eposta !== '' && filter_var($eposta, FILTER_VALIDATE_EMAIL) === false) {
        return ['tamam' => false, 'mesaj' => 'E-posta geçerli değil.'];
    }

    if (strlen($sifre) < 8) {
        return ['tamam' => false, 'mesaj' => 'Şifre en az 8 karakter olmalı.'];
    }

    // Egitim tablolarini (kullanici_hesap dahil) platformun kendi
    // kurulumuyla olustur.
    require_once EGITIM_KOK . '/api/db.php';
    ppBaglan();

    // Hesap yokken yapilan denemeler girisi kilitlemis olabilir.
    egitim_kilitleri_temizle();

    $hash = password_hash($sifre, PASSWORD_DEFAULT);
    $var  = db()->prepare('SELECT 1 FROM kullanici_hesap WHERE kullanici_adi = ?');
    $var->execute([$kullaniciAdi]);

    if ($var->fetchColumn()) {
        db()->prepare(
            "UPDATE kullanici_hesap SET ad = ?, sifre_hash = ?, rol = 'yonetici',
                    eposta = COALESCE(NULLIF(?, ''), eposta)
              WHERE kullanici_adi = ?"
        )->execute([$ad, $hash, $eposta, $kullaniciAdi]);

        return ['tamam' => true, 'mesaj' => $kullaniciAdi . ' güncellendi: yönetici, yeni şifresiyle giriş yapabilir.'];
    }

    db()->prepare(
        "INSERT INTO kullanici_hesap (kullanici_adi, ad, eposta, sifre_hash, rol, kayit_tarihi)
         VALUES (?, ?, NULLIF(?, ''), ?, 'yonetici', ?)"
    )->execute([$kullaniciAdi, $ad, $eposta, $hash, gmdate('Y-m-d H:i:s')]);

    return ['tamam' => true, 'mesaj' => $kullaniciAdi . ' yönetici olarak oluşturuldu. valentra.com.tr/egitim adresinden giriş yapabilirsiniz.'];
}

/**
 * Egitim girisindeki hatali deneme kilitlerini siler (bkz.
 * egitim/api/db.php: ppDenemeSiniri). Donen deger silinen kayit sayisi.
 */
function egitim_kilitleri_temizle(): int
{
    try {
        return db()->exec("DELETE FROM giris_deneme WHERE tur = 'giris'") ?: 0;
    } catch (PDOException $e) {
        return 0;   // tablo henuz yok: kilit de yok
    }
}
