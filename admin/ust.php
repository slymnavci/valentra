<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($panelBasligi ?? 'Yönetim Paneli') ?> — Valentra</title>
    <link rel="stylesheet" href="<?= e_varlik('/assets/admin.css') ?>">
</head>
<body>
    <header class="panel-ust">
        <?php
    require_once __DIR__ . '/../includes/sema.php';

    // Veritabani guncellenmemisse panelin her sayfasinda uyar: kullanici
    // aksi halde bunu yalnizca site hata verdiginde fark eder.
    if (!sema_guncel_mi() && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'veritabani.php'):
    ?>
        <div class="sinirli">
            <div class="uyari uyari-hata" style="margin-top:18px;">
                <strong>Veritabanı güncellenmesi gerekiyor.</strong>
                Site son güncellemede yeni tablo ve sütunlar kazandı; bunlar
                henüz uygulanmadığı için kaynak listesi ve konu grupları boş
                görünüyor, site hata verebilir.
                <a href="veritabani.php" class="dugme dugme-ana"
                   style="margin-left:10px;">Şimdi güncelle</a>
            </div>
        </div>
    <?php endif; ?>

    <?php
    /*
     * OpenAI (Luna) kredisi bitti / anahtar gecersiz uyarisi. Site
     * sahibinin istegi (23.10.2026): "kredi biterse bana haber ver".
     * Ajan her toplamada durumu bildiriyor; kredi yuklenince ilk
     * basarili calismada uyari kendiliginden kalkiyor.
     */
    require_once __DIR__ . '/../includes/openai_durum.php';
    $openAiUyari = openai_durum_uyarisi();

    if ($openAiUyari !== null):
        $openAiGun = intdiv(time() - (int) $openAiUyari['ilk'], 86400);
    ?>
        <div class="sinirli">
            <div class="uyari uyari-hata" style="margin-top:18px;">
                <?php if ($openAiUyari['durum'] === 'kredi'): ?>
                    <strong>OpenAI kredisi bitti.</strong>
                    Haberler şu an yedek model (Gemini) ile yazılıyor; bu yaklaşık
                    6 kat daha pahalı. Kredi yükleyin; ajanın bir sonraki
                    çalışmasında Luna'ya kendiliğinden döner ve bu uyarı kalkar.
                    <a href="https://platform.openai.com/settings/organization/billing/overview"
                       target="_blank" rel="noopener" class="dugme dugme-ana"
                       style="margin-left:10px;">Kredi yükle</a>
                <?php else: ?>
                    <strong>OpenAI anahtarı geçersiz.</strong>
                    Haberler şu an yedek model (Gemini) ile yazılıyor. GitHub'daki
                    <code>OPENAI_API_KEY</code> değerini yeni bir anahtarla güncelleyin.
                    <a href="https://platform.openai.com/api-keys" target="_blank"
                       rel="noopener" class="dugme dugme-ana" style="margin-left:10px;">API anahtarları</a>
                <?php endif; ?>
                <br><small>
                    Son kontrol: <?= e(date('d.m.Y H:i', (int) $openAiUyari['zaman'])) ?>
                    <?= $openAiGun > 0 ? ' · ' . $openAiGun . ' gündür sürüyor' : '' ?>
                    <?= $openAiUyari['mesaj'] !== '' ? ' · OpenAI: ' . e(mb_substr($openAiUyari['mesaj'], 0, 160, 'UTF-8')) : '' ?>
                </small>
            </div>
        </div>
    <?php endif; ?>

    <div class="sinirli">
            <a class="logo" href="gunluk.php" style="color:#fff;display:flex;align-items:center;gap:10px;"><img src="/assets/logo.svg" alt="" width="30" height="26" style="background:#fff;border-radius:4px;padding:2px;">VALENTRA</a>
            <?php
            /*
             * Menu gruplari. Tek tek 15 baglanti iki satira tasiyordu;
             * ilgili sayfalar acilir listelerde toplandi. Bulunulan
             * sayfanin grubu isaretleniyor.
             */
            $panelMenu = [
                'Günlük işler'  => 'gunluk.php',
                'Haberler'      => 'index.php',
                'İçerik'        => [
                    'Köşe yazıları'   => 'kose-yazilari.php',
                    'Rehberler'       => 'rehberler.php',
                    'Pratik bilgiler' => 'pratik.php',
                    'Grafikler'       => 'grafikler.php',
                    'Kanun metinleri' => 'kanunlar.php',
                ],
                'Eğitim'        => 'egitim.php',
                'Ajan ve aktarım' => [
                    'Ajan'             => 'ajan.php',
                    'Kaynaklar'        => 'kaynaklar.php',
                    'Ajan anahtarları' => 'anahtarlar.php',
                    'Eğitim aktarımı'  => 'egitim.php',
                ],
                'Arama motorları' => [
                    'Google görünürlüğü'       => 'google.php',
                    'Arama motoru ayarları'    => 'seo.php',
                ],
                'Site'          => [
                    'Spor verisi'  => 'spor.php',
                    'Ziyaretçiler' => 'istatistik.php',
                    'Veritabanı'   => 'veritabani.php',
                    'Siteyi gör ↗' => '/',
                ],
            ];
            $panelSayfa = basename($_SERVER['SCRIPT_NAME'] ?? '');
            ?>
            <nav class="panel-menu">
                <?php foreach ($panelMenu as $ad => $hedef): ?>
                    <?php if (is_string($hedef)): ?>
                        <a href="<?= e($hedef) ?>" class="<?= $panelSayfa === $hedef ? 'aktif' : '' ?>"><?= e($ad) ?></a>
                    <?php else: ?>
                        <details class="panel-grup <?= in_array($panelSayfa, $hedef, true) ? 'aktif' : '' ?>">
                            <summary><?= e($ad) ?></summary>
                            <div class="panel-acilir">
                                <?php foreach ($hedef as $altAd => $altHedef): ?>
                                    <a href="<?= e($altHedef) ?>" class="<?= $panelSayfa === $altHedef ? 'aktif' : '' ?>"
                                       <?= $altHedef === '/' ? 'target="_blank" rel="noopener"' : '' ?>><?= e($altAd) ?></a>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                <?php endforeach; ?>
                <a href="cikis.php" class="panel-cikis"><?= e(aktif_yonetici_ad()) ?> — Çıkış</a>
            </nav>
            <script>
            // Ayni anda tek acilir liste; disari tiklayinca kapanir.
            (function () {
                var gruplar = document.querySelectorAll('.panel-grup');
                gruplar.forEach(function (g) {
                    g.addEventListener('toggle', function () {
                        if (g.open) { gruplar.forEach(function (d) { if (d !== g) { d.open = false; } }); }
                    });
                });
                document.addEventListener('click', function (o) {
                    if (!o.target.closest('.panel-grup')) { gruplar.forEach(function (d) { d.open = false; }); }
                });
            })();
            </script>
        </div>
    </header>

    <div class="sinirli">
