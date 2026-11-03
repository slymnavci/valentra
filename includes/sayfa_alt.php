    </main>

    <?php if (!empty($networkSayfasi)): ?>
    <footer class="nw-alt">
        <span>&copy; <?= date('Y') ?> V Network · Valentra</span>
        <a href="/network/kurallar">Topluluk Kuralları</a>
        <a href="/">Valentra Haber</a>
    </footer>
    <?php else: ?>
    <footer class="alt-bant">
        <?php /* Gunluk bulten aboneligi (03.11.2026; bkz. includes/bulten.php). */ ?>
        <?php require_once __DIR__ . '/bulten.php'; ?>
        <div class="sinirli"><?= bulten_kutu_html() ?></div>
        <?php require_once __DIR__ . '/kurumsal.php'; ?>
        <nav class="sinirli alt-bant-baglar" aria-label="Kurumsal">
            <?php foreach (kurumsal_sayfalar() as $kurumsalAnahtar => $kurumsalAd): ?>
                <a href="<?= e(kurumsal_yolu($kurumsalAnahtar)) ?>"><?= e($kurumsalAd) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="sinirli">
            <span>&copy; <?= date('Y') ?> Valentra — Güncel Haberler</span>
            <span>Haberler, kaynaklarına atıf ve bağlantıyla özet olarak yayımlanır.</span>
        </div>
        <div class="sinirli alt-bant-uygulama">
            <button type="button" class="alt-bant-dugme" data-bildirim-dugme hidden>🔔 Son dakika bildirimlerini aç</button>
            <button type="button" class="alt-bant-dugme" data-uygulama-dugme hidden>📲 Valentra uygulamasını yükle</button>
        </div>
    </footer>
    <?php endif; ?>
<?php /* Ziyaretin tarayicida dogrulanmasi; bkz. includes/ziyaret.php */ ?>
<?= function_exists('sayfa_onbellek_ziyaret_betigi') ? sayfa_onbellek_ziyaret_betigi() : (function_exists('ziyaret_betigi') ? ziyaret_betigi() : '') ?>
<?php require_once __DIR__ . '/olcum_reklam.php'; ?>
<?= cerez_bildirimi_html() ?>
<?php require_once __DIR__ . '/web_push.php'; ?>
<script src="<?= e(varlik('/assets/bildirim.js')) ?>" data-anahtar="<?= e(push_acik_vapid()) ?>" defer></script>
<?php /* Paylasim dugmeleri (03.11.2026; bkz. includes/paylas.php). */ ?>
<script src="<?= e(varlik('/assets/paylas.js')) ?>" defer></script>
</body>
</html>
