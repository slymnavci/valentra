<?php
declare(strict_types=1);

/**
 * Gunluk e-posta bulteni (03.11.2026, site sahibi: okuru geri getiren
 * ozellikler, "siradaki").
 *
 * Abonelik iki asamali (KVKK ve istenmeyen posta kurallari): okur adresini
 * yazar, onay e-postasindaki baglantiya tiklayinca abone olur. Her
 * bultende tek tikla abonelikten cikma baglantisi ve List-Unsubscribe
 * basligi var (Gmail'in "Aboneligi iptal et" dugmesi).
 *
 * Her sabah BULTEN_SAAT'ten sonra ilk ziyaretle (api/gorunum.php, sayfa
 * gittikten sonra) gunun sayisi hazirlaniyor: son 24 saatin en onemli
 * haberleri, gunun kose yazisi, acik canli anlatim. Aboneler parti parti
 * gonderiliyor (paylasimli hostingde zaman asimi olmasin); bitmeyen
 * gonderim sonraki ziyaretlerle suruyor. Panelden (admin/bulten.php)
 * onizleme, kendine deneme ve ac/kapat.
 */

require_once __DIR__ . '/ayarlar.php';
require_once __DIR__ . '/seo.php';
require_once __DIR__ . '/adres.php';
require_once __DIR__ . '/eposta.php';

const BULTEN_SAAT        = 7;    // Turkiye saati; bundan sonra gonderilir
const BULTEN_HABER       = 8;    // bultendeki haber sayisi
const BULTEN_PARTI       = 40;   // bir calismada en fazla e-posta
const BULTEN_SAATLIK_IP  = 5;    // ayni baglantidan saatte en fazla abonelik

function bulten_acik(): bool
{
    return ayar_oku('bulten_acik', '1') === '1';
}

function bulten_istanbul(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul'));
}

/** Onay, iptal ve bulten sayfasinin adresi. */
function bulten_yolu(string $islem = '', string $anahtar = ''): string
{
    return '/bulten.php' . ($islem !== '' ? '?' . $islem . '=' . rawurlencode($anahtar) : '');
}

/* ------------------------------------------------------------------------
 * Abonelik
 * --------------------------------------------------------------------- */

/**
 * Onay e-postası (04.11.2026, site sahibi: "daha dikkat cekici yapalim;
 * insanlar gordugunde girmek isteyecekleri bir sekilde iletelim").
 *
 * Genel sablon (eposta_sablon) yerine bultene ozel: guclu konu satiri ve
 * on izleme metni, kirmizi ust bant, buyuk onay dugmesi, bultende neler
 * oldugu ve gunun one cikan uc basligindan bir tadimlik. Basliklar bilerek
 * BAGLANTI DEGIL: tek tiklanacak sey onay dugmesi (abonelik yalnizca
 * okur dugmeye basinca baslar; baska bir tiklama onay sayilmaz).
 *
 * @return array{konu:string,html:string,metin:string}
 */
function bulten_onay_epostasi(string $adres): array
{
    $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    // Gunun one cikan basliklari (tadimlik); alinamazsa bolum gosterilmiyor.
    $basliklar = [];

    try {
        require_once __DIR__ . '/haberler.php';
        $ifade = db()->prepare(
            'SELECT h.baslik FROM haberler h
              WHERE h.durum = :d AND h.olusturuldu > NOW() - INTERVAL 24 HOUR
              ORDER BY h.onem DESC, h.olusturuldu DESC LIMIT 3'
        );
        $ifade->execute(['d' => HABER_YAYINDA]);
        $basliklar = array_map('strval', $ifade->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        $basliklar = [];
    }

    $konu = 'Son bir adım: yarın sabah ilk bülteniniz gelsin';
    $onIzleme = 'Tek tıkla onaylayın; her sabah günün en önemli ' . BULTEN_HABER . ' haberi 2 dakikada e-postanızda.';

    $neler = [
        ['📰', 'Günün en önemli ' . BULTEN_HABER . ' haberi', 'Gece neler oldu, sabah ilk siz bilin.'],
        ['🌍', 'Türkiye ve dünyadan', 'Gündem, ekonomi, dünya, spor: önemli ne varsa.'],
        ['✍️', 'Günün köşe yazısı', 'Gündemin arka planı, sade bir dille.'],
        ['⏱️', '2 dakikada okunur', 'Her sabah, kısa ve reklamsız.'],
    ];

    $nelerHtml = '';

    foreach ($neler as [$simge, $baslik, $aciklama]) {
        $nelerHtml .= '<tr><td width="40" valign="top" style="padding:0 0 14px;font-size:22px;line-height:1">' . $simge . '</td>'
            . '<td valign="top" style="padding:0 0 14px"><div style="font-size:15px;font-weight:700;color:#18181b">' . $e($baslik) . '</div>'
            . '<div style="font-size:13px;line-height:1.5;color:#71717a">' . $e($aciklama) . '</div></td></tr>';
    }

    $dugme = static fn (string $metin): string =>
        '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" bgcolor="#d7161f" style="border-radius:10px">'
        . '<a href="' . $e($adres) . '" style="display:block;padding:16px 20px;font-size:17px;font-weight:800;color:#ffffff;text-decoration:none;border-radius:10px">' . $e($metin) . '</a>'
        . '</td></tr></table>';

    $tadimlik = '';

    if ($basliklar !== []) {
        $satirlar = '';

        foreach ($basliklar as $i => $b) {
            $satirlar .= '<tr><td width="28" valign="top" style="padding:8px 0;font-size:15px;font-weight:800;color:#d7161f">' . ($i + 1) . '</td>'
                . '<td style="padding:8px 0;border-bottom:1px solid #f0f0f2;font-size:15px;font-weight:700;line-height:1.4;color:#18181b">' . $e($b) . '</td></tr>';
        }

        $tadimlik = '<tr><td style="padding:6px 28px 4px"><div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#a1a1aa">Bugün bültende olsaydı</div></td></tr>'
            . '<tr><td style="padding:4px 28px 18px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $satirlar . '</table>'
            . '<div style="font-size:13px;color:#71717a;margin-top:10px">Yarın sabahın en önemli haberleri de böyle, tek bakışta.</div></td></tr>';
    }

    $html = '<!DOCTYPE html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($konu) . '</title></head>'
        . '<body style="margin:0;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#18181b">'
        . '<div style="display:none;max-height:0;overflow:hidden">' . $e($onIzleme) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 10px">'
        . '<table role="presentation" width="100%" style="max-width:540px;background:#ffffff;border-radius:14px;overflow:hidden" cellpadding="0" cellspacing="0">'
        // Ust bant
        . '<tr><td bgcolor="#d7161f" style="background:#d7161f;padding:26px 28px 24px">'
        . '<div style="font-size:24px;font-weight:900;letter-spacing:.12em;color:#ffffff">VALENTRA</div>'
        . '<div style="font-size:14px;color:#ffe4e6;margin-top:4px">Günün özeti, her sabah e-postanızda</div></td></tr>'
        // Baslik ve cagri
        . '<tr><td style="padding:28px 28px 6px;font-size:26px;font-weight:900;line-height:1.25;color:#18181b">Son bir adım kaldı!</td></tr>'
        . '<tr><td style="padding:6px 28px 20px;font-size:16px;line-height:1.6;color:#3f3f46">Aboneliğinizi onaylayın, <strong>yarın sabah ilk bülteniniz</strong> e-postanızda olsun. Tek tık yeterli.</td></tr>'
        . '<tr><td style="padding:0 28px 24px">' . $dugme('Evet, bülteni istiyorum →') . '</td></tr>'
        // Neler gelecek
        . '<tr><td style="padding:4px 28px 8px"><div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#a1a1aa">Her sabah neler gelecek?</div></td></tr>'
        . '<tr><td style="padding:10px 28px 8px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $nelerHtml . '</table></td></tr>'
        . $tadimlik
        // Ikinci cagri
        . '<tr><td style="padding:6px 28px 26px">' . $dugme('Aboneliği onayla') . '</td></tr>'
        . '</table>'
        // Alt bilgi
        . '<table role="presentation" width="100%" style="max-width:540px" cellpadding="0" cellspacing="0"><tr><td style="padding:16px 24px;font-size:12px;line-height:1.6;color:#71717a;text-align:center">'
        . 'Bu e-postayı valentra.com.tr\'de bültene abone olmak istendiği için aldınız. Siz istemediyseniz yok sayın; onaylamadan abone olmazsınız.<br>'
        . 'Her bültenin altında tek tıkla abonelikten çıkabilirsiniz.<br>'
        . '<span style="color:#a1a1aa">Düğme çalışmazsa: ' . $e($adres) . '</span></td></tr></table>'
        . '</td></tr></table></body></html>';

    $metin = "Son bir adım kaldı!\n\n"
        . 'Valentra günlük bültenini onaylayın; yarın sabah ilk bülteniniz e-postanızda olsun. Her sabah'
        . ' günün en önemli ' . BULTEN_HABER . " haberi ve günün köşe yazısı; 2 dakikada okunur.\n\n"
        . "Onaylamak için: " . $adres . "\n\n"
        . ($basliklar !== [] ? "Bugün bültende olsaydı:\n- " . implode("\n- ", $basliklar) . "\n\n" : '')
        . "Bu isteği siz yapmadıysanız e-postayı yok sayın; onaylamadan abone olmazsınız.\n";

    return ['konu' => $konu, 'html' => $html, 'metin' => $metin];
}

/**
 * Adresi kaydeder ve onay e-postasi gonderir. Ziyaretciye her durumda ayni
 * yanit gosteriliyor (adresin kayitli olup olmadigi disari sizmasin).
 *
 * @return string 'tamam' | 'gecersiz' | 'cok' (siniri asti)
 */
function bulten_abone_ol(string $eposta, string $ip): string
{
    $eposta = mb_strtolower(trim($eposta), 'UTF-8');

    if (!filter_var($eposta, FILTER_VALIDATE_EMAIL) || strlen($eposta) > 190) {
        return 'gecersiz';
    }

    $sayac = db()->prepare('SELECT COUNT(*) FROM bulten_aboneleri WHERE ip = :ip AND olusturuldu > NOW() - INTERVAL 1 HOUR');
    $sayac->execute(['ip' => $ip]);

    if ((int) $sayac->fetchColumn() >= BULTEN_SAATLIK_IP) {
        return 'cok';
    }

    $var = db()->prepare('SELECT * FROM bulten_aboneleri WHERE eposta = :e');
    $var->execute(['e' => $eposta]);
    $abone = $var->fetch();

    if ($abone && $abone['durum'] === 'aktif') {
        return 'tamam'; // zaten abone; yeniden e-posta gonderme
    }

    // Ayni adrese 10 dakikada bir onay e-postasindan fazlasi gitmesin.
    if ($abone && $abone['onay_gonderildi'] !== null && strtotime((string) $abone['onay_gonderildi']) > time() - 600) {
        return 'tamam';
    }

    if ($abone) {
        $anahtar = (string) $abone['anahtar'];
        db()->prepare("UPDATE bulten_aboneleri SET durum = 'bekliyor', ayrildi = NULL, onay_gonderildi = NOW(), ip = :ip WHERE id = :id")
            ->execute(['ip' => mb_substr($ip, 0, 45), 'id' => $abone['id']]);
    } else {
        $anahtar = bin2hex(random_bytes(16));
        db()->prepare('INSERT INTO bulten_aboneleri (eposta, anahtar, ip, onay_gonderildi) VALUES (:e, :a, :ip, NOW())')
            ->execute(['e' => $eposta, 'a' => $anahtar, 'ip' => mb_substr($ip, 0, 45)]);
    }

    $adres = site_adresi() . bulten_yolu('onay', $anahtar);
    $onay  = bulten_onay_epostasi($adres);

    try {
        eposta_gonder($eposta, $onay['konu'], $onay['metin'], $onay['html']);
    } catch (RuntimeException $e) {
        error_log('[valentra] bulten onay e-postasi: ' . $e->getMessage());
    }

    return 'tamam';
}

/** @return array<string,mixed>|null */
function bulten_abone_anahtarla(string $anahtar): ?array
{
    if (!preg_match('/^[0-9a-f]{32}$/', $anahtar)) {
        return null;
    }

    $ifade = db()->prepare('SELECT * FROM bulten_aboneleri WHERE anahtar = :a');
    $ifade->execute(['a' => $anahtar]);

    return $ifade->fetch() ?: null;
}

function bulten_onayla(string $anahtar): bool
{
    $abone = bulten_abone_anahtarla($anahtar);

    if ($abone === null) {
        return false;
    }

    db()->prepare("UPDATE bulten_aboneleri SET durum = 'aktif', onaylandi = COALESCE(onaylandi, NOW()), ayrildi = NULL WHERE id = :id")
        ->execute(['id' => $abone['id']]);

    return true;
}

function bulten_ayril(string $anahtar): bool
{
    $abone = bulten_abone_anahtarla($anahtar);

    if ($abone === null) {
        return false;
    }

    db()->prepare("UPDATE bulten_aboneleri SET durum = 'iptal', ayrildi = NOW() WHERE id = :id")->execute(['id' => $abone['id']]);

    return true;
}

/** @return array{aktif:int,bekliyor:int,iptal:int} */
function bulten_sayilar(): array
{
    $s = ['aktif' => 0, 'bekliyor' => 0, 'iptal' => 0];

    try {
        foreach (db()->query('SELECT durum, COUNT(*) AS n FROM bulten_aboneleri GROUP BY durum') as $r) {
            $s[(string) $r['durum']] = (int) $r['n'];
        }
    } catch (PDOException $e) {
    }

    return $s;
}

/* ------------------------------------------------------------------------
 * Icerik
 * --------------------------------------------------------------------- */

/**
 * Gunun bulteni: son 24 saatin en onemli haberleri, kose yazisi, canli
 * anlatim. Iptal baglantisi yerine {IPTAL} yer tutucusu (her aliciya ayri).
 *
 * @return array{konu:string,html:string,metin:string,haber:int}
 */
function bulten_icerik(): array
{
    require_once __DIR__ . '/haberler.php';
    require_once __DIR__ . '/gorsel_onbellek.php';

    $taban = site_adresi();
    $izle  = static fn (string $yol): string => $taban . $yol . (str_contains($yol, '?') ? '&' : '?') . 'utm_source=bulten&utm_medium=eposta';

    $ifade = db()->prepare(
        'SELECT h.id, h.baslik, h.slug, h.ozet, h.gorsel_url, h.onem, h.kaynak_baslik, h.kaynak_url, k.ad AS kategori_adi
           FROM haberler h LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = :d AND ' . haber_ana_akis_kosulu() . '
            AND h.olusturuldu > NOW() - INTERVAL 24 HOUR
          ORDER BY h.onem DESC, h.olusturuldu DESC
          LIMIT ' . BULTEN_HABER
    );
    $ifade->execute(['d' => HABER_YAYINDA]);
    $haberler = $ifade->fetchAll();

    /*
     * CEBINIZI ETKILEYENLER (08.11.2026, site sahibi: bulten vaadi "her sabah
     * bes dakikada gundem ve cebinizi etkileyen gelismeler"). Son 24 saatin
     * kira, emekli ve memur zammi, asgari ucret, enflasyon, akaryakit ve faiz
     * haberleri (bkz. includes/konu.php); ustteki listede olanlar tekrar edilmiyor.
     */
    $cep = bulten_cep_haberleri(array_map(static fn (array $h): int => (int) $h['id'], $haberler));

    $kose = [];

    try {
        require_once __DIR__ . '/kose.php';
        $kose = array_slice(kose_son_gun(), 0, 1);
    } catch (Throwable $e) {
        $kose = [];
    }

    $canli = [];

    try {
        require_once __DIR__ . '/canli.php';
        $canli = canli_aciklar(1);
    } catch (Throwable $e) {
        $canli = [];
    }

    $tarih = bulten_istanbul();
    $aylar = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    $gunler = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];
    $tarihYazi = $tarih->format('j') . ' ' . $aylar[(int) $tarih->format('n')] . ' ' . $tarih->format('Y') . ', ' . $gunler[(int) $tarih->format('w')];

    $konu = $haberler !== []
        ? 'Günün özeti: ' . mb_strimwidth((string) $haberler[0]['baslik'], 0, 90, '…')
        : 'Valentra günün özeti · ' . $tarihYazi;

    $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $satirlar = '';
    $duz = "VALENTRA · Günün özeti · " . $tarihYazi . "\n\n";

    if ($canli !== []) {
        $c = $canli[0];
        $u = $izle(canli_yolu((string) $c['slug']));
        $satirlar .= '<tr><td style="padding:0 24px 16px"><a href="' . $e($u) . '" style="display:block;padding:12px 14px;border-radius:8px;background:#fdecec;color:#18181b;text-decoration:none">'
            . '<span style="display:inline-block;background:#d50000;color:#fff;font-size:11px;font-weight:700;letter-spacing:.08em;padding:2px 8px;border-radius:4px">● CANLI</span> '
            . '<strong style="font-size:15px">' . $e((string) $c['baslik']) . '</strong></a></td></tr>';
        $duz .= "CANLI: " . $c['baslik'] . "\n" . $u . "\n\n";
    }

    foreach ($haberler as $i => $h) {
        $u = $izle(haber_yolu((string) $h['slug']));
        $gorsel = $i === 0 ? haber_gorsel($h) : '';

        if ($gorsel !== '' && !str_starts_with($gorsel, 'http')) {
            $gorsel = $taban . $gorsel;
        }

        $satirlar .= '<tr><td style="padding:0 24px 18px">'
            . ($gorsel !== '' ? '<a href="' . $e($u) . '"><img src="' . $e($gorsel) . '" alt="" width="472" style="display:block;width:100%;max-width:472px;height:auto;border-radius:8px;margin-bottom:10px"></a>' : '')
            . ((string) ($h['kategori_adi'] ?? '') !== '' ? '<div style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#d7161f;margin-bottom:3px">' . $e((string) $h['kategori_adi']) . '</div>' : '')
            . '<a href="' . $e($u) . '" style="color:#18181b;text-decoration:none;font-size:' . ($i === 0 ? '20' : '16') . 'px;font-weight:700;line-height:1.35">' . $e((string) $h['baslik']) . '</a>'
            . ((string) $h['ozet'] !== '' && $i < 3 ? '<div style="font-size:14px;line-height:1.55;color:#52525b;margin-top:5px">' . $e(kisalt((string) $h['ozet'], 180)) . '</div>' : '')
            . '</td></tr>';
        $duz .= '• ' . $h['baslik'] . "\n  " . $u . "\n";
    }

    if ($cep !== []) {
        $satirlar .= '<tr><td style="padding:4px 24px 6px"><div style="border-top:1px solid #e4e4e7;padding-top:14px;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#0b7a3b">Cebinizi etkileyenler</div></td></tr>';
        $duz .= "\nCEBİNİZİ ETKİLEYENLER\n";

        foreach ($cep as $h) {
            $u = $izle(haber_yolu((string) $h['slug']));
            $satirlar .= '<tr><td style="padding:0 24px 10px"><a href="' . $e($u) . '" style="color:#18181b;text-decoration:none;font-size:15px;font-weight:700;line-height:1.35">'
                . $e((string) $h['baslik']) . '</a></td></tr>';
            $duz .= '• ' . $h['baslik'] . "\n  " . $u . "\n";
        }
    }

    if ($kose !== []) {
        $k = $kose[0];
        $u = $izle(kose_yolu((string) $k['slug']));
        $satirlar .= '<tr><td style="padding:4px 24px 18px"><div style="border-top:1px solid #e4e4e7;padding-top:14px;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#a1752b">Köşe yazısı</div>'
            . '<a href="' . $e($u) . '" style="color:#18181b;text-decoration:none;font-size:16px;font-weight:700;line-height:1.35">' . $e((string) $k['baslik']) . '</a></td></tr>';
        $duz .= "\nKöşe yazısı: " . $k['baslik'] . "\n" . $u . "\n";
    }

    if ($haberler === [] && $kose === [] && $canli === [] && $cep === []) {
        $satirlar .= '<tr><td style="padding:0 24px 18px;color:#52525b">Bugün öne çıkan yeni haber yok. Güncel haberler için siteyi ziyaret edin.</td></tr>';
    }

    $html = '<!DOCTYPE html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($konu) . '</title></head>'
        . '<body style="margin:0;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#18181b">'
        . '<div style="display:none;max-height:0;overflow:hidden">' . $e($haberler !== [] ? kisalt(implode(' · ', array_map(static fn (array $h): string => (string) $h['baslik'], array_slice($haberler, 1, 3))), 140) : $tarihYazi) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:20px 10px">'
        . '<table role="presentation" width="100%" style="max-width:520px;background:#ffffff;border-radius:10px;border-top:4px solid #d7161f" cellpadding="0" cellspacing="0">'
        . '<tr><td style="padding:22px 24px 4px"><a href="' . $e($izle('/')) . '" style="font-size:22px;font-weight:800;letter-spacing:.08em;color:#d7161f;text-decoration:none">VALENTRA</a></td></tr>'
        . '<tr><td style="padding:0 24px 18px;font-size:13px;color:#71717a">Günün özeti · ' . $e($tarihYazi) . '</td></tr>'
        . $satirlar
        . '<tr><td style="padding:6px 24px 22px"><a href="' . $e($izle('/')) . '" style="display:inline-block;background:#d7161f;color:#ffffff;text-decoration:none;font-weight:700;padding:11px 20px;border-radius:8px">Bütün haberler</a></td></tr>'
        . '</table>'
        . '<table role="presentation" width="100%" style="max-width:520px" cellpadding="0" cellspacing="0"><tr><td style="padding:14px 24px;font-size:12px;line-height:1.6;color:#71717a;text-align:center">'
        . 'Bu e-postayı Valentra günlük bültenine abone olduğunuz için alıyorsunuz.<br>'
        . '<a href="{IPTAL}" style="color:#71717a">Abonelikten çık</a></td></tr></table>'
        . '</td></tr></table></body></html>';

    $duz .= "\nBütün haberler: " . $izle('/') . "\n\nAbonelikten çıkmak için: {IPTAL}\n";

    return ['konu' => $konu, 'html' => $html, 'metin' => $duz, 'haber' => count($haberler)];
}

/* ------------------------------------------------------------------------
 * Gonderim
 * --------------------------------------------------------------------- */

/** Bir aliciya gunun sayisini gonderir. */
function bulten_tek_gonder(string $eposta, string $anahtar, array $sayi): void
{
    $iptal = site_adresi() . bulten_yolu('iptal', $anahtar);

    eposta_gonder(
        $eposta,
        (string) $sayi['konu'],
        str_replace('{IPTAL}', $iptal, (string) $sayi['metin']),
        str_replace('{IPTAL}', htmlspecialchars($iptal, ENT_QUOTES, 'UTF-8'), (string) $sayi['html']),
        [
            'List-Unsubscribe: <' . $iptal . '>',
            'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
            'Precedence: bulk',
        ]
    );
}

/** Saati geldiyse ve bugunun sayisi yoksa hazirlar; bitmemis sayiyi surdurur. Ucuz denetim. */
function bulten_zamani_geldiyse(): void
{
    if (!bulten_acik()) {
        return;
    }

    $simdi = bulten_istanbul();
    $bugun = $simdi->format('Y-m-d');
    $durum = ayar_oku('bulten_durum'); // "gun|bitti" ya da "gun|suruyor"

    if ($durum === $bugun . '|bitti' || (int) $simdi->format('G') < BULTEN_SAAT) {
        return;
    }

    static $kuruldu = false;

    if ($kuruldu) {
        return;
    }

    $kuruldu = true;

    register_shutdown_function(static function (): void {
        try {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            ignore_user_abort(true);

            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }

            @set_time_limit(120);
            bulten_isle();
        } catch (Throwable $e) {
            error_log('[valentra] bulten: ' . $e->getMessage());
        }
    });
}

/**
 * Bugunun sayisini hazirlar (yoksa) ve bir parti gonderir. Tek isci.
 *
 * @return array{gonderilen:int,hata:int,bitti:bool}
 */
function bulten_isle(int $sureButcesi = 90): array
{
    $sonuc = ['gonderilen' => 0, 'hata' => 0, 'bitti' => false];

    if ((int) db()->query("SELECT GET_LOCK('valentra_bulten', 0)")->fetchColumn() !== 1) {
        return $sonuc;
    }

    try {
        $bugun = bulten_istanbul()->format('Y-m-d');
        $sayi  = db()->prepare('SELECT * FROM bulten_sayilari WHERE gun = :g');
        $sayi->execute(['g' => $bugun]);
        $sayi = $sayi->fetch();

        if (!$sayi) {
            $aktif = bulten_sayilar()['aktif'];

            if ($aktif === 0) {
                ayar_yaz('bulten_durum', $bugun . '|bitti');
                $sonuc['bitti'] = true;

                return $sonuc;
            }

            $icerik = bulten_icerik();
            db()->prepare('INSERT INTO bulten_sayilari (gun, konu, html, metin, hedef) VALUES (:g, :k, :h, :m, :n)')
                ->execute(['g' => $bugun, 'k' => mb_substr($icerik['konu'], 0, 200), 'h' => $icerik['html'], 'm' => $icerik['metin'], 'n' => $aktif]);
            $sayi = db()->query('SELECT * FROM bulten_sayilari WHERE id = ' . (int) db()->lastInsertId())->fetch();
        }

        if ($sayi['durum'] === 'bitti') {
            ayar_yaz('bulten_durum', $bugun . '|bitti');
            $sonuc['bitti'] = true;

            return $sonuc;
        }

        ayar_yaz('bulten_durum', $bugun . '|suruyor');
        $bitis = time() + $sureButcesi;
        $sonAbone = (int) $sayi['son_abone'];
        $aboneler = db()->prepare("SELECT id, eposta, anahtar FROM bulten_aboneleri WHERE durum = 'aktif' AND id > :s ORDER BY id LIMIT " . BULTEN_PARTI);
        $aboneler->execute(['s' => $sonAbone]);
        $liste = $aboneler->fetchAll();
        $sonHata = '';
        $kesildi = false;

        foreach ($liste as $a) {
            if (time() >= $bitis) {
                $kesildi = true;
                break;
            }

            try {
                bulten_tek_gonder((string) $a['eposta'], (string) $a['anahtar'], $sayi);
                $sonuc['gonderilen']++;
            } catch (RuntimeException $e) {
                $sonuc['hata']++;
                $sonHata = mb_substr($e->getMessage(), 0, 300);
            }

            $sonAbone = (int) $a['id'];
        }

        // Parti dolmadiysa ve sure bitmeden hepsi islendiyse sayi tamam.
        $bitti = !$kesildi && count($liste) < BULTEN_PARTI;

        db()->prepare(
            'UPDATE bulten_sayilari SET son_abone = :s, gonderilen = gonderilen + :g, hata = hata + :h,
                    son_hata = IF(:sh = \'\', son_hata, :sh2), durum = :d, bitti = IF(:d2 = \'bitti\', NOW(), NULL) WHERE id = :id'
        )->execute(['s' => $sonAbone, 'g' => $sonuc['gonderilen'], 'h' => $sonuc['hata'], 'sh' => $sonHata, 'sh2' => $sonHata,
                    'd' => $bitti ? 'bitti' : 'gonderiliyor', 'd2' => $bitti ? 'bitti' : 'gonderiliyor', 'id' => $sayi['id']]);

        if ($bitti) {
            ayar_yaz('bulten_durum', $bugun . '|bitti');
        }

        $sonuc['bitti'] = $bitti;
    } finally {
        db()->query("SELECT RELEASE_LOCK('valentra_bulten')");
    }

    return $sonuc;
}

/**
 * Son 24 saatin "cebinizi etkileyen" haberleri: kira, zam, asgari ucret,
 * enflasyon, akaryakit, faiz dosyalarina giren haberler (bkz. includes/konu.php).
 *
 * @param list<int> $haric ustteki listede olanlar
 * @return list<array<string,mixed>>
 */
function bulten_cep_haberleri(array $haric, int $adet = 4): array
{
    require_once __DIR__ . '/konu.php';

    $sonuc = [];

    foreach (['kira-artisi', 'emekli-zammi', 'memur-maasi', 'asgari-ucret', 'enflasyon', 'akaryakit', 'faiz-karari'] as $slug) {
        foreach (konu_haberleri($slug, 3) as $h) {
            if (in_array((int) $h['id'], $haric, true) || isset($sonuc[(int) $h['id']])
                || strtotime((string) $h['yayin_tarihi']) < time() - 86400) {
                continue;
            }

            $sonuc[(int) $h['id']] = $h;
            break;
        }

        if (count($sonuc) >= $adet) {
            break;
        }
    }

    return array_values($sonuc);
}

/** Sitedeki "Gunun ozeti e-postaniza" kutusu. */
function bulten_kutu_html(string $konum = 'haber'): string
{
    if (!bulten_acik()) {
        return '';
    }

    return '<aside class="bulten-kutu bulten-kutu-' . e($konum) . '">'
        // Yayin vaadi (08.11.2026, site sahibi): "Her sabah bes dakikada gundem
        // ve cebinizi etkileyen gelismeler."
        . '<div class="bulten-kutu-metin"><strong>Her sabah 5 dakikada gündem ve cebinizi etkileyen gelişmeler</strong>'
        . '<span>Günün en önemli haberleri; zam, kira, akaryakıt, faiz ve enflasyondaki değişiklikler. Saat ' . str_pad((string) BULTEN_SAAT, 2, '0', STR_PAD_LEFT) . ':00\'de tek e-postada, ücretsiz; istediğiniz an çıkabilirsiniz.</span></div>'
        . '<form method="post" action="' . e(bulten_yolu()) . '" class="bulten-form">'
        . '<label class="gizli-etiket" for="bulten-eposta-' . e($konum) . '">E-posta adresiniz</label>'
        . '<input type="email" id="bulten-eposta-' . e($konum) . '" name="eposta" required maxlength="190" placeholder="E-posta adresiniz" autocomplete="email">'
        // Botlar icin tuzak alan: gorunmez; dolu gelirse kayit yapilmaz.
        . '<input type="text" name="web" tabindex="-1" autocomplete="off" class="bulten-tuzak" aria-hidden="true">'
        . '<button type="submit">Abone ol</button>'
        . '</form></aside>';
}
