    </main>

    <footer class="alt-bant">
        <div class="sinirli">
            <span>&copy; <?= date('Y') ?> Valentra — Güncel Haberler</span>
            <span>Haberler, kaynaklarına atıf ve bağlantıyla özet olarak yayımlanır.</span>
        </div>
    </footer>
<?php /* Ziyaretin tarayicida dogrulanmasi; bkz. includes/ziyaret.php */ ?>
<?= function_exists('ziyaret_betigi') ? ziyaret_betigi() : '' ?>
</body>
</html>
