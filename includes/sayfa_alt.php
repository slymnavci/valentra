    </main>

    <footer class="alt-bant">
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
    </footer>
<?php /* Ziyaretin tarayicida dogrulanmasi; bkz. includes/ziyaret.php */ ?>
<?= function_exists('ziyaret_betigi') ? ziyaret_betigi() : '' ?>
</body>
</html>
