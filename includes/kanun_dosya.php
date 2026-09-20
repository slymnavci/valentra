<?php
declare(strict_types=1);

/**
 * Panelden yüklenen kanun PDF'leri.
 *
 * Neden var: kanun metnini resmi kaynaktan cekmek her zaman mumkun
 * olmuyor — kaynak sunucu erisime kapali olabiliyor, sertifika zinciri
 * tamamlanamiyor ya da adres degisiyor. Bu durumda sayfa bos kaliyordu.
 * Yonetici metni elinde varsa yukleyebilsin diye bu yol acildi.
 *
 * Yuklenen dosya bilincli olarak bir KOPYADIR ve kopyanin eskime riski
 * var; bu yuzden yuklenme tarihi hem panelde hem ziyaretcinin gordugu
 * sayfada yaziyor ve resmi kaynaga giden bag her zaman duruyor. Kopya
 * tutmama tercihinden sapmanin bedeli bu sekilde gorunur kaliyor.
 *
 * Dosyalar includes/ altinda duruyor: o klasor .htaccess ile dogrudan
 * erisime kapali, yani PDF yalnizca api/kanun-pdf.php uzerinden
 * servis ediliyor.
 */

/** Yüklenen dosyaların klasörü. */
function kanun_dosya_klasoru(): string
{
    return __DIR__ . '/kanun_pdf';
}

/** Bir kanunun yüklenmiş dosyasının yolu. */
function kanun_dosya_yolu(int $no): string
{
    return kanun_dosya_klasoru() . '/' . $no . '.pdf';
}

/** Yüklenmiş dosya var mı? */
function kanun_dosya_var_mi(int $no): bool
{
    $yol = kanun_dosya_yolu($no);

    return is_file($yol) && is_readable($yol) && filesize($yol) > 0;
}

/**
 * Yüklenmiş dosyanın künyesi.
 *
 * @return array{var:bool,tarih:string,boyut:int}
 */
function kanun_dosya_bilgisi(int $no): array
{
    if (!kanun_dosya_var_mi($no)) {
        return ['var' => false, 'tarih' => '', 'boyut' => 0];
    }

    $yol = kanun_dosya_yolu($no);

    return [
        'var'   => true,
        'tarih' => date('Y-m-d H:i:s', (int) filemtime($yol)),
        'boyut' => (int) filesize($yol),
    ];
}

/**
 * Yüklenen dosyayı doğrular ve yerine koyar.
 *
 * ESKISI KORUNUR. Dogrulamayi gecmeyen bir yukleme mevcut dosyaya
 * DOKUNMAZ: yeni dosya once gecici bir ada yaziliyor, butun kontroller
 * gectikten sonra tek islemde yerine geciyor. Aksi halde bozuk bir
 * yukleme, calisan metni de goturur ve sayfa yukleme oncesinden daha
 * kotu duruma duserdi.
 *
 * @param array<string,mixed> $dosya $_FILES girdisi
 * @return array{tamam:bool,mesaj:string}
 */
function kanun_dosya_yukle(int $no, array $dosya): array
{
    $hataKodu = (int) ($dosya['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($hataKodu !== UPLOAD_ERR_OK) {
        return ['tamam' => false, 'mesaj' => kanun_yukleme_hatasi($hataKodu)];
    }

    $gecici = (string) ($dosya['tmp_name'] ?? '');

    /*
     * Dosyanin gercekten yuklenmis olmasi sart: is_uploaded_file
     * olmadan, istekle gelen bir yol sunucudaki baska bir dosyayi
     * gostermek icin kullanilabilirdi.
     */
    if ($gecici === '' || !is_uploaded_file($gecici)) {
        return ['tamam' => false, 'mesaj' => 'Geçerli bir yükleme bulunamadı.'];
    }

    $boyut = (int) ($dosya['size'] ?? 0);

    if ($boyut <= 0) {
        return ['tamam' => false, 'mesaj' => 'Dosya boş.'];
    }

    if ($boyut > 40 * 1024 * 1024) {
        return ['tamam' => false, 'mesaj' => 'Dosya 40 MB sınırını aşıyor.'];
    }

    $kontrol = kanun_pdf_dogrula($gecici);

    if (!$kontrol['tamam']) {
        return $kontrol;
    }

    $klasor = kanun_dosya_klasoru();

    if (!is_dir($klasor) && !@mkdir($klasor, 0755, true) && !is_dir($klasor)) {
        return ['tamam' => false, 'mesaj' => 'Klasör oluşturulamadı: ' . $klasor];
    }

    $hedef  = kanun_dosya_yolu($no);
    $tampon = $hedef . '.yeni';

    if (!@move_uploaded_file($gecici, $tampon)) {
        return ['tamam' => false, 'mesaj' => 'Dosya yazılamadı; '
                                           . basename($klasor) . ' klasörü yazılabilir olmalı.'];
    }

    // Tasima sirasinda bozulma ihtimaline karsi hedefteki dosya da
    // sinanir; gecmezse eskisi yerinde kalir.
    $sonKontrol = kanun_pdf_dogrula($tampon);

    if (!$sonKontrol['tamam']) {
        @unlink($tampon);

        return ['tamam' => false, 'mesaj' => 'Yazıldıktan sonra doğrulama geçmedi: '
                                           . $sonKontrol['mesaj'] . ' Eski dosya korundu.'];
    }

    if (!@rename($tampon, $hedef)) {
        @unlink($tampon);

        return ['tamam' => false, 'mesaj' => 'Dosya yerine konamadı. Eski dosya korundu.'];
    }

    return ['tamam' => true, 'mesaj' => 'PDF yüklendi ve doğrulandı ('
                                      . number_format($kontrol['sayfa']) . ' sayfa, '
                                      . number_format($boyut / 1024 / 1024, 1) . ' MB).'];
}

/**
 * Dosyanın gerçekten okunabilir bir PDF olduğunu sınar.
 *
 * Uzantiya ve tarayicinin bildirdigi turune GUVENILMEZ: ikisi de
 * istemciden gelir. Dosyanin kendisine bakiliyor:
 *   - %PDF imzasiyla basliyor mu
 *   - sonunda %%EOF isareti var mi (yarim inen dosyalarda olmaz)
 *   - icinde en az bir sayfa nesnesi var mi
 *
 * @return array{tamam:bool,mesaj:string,sayfa:int}
 */
function kanun_pdf_dogrula(string $yol): array
{
    $tanitici = @fopen($yol, 'rb');

    if ($tanitici === false) {
        return ['tamam' => false, 'mesaj' => 'Dosya okunamadı.', 'sayfa' => 0];
    }

    $bas = (string) fread($tanitici, 1024);

    /*
     * Son kisim: %%EOF dosyanin sonuna yakin durur.
     *
     * Geri sarma miktari dosya boyutuyla sinirlaniyor. Sinirlanmazsa
     * 2 KB'tan kucuk dosyalarda fseek basarisiz oluyor, okuma bos
     * doneyordu ve GECERLI bir PDF "eksik" diye reddediliyordu.
     */
    $boyut  = max(0, (int) @filesize($yol));
    $kuyruk = min(2048, $boyut);

    fseek($tanitici, $boyut - $kuyruk, SEEK_SET);
    $son = (string) fread($tanitici, max(1, $kuyruk));
    fclose($tanitici);

    if (!str_starts_with($bas, '%PDF')) {
        return ['tamam' => false, 'mesaj' => 'Dosya PDF değil (ilk baytlar: '
                                           . preg_replace('/[^\x20-\x7E]/', '.', substr($bas, 0, 16))
                                           . ').', 'sayfa' => 0];
    }

    if (!str_contains($son, '%%EOF')) {
        return ['tamam' => false, 'mesaj' => 'PDF eksik görünüyor; sonlandırma '
                                           . 'işareti (%%EOF) yok. Dosya yarım inmiş olabilir.',
                'sayfa' => 0];
    }

    /*
     * Sayfa sayisi bilgi amacli. Sikistirilmis PDF'lerde sayilamayabilir;
     * sayilamamasi hata degil, o yuzden sonuc yine "tamam".
     */
    $tumu  = (string) @file_get_contents($yol);
    $sayfa = preg_match_all('#/Type\s*/Page[^s]#', $tumu);

    return ['tamam' => true, 'mesaj' => 'Geçerli PDF.', 'sayfa' => max(0, (int) $sayfa)];
}

/** Yüklenmiş dosyayı siler. */
function kanun_dosya_sil(int $no): bool
{
    $yol = kanun_dosya_yolu($no);

    return is_file($yol) ? @unlink($yol) : true;
}

/** PHP'nin yükleme hata kodunu anlaşılır cümleye çevirir. */
function kanun_yukleme_hatasi(int $kod): string
{
    return match ($kod) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
            'Dosya sunucunun izin verdiği boyutu aşıyor (php.ini: upload_max_filesize).',
        UPLOAD_ERR_PARTIAL   => 'Dosya yarım yüklendi; tekrar deneyin.',
        UPLOAD_ERR_NO_FILE   => 'Dosya seçilmedi.',
        UPLOAD_ERR_NO_TMP_DIR => 'Sunucuda geçici klasör yok.',
        UPLOAD_ERR_CANT_WRITE => 'Sunucu diske yazamadı.',
        UPLOAD_ERR_EXTENSION => 'Bir PHP eklentisi yüklemeyi durdurdu.',
        default              => 'Yükleme başarısız (kod ' . $kod . ').',
    };
}
