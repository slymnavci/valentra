<?php
declare(strict_types=1);

/**
 * Günlük e-posta bülteni ("Bugün bilmeniz gerekenler").
 *
 * Site sahibinin istegi (03.11.2026, "baska neler yapabiliriz" onerisi):
 * gelen okuru geri getirmek icin her sabah son 24 saatin en onemli
 * haberleri e-postayla.
 *
 *   Abonelik: sayfalarin altindaki kutu -> bulten.php. CIFT ONAY (KVKK ve
 *   spam): adrese onay baglantisi gider; tiklanmadan bulten gonderilmez.
 *   Ayrilma: her e-postadaki baglanti (tek tik) ve List-Unsubscribe.
 *
 *   Gonderim: BULTEN_SAAT'ten sonra, sayfalarin sonunda (yanittan sonra)
 *   ve canli veri is akisinda; her seferinde en fazla BULTEN_PARCA abone.
 *   Gunun icerigi bir kez kuruluyor ve ayarda saklaniyor; butun aboneler
 *   ayni bulteni aliyor.
 */

require_once __DIR__ . '/ayarlar.php';
require_once __DIR__ . '/haberler.php';
require_once __DIR__ . '/seo.php';

/** Bu saatten (Türkiye) sonra günün bülteni gönderilir. */
const BULTEN_SAAT = 7;

/** Bir seferde en fazla bu kadar aboneye gönderilir (SMTP her biri ayrı bağlantı). */
const BULTEN_PARCA = 25;

/** Bültendeki haber sayısı. */
const BULTEN_HABER = 8;

/** Bu kadar üst üste gönderilemeyen adres pasife alınır. */
const BULTEN_HATA_SINIRI = 5;

function bulten_yolu(array $sorgu = []): string
{
    return '/bulten.php' . ($sorgu === [] ? '' : '?' . http_build_query($sorgu));
}

/** Tablo var mı (şema yükseltmesi henüz inmediyse bülten sessizce kapalı). */
function bulten_hazir(): bool
{
    static $hazir = null;

    if ($hazir === null) {
        try {
            db()->query('SELECT 1 FROM bulten_aboneler LIMIT 0');
            $hazir = true;
        } catch (Throwable $e) {
            $hazir = false;
        }
    }

    return $hazir;
}

/**
 * Abone kaydı ve onay e-postası.
 *
 * @return string 'gonderildi' | 'zaten' | 'gecersiz' | 'hata'
 */
function bulten_abone_ol(string $eposta): string
{
    $eposta = mb_strtolower(trim($eposta), 'UTF-8');

    if (!filter_var($eposta, FILTER_VALIDATE_EMAIL) || mb_strlen($eposta, 'UTF-8') > 190) {
        return 'gecersiz';
    }

    $ifade = db()->prepare('SELECT id, durum, jeton, olusturuldu FROM bulten_aboneler WHERE eposta = :e');
    $ifade->execute(['e' => $eposta]);
    $kayit = $ifade->fetch() ?: null;

    if ($kayit !== null && $kayit['durum'] === 'aktif') {
        return 'zaten';
    }

    // Ayni adrese onay e-postasi en fazla 10 dakikada bir (kotuye kullanim).
    if ($kayit !== null && $kayit['durum'] === 'bekliyor' && strtotime((string) $kayit['olusturuldu']) > time() - 600) {
        return 'gonderildi';
    }

    $jeton = bin2hex(random_bytes(16));

    if ($kayit === null) {
        db()->prepare('INSERT INTO bulten_aboneler (eposta, jeton) VALUES (:e, :j)')->execute(['e' => $eposta, 'j' => $jeton]);
    } else {
        db()->prepare("UPDATE bulten_aboneler SET durum = 'bekliyor', jeton = :j, olusturuldu = NOW(), ayrildi = NULL, hata_sayisi = 0 WHERE id = :id")
            ->execute(['j' => $jeton, 'id' => $kayit['id']]);
    }

    require_once __DIR__ . '/eposta.php';

    $adres = site_adresi() . bulten_yolu(['onay' => $jeton]);

    try {
        eposta_gonder(
            $eposta,
            'Valentra bültenine aboneliğinizi onaylayın',
            "Valentra'nın günlük bültenine abone olmak için bu bağlantıyı açın:\n" . $adres
                . "\n\nBu isteği siz yapmadıysanız bu e-postayı yok sayın; size başka e-posta gönderilmez.",
            eposta_sablon(
                'Aboneliğinizi onaylayın',
                'Her sabah günün en önemli haberlerini e-postanıza göndereceğiz. Aboneliği başlatmak için aşağıdaki düğmeye basın.',
                'Aboneliği onayla',
                $adres,
                'Bu isteği siz yapmadıysanız bu e-postayı yok sayın; size başka e-posta gönderilmez.'
            )
        );
    } catch (Throwable $e) {
        error_log('[valentra] bulten onay e-postasi: ' . $e->getMessage());
        // Gonderilemedi: on dakikalik bekleme uygulanmasin, okur hemen yeniden deneyebilsin.
        db()->prepare('UPDATE bulten_aboneler SET olusturuldu = NOW() - INTERVAL 1 HOUR WHERE eposta = :e')->execute(['e' => $eposta]);

        return 'hata';
    }

    return 'gonderildi';
}

/** Onay bağlantısı. */
function bulten_onayla(string $jeton): bool
{
    if (preg_match('/^[a-f0-9]{32}$/', $jeton) !== 1) {
        return false;
    }

    $ifade = db()->prepare(
        "UPDATE bulten_aboneler SET durum = 'aktif', onaylandi = NOW(), son_gonderim = CURDATE()
          WHERE jeton = :j AND durum IN ('bekliyor', 'aktif')"
    );
    $ifade->execute(['j' => $jeton]);

    if ($ifade->rowCount() > 0) {
        return true;
    }

    // Zaten aktifse rowCount 0 olabilir (degisen satir yok).
    $ifade = db()->prepare("SELECT 1 FROM bulten_aboneler WHERE jeton = :j AND durum = 'aktif'");
    $ifade->execute(['j' => $jeton]);

    return (bool) $ifade->fetchColumn();
}

/** Ayrılma bağlantısı. */
function bulten_ayril(string $jeton): bool
{
    if (preg_match('/^[a-f0-9]{32}$/', $jeton) !== 1) {
        return false;
    }

    $ifade = db()->prepare("UPDATE bulten_aboneler SET durum = 'ayrildi', ayrildi = NOW() WHERE jeton = :j");
    $ifade->execute(['j' => $jeton]);

    return $ifade->rowCount() > 0 || (bool) db()->query(
        'SELECT 1 FROM bulten_aboneler WHERE jeton = ' . db()->quote($jeton)
    )->fetchColumn();
}

/** @return array{aktif:int,bekliyor:int,ayrildi:int,bugun:int} */
function bulten_sayilar(): array
{
    $s = ['aktif' => 0, 'bekliyor' => 0, 'ayrildi' => 0, 'bugun' => 0];

    if (!bulten_hazir()) {
        return $s;
    }

    foreach (db()->query('SELECT durum, COUNT(*) AS n FROM bulten_aboneler GROUP BY durum')->fetchAll() as $r) {
        $s[$r['durum']] = (int) $r['n'];
    }

    $s['bugun'] = (int) db()->query(
        "SELECT COUNT(*) FROM bulten_aboneler WHERE durum = 'aktif' AND son_gonderim = CURDATE()"
    )->fetchColumn();

    return $s;
}

/**
 * Günün bülteni: bir kez kurulur, gün boyu aynı.
 *
 * @return array{tarih:string,konu:string,haberler:list<array<string,string>>}|null
 */
function bulten_bugunku(): ?array
{
    $bugun = date('Y-m-d');
    $kayit = json_decode(ayar_oku('bulten_icerik'), true);

    if (is_array($kayit) && ($kayit['tarih'] ?? '') === $bugun) {
        return $kayit;
    }

    // Son 24 saatin en onemli haberleri (onem, sonra yenilik).
    $satirlar = db()->query(
        "SELECT baslik, slug, ozet FROM haberler
          WHERE durum = 'yayinda' AND yayin_tarihi >= NOW() - INTERVAL 24 HOUR
          ORDER BY onem DESC, yayin_tarihi DESC
          LIMIT 40"
    )->fetchAll();

    // Ayni basligin ikinci kez yer almasi engelleniyor.
    $haberler = [];
    $gorulen  = [];

    foreach ($satirlar as $r) {
        $parmak = mb_strtolower(trim((string) $r['baslik']), 'UTF-8');

        if (isset($gorulen[$parmak])) {
            continue;
        }

        $gorulen[$parmak] = true;
        $haberler[] = [
            'baslik' => (string) $r['baslik'],
            'ozet'   => (string) $r['ozet'],
            'adres'  => site_adresi() . haber_yolu((string) $r['slug']),
        ];

        if (count($haberler) >= BULTEN_HABER) {
            break;
        }
    }

    if (count($haberler) < 3) {
        return null;
    }

    $kayit = [
        'tarih'    => $bugun,
        'konu'     => 'Bugün bilmeniz gerekenler: ' . $haberler[0]['baslik'],
        'haberler' => $haberler,
    ];
    ayar_yaz('bulten_icerik', (string) json_encode($kayit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return $kayit;
}

/** @return array{0:string,1:string} [düz metin, html] */
function bulten_metni(array $bulten, string $jeton): array
{
    $e      = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $ayril  = site_adresi() . bulten_yolu(['ayril' => $jeton]);
    $aylar  = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    $z      = strtotime($bulten['tarih']);
    $tarih  = (int) date('j', $z) . ' ' . $aylar[(int) date('n', $z)] . ' ' . date('Y', $z);
    $utm    = 'utm_source=bulten&utm_medium=email&utm_campaign=gunluk';

    $duz  = "VALENTRA — Bugün bilmeniz gerekenler, {$tarih}\n\n";
    $html = '<!DOCTYPE html><html lang="tr"><body style="margin:0;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#18181b">'
          . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">'
          . '<table role="presentation" width="100%" style="max-width:600px;background:#ffffff;border-radius:10px;border-top:4px solid #d7161f" cellpadding="0" cellspacing="0">'
          . '<tr><td style="padding:26px 28px 4px;font-size:22px;font-weight:800;letter-spacing:.06em;color:#d7161f">VALENTRA</td></tr>'
          . '<tr><td style="padding:0 28px 10px;font-size:14px;color:#71717a">Bugün bilmeniz gerekenler · ' . $e($tarih) . '</td></tr>';

    foreach ($bulten['haberler'] as $i => $h) {
        $adres = $h['adres'] . (str_contains($h['adres'], '?') ? '&' : '?') . $utm;
        $duz  .= ($i + 1) . '. ' . $h['baslik'] . "\n" . $h['ozet'] . "\n" . $adres . "\n\n";
        $html .= '<tr><td style="padding:14px 28px;border-top:1px solid #f0f0f0">'
              . '<a href="' . $e($adres) . '" style="font-size:17px;font-weight:700;color:#18181b;text-decoration:none;line-height:1.35">' . $e($h['baslik']) . '</a>'
              . '<div style="font-size:14px;line-height:1.55;color:#52525b;margin-top:6px">' . $e($h['ozet']) . '</div></td></tr>';
    }

    $site  = site_adresi() . '/?' . $utm;
    $duz  .= "Bütün haberler: {$site}\n\nBültenden ayrılmak için: {$ayril}\n";
    $html .= '<tr><td style="padding:18px 28px 6px"><a href="' . $e($site) . '" style="display:inline-block;background:#d7161f;color:#ffffff;text-decoration:none;font-weight:700;padding:11px 20px;border-radius:8px">Bütün haberler</a></td></tr>'
          . '<tr><td style="padding:14px 28px 24px;font-size:12px;line-height:1.5;color:#71717a">Bu e-postayı Valentra bültenine abone olduğunuz için aldınız. '
          . '<a href="' . $e($ayril) . '" style="color:#71717a">Bültenden ayrılın</a>.</td></tr>'
          . '</table></td></tr></table></body></html>';

    return [$duz, $html];
}

/**
 * Günün bülteninden sıradaki parçayı gönderir.
 *
 * @return array{gonderilen:int,hata:int,kalan:int}|null null: zamanı değil / içerik yok
 */
function bulten_gonder_parca(): ?array
{
    if (!bulten_hazir() || (int) date('G') < BULTEN_SAAT) {
        return null;
    }

    // Iki istek ayni anda gondermesin: kisa kilit.
    if (time() - (int) ayar_oku('bulten_kilit', '0') < 120) {
        return null;
    }

    ayar_yaz('bulten_kilit', (string) time());

    $aboneler = db()->query(
        "SELECT id, eposta, jeton FROM bulten_aboneler
          WHERE durum = 'aktif' AND (son_gonderim IS NULL OR son_gonderim < CURDATE())
          ORDER BY id LIMIT " . BULTEN_PARCA
    )->fetchAll();

    if ($aboneler === []) {
        ayar_yaz('bulten_kilit', '0');

        return null;
    }

    $bulten = bulten_bugunku();

    if ($bulten === null) {
        ayar_yaz('bulten_kilit', '0');

        return null;
    }

    require_once __DIR__ . '/eposta.php';
    @set_time_limit(120);

    $gonderilen = $hata = 0;
    $tamam  = db()->prepare('UPDATE bulten_aboneler SET son_gonderim = CURDATE(), hata_sayisi = 0 WHERE id = :id');
    $basarisiz = db()->prepare(
        "UPDATE bulten_aboneler SET hata_sayisi = hata_sayisi + 1, son_gonderim = CURDATE(),
                durum = IF(hata_sayisi + 1 >= " . BULTEN_HATA_SINIRI . ", 'ayrildi', durum)
          WHERE id = :id"
    );

    foreach ($aboneler as $a) {
        [$duz, $html] = bulten_metni($bulten, (string) $a['jeton']);
        $ayril = site_adresi() . bulten_yolu(['ayril' => $a['jeton']]);

        try {
            eposta_gonder((string) $a['eposta'], $bulten['konu'], $duz, $html, [
                'List-Unsubscribe'      => '<' . $ayril . '>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ]);
            $tamam->execute(['id' => $a['id']]);
            $gonderilen++;
        } catch (Throwable $e) {
            error_log('[valentra] bulten gonderimi #' . $a['id'] . ': ' . $e->getMessage());
            $basarisiz->execute(['id' => $a['id']]);
            $hata++;
        }
    }

    $kalan = (int) db()->query(
        "SELECT COUNT(*) FROM bulten_aboneler WHERE durum = 'aktif' AND (son_gonderim IS NULL OR son_gonderim < CURDATE())"
    )->fetchColumn();

    // Kalan varsa kilidi birak: bir sonraki istek hemen devam etsin.
    ayar_yaz('bulten_kilit', $kalan > 0 ? '0' : (string) time());

    return ['gonderilen' => $gonderilen, 'hata' => $hata, 'kalan' => $kalan];
}

/** Sayfa gönderildikten sonra (zamanıysa) bir parça gönderim. */
function bulten_gonder_sayfa_sonunda(): void
{
    if ((int) date('G') < BULTEN_SAAT || ayar_oku('bulten_bitti') === date('Y-m-d')) {
        return;
    }

    register_shutdown_function(static function (): void {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }

        ignore_user_abort(true);

        try {
            $sonuc = bulten_gonder_parca();

            if ($sonuc === null || $sonuc['kalan'] === 0) {
                // Bugun gonderilecek kimse kalmadiysa gun boyu bir daha bakma
                // (yeni onaylanan abone ertesi sabah alir).
                if (bulten_hazir() && (int) db()->query(
                    "SELECT COUNT(*) FROM bulten_aboneler WHERE durum = 'aktif' AND (son_gonderim IS NULL OR son_gonderim < CURDATE())"
                )->fetchColumn() === 0) {
                    ayar_yaz('bulten_bitti', date('Y-m-d'));
                }
            }
        } catch (Throwable $e) {
            error_log('[valentra] bulten: ' . $e->getMessage());
        }
    });
}

/** Sayfaların altındaki abonelik kutusu. */
function bulten_kutu_html(): string
{
    return '<form class="bulten-kutu" method="post" action="' . e(bulten_yolu()) . '">'
        . '<div class="bulten-metin"><strong>Her sabah günün özeti</strong>'
        . '<span>Günün en önemli haberleri sabah 7\'den sonra e-postanızda. Ücretsiz; tek tıkla ayrılabilirsiniz.</span></div>'
        . '<div class="bulten-alan"><input type="email" name="eposta" required maxlength="190" placeholder="E-posta adresiniz" aria-label="E-posta adresiniz">'
        . '<input type="text" name="web" tabindex="-1" autocomplete="off" class="bulten-tuzak" aria-hidden="true">'
        . '<button type="submit">Abone ol</button></div>'
        . '</form>';
}
