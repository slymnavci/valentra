<?php
declare(strict_types=1);

/**
 * V Network ust bandi (includes/sayfa_ust.php, $networkSayfasi = true).
 *
 * Haber sitesinin logo bandi, menusu ve son dakika seridi yerine: solda V
 * Network logosu, ortada bolumler (Akis, Kisiler, Mesajlar, Profil),
 * sagda gece/gunduz ve giris ya da uye simgesi. Haber sitesine donus
 * logonun yaninda.
 *
 * Giris yapmamis ziyaretcinin sayfasi onbellekte olabilir; uyeye ozel
 * her sey (ad, okunmamis mesaj) yalnizca uyenin onbellege girmeyen
 * sayfasinda.
 */

require_once __DIR__ . '/network.php';

$nwUye     = aktif_uye();
$nwAktif   = (string) ($nwAktif ?? 'akis');
$nwMesajSayi = $nwUye !== null ? mesaj_okunmamis((int) $nwUye['id']) : 0;
$nwBolumler = [
    'akis'    => ['Akış', nw_yol(), '<path d="M3 11.5 12 4l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>'],
    'kisiler' => ['Kişiler', nw_yol('kisiler'), '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.2-5.5 6.5-5.5s5.9 1.9 6.5 5.5"/><circle cx="17" cy="9" r="2.6"/><path d="M16.5 14.6c2.6.2 4.4 1.9 5 5.4"/>'],
    'mesajlar' => ['Mesajlar', nw_yol('mesajlar'), '<path d="M4 5h16v11H8l-4 4z"/>'],
];
?>
<header class="nw-bant">
    <div class="nw-bant-ic">
        <a class="nw-bant-logo" href="<?= e(nw_yol()) ?>" aria-label="V Network akışı"><?= network_logo_html(40) ?></a>
        <a class="nw-bant-donus" href="/" title="Valentra haber sitesine dön">← Valentra Haber</a>

        <nav class="nw-bant-menu" aria-label="V Network">
            <?php foreach ($nwBolumler as $anahtar => [$ad, $yol, $simge]): ?>
                <a href="<?= e($yol) ?>" class="<?= $nwAktif === $anahtar ? 'aktif' : '' ?>"<?= $nwAktif === $anahtar ? ' aria-current="page"' : '' ?>>
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true"><?= $simge ?></svg>
                    <span><?= e($ad) ?></span>
                    <?php if ($anahtar === 'mesajlar' && $nwMesajSayi > 0): ?><b class="nw-rozet"><?= $nwMesajSayi > 99 ? '99+' : $nwMesajSayi ?></b><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="nw-bant-sag">
            <button type="button" class="tema-dugme nw-tema" id="tema-dugme" aria-label="Gece/gündüz modu">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
            </button>
            <?php if ($nwUye !== null): ?>
                <a class="nw-bant-uye<?= $nwAktif === 'profil' ? ' aktif' : '' ?>" href="<?= e(nw_profil_yolu((int) $nwUye['id'])) ?>" title="Profilim">
                    <?= uye_simge_html($nwUye, 34) ?>
                    <span><?= e((string) $nwUye['ad']) ?></span>
                </a>
            <?php else: ?>
                <a class="uye-dugme nw-bant-giris" href="<?= e(uye_yolu('giris', nw_yol())) ?>">Giriş yap</a>
                <a class="uye-dugme uye-dugme-ikincil nw-bant-kayit" href="<?= e(uye_yolu('kayit', nw_yol())) ?>">Üye ol</a>
            <?php endif; ?>
        </div>
    </div>
</header>
<script>
(function () {
    // Gece/gunduz dugmesi: sitenin tema tercihiyle ayni anahtar (bkz. sayfa_ust.php).
    var d = document.getElementById('tema-dugme');
    if (!d) { return; }
    d.addEventListener('click', function () {
        var kok = document.documentElement;
        var simdi = kok.getAttribute('data-tema') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'koyu' : 'acik');
        var yeni = simdi === 'koyu' ? 'acik' : 'koyu';
        kok.setAttribute('data-tema', yeni);
        try { localStorage.setItem('valentra-tema', yeni); } catch (e) {}
    });
})();
</script>
