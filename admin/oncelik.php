<?php
declare(strict_types=1);

/**
 * Öncelik masası (12.11.2026; bkz. includes/oncelik.php).
 *
 * Editorun uc isi tek sayfada:
 *   1. ACIL INCELEME: kritik ama dogrulanmamis haberler ve dogrulanmamis
 *      iddialar otomatik yayimlanmiyor; burada yayimlanir ya da reddedilir.
 *   2. ANA SAYFA SIRASI: ilk 20 haber, her biri icin "bu haber neden bu
 *      oncelikte ve neden bu sirada" (karar kaydi: oncelik, onem puani,
 *      dogrulama, kaynaklar, olay zamani, son onemli gelisme, gerekce).
 *   3. MUDAHALE: mansete al / mansetten cikar / onceligi degistir (bitis
 *      suresi ve gerekce zorunlu), otomatik yayini durdur / geri cek,
 *      duzeltme notu. Otomasyon suresi dolana kadar editor kararini
 *      degistirmez; suresi dolan ya da dolmak uzere olan icin uyari.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/oncelik.php';

giris_zorunlu();

$panelBasligi = 'Öncelik masası';
$bildirimler  = [
    'ayarlandi'  => 'Editör müdahalesi kaydedildi; ana sayfa yenilendi.',
    'kaldirildi' => 'Müdahale kaldırıldı; haber otomatik sıraya döndü.',
    'durduruldu' => 'Haberin otomatik yayını durduruldu ve haber geri çekildi.',
    'duzeltme'   => 'Düzeltme notu kaydedildi.',
    'bant'       => 'Son dakika bandı kararı kaydedildi.',
    'bant_kaldirildi' => 'Bant kararı kaldırıldı; haber otomatik karara döndü.',
    'bant_modu'  => 'Son dakika bandının modu değiştirildi.',
    'onaylandi'  => 'Haber yayımlandı.',
    'reddedildi' => 'Haber reddedildi.',
];
$bildirim = $bildirimler[(string) ($_GET['bildirim'] ?? '')] ?? '';
$hata     = mb_substr((string) ($_GET['hata'] ?? ''), 0, 200, 'UTF-8');

$hazir     = oncelik_hazir();
$bekleyen  = $hazir ? oncelik_inceleme_bekleyenler() : [];
$sira      = $hazir ? oncelik_ana_sayfa_sirasi(ANA_SAYFA_MANSET_ADEDI) : [];
$odak      = (int) ($_GET['id'] ?? 0);
$odakHaber = $odak > 0 ? haber_bul($odak) : null;

/** Sirada olmayan bir haber adresle acildiysa (duzenleme sayfasindan) o da gosterilir. */
if ($hazir && $odakHaber !== null && !in_array($odak, array_map(static fn (array $h): int => (int) $h['id'], $sira), true)) {
    $ifade = db()->prepare(
        'SELECT h.*, k.ad AS kategori_adi, ' . haber_dalga_ifadesi() . ' AS dalga, ' . haber_etkin_onem_ifadesi() . ' AS etkin_onem,
                ' . oncelik_etkin_sinif_ifadesi() . ' AS etkin_sinif, ' . oncelik_sinif_puani_ifadesi() . ' AS sinif_puani,
                ' . oncelik_editor_gecerli_ifadesi() . ' AS editor_gecerli,
                TIMESTAMPDIFF(MINUTE, COALESCE(h.gelisme_zamani, h.kaynak_tarihi, h.olusturuldu), NOW()) AS gelisme_dakika,
                TIMESTAMPDIFF(MINUTE, NOW(), h.editor_bitis) AS editor_kalan_dakika
           FROM haberler h LEFT JOIN kategoriler k ON k.id = h.kategori_id WHERE h.id = :id'
    );
    $ifade->execute(['id' => $odak]);
    $odakHaber = $ifade->fetch() ?: null;
} else {
    $odakHaber = null;
}

$sinifRengi = [ONCELIK_KRITIK => 'rozet-reddedildi', ONCELIK_YUKSEK => 'rozet-taslak', ONCELIK_NORMAL => 'rozet-skor', ONCELIK_DUSUK => 'rozet-skor'];

/** Bir haberin karar kaydi ve mudahale formlari. */
$kart = static function (array $h, ?int $siraNo) use ($sinifRengi): void {
    $a       = oncelik_aciklama($h);
    $etkin   = (int) ($h['etkin_sinif'] ?? 1);
    $gecerli = (int) ($h['editor_gecerli'] ?? 0) === 1;
    $gecmis  = oncelik_gecmis((string) ($h['olay_kimlik'] ?? ''), 12);
    ?>
    <div class="kutu" id="h<?= (int) $h['id'] ?>" style="margin-bottom:12px;">
        <div style="display:flex;gap:10px;align-items:baseline;flex-wrap:wrap;">
            <?php if ($siraNo !== null): ?><strong style="font-size:1.2rem;min-width:2ch;"><?= $siraNo ?>.</strong><?php endif; ?>
            <a href="duzenle.php?id=<?= (int) $h['id'] ?>" style="font-weight:600;flex:1;min-width:240px;"><?= e((string) $h['baslik']) ?></a>
            <span class="rozet <?= $sinifRengi[$etkin] ?? 'rozet-skor' ?>"><?= e($a['sinif']) ?></span>
            <span class="rozet rozet-skor"><?= e((string) ($h['kategori_adi'] ?? '—')) ?></span>
            <span class="rozet rozet-skor">önem <?= (int) $h['onem'] ?></span>
            <?php if ($gecerli): ?><span class="rozet rozet-yayinda">editör kararı</span><?php endif; ?>
        </div>

        <p style="margin:8px 0 0;"><strong>Neden bu sırada?</strong> <?= e($a['neden']) ?></p>

        <?php if ($a['uyari'] !== ''): ?>
            <div class="uyari uyari-hata" style="margin:8px 0 0;"><?= e($a['uyari']) ?></div>
        <?php endif; ?>

        <details style="margin-top:8px;">
            <summary>Karar kaydı, olay geçmişi ve müdahale</summary>

            <ul style="margin:8px 0;padding-left:18px;">
                <?php foreach ($a['ayrinti'] as $satir): ?><li><?= e($satir) ?></li><?php endforeach; ?>
            </ul>

            <?php if ($gecmis !== []): ?>
                <p style="margin:8px 0 4px;"><strong>Olay geçmişi</strong> (kaynaklar ve güncellemeler)</p>
                <ul style="margin:0 0 8px;padding-left:18px;">
                    <?php foreach ($gecmis as $g): ?>
                        <li><?= e((string) $g['zaman']) ?> — <em><?= e((string) $g['tur']) ?></em>: <?= e((string) $g['aciklama']) ?><?= (string) $g['kaynak'] !== '' ? ' (' . e((string) $g['kaynak']) . ')' : '' ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <form method="post" action="islem.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-top:10px;">
                <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                <input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
                <input type="hidden" name="islem" value="oncelik_ayarla">
                <label>Manşet<br>
                    <select name="manset">
                        <option value="0">değiştirme</option>
                        <option value="1">manşete al (1. sıra)</option>
                        <option value="-1">manşetten çıkar</option>
                    </select>
                </label>
                <label>Öncelik<br>
                    <select name="oncelik">
                        <option value="">değiştirme</option>
                        <?php foreach (array_reverse(ONCELIK_ADLAR, true) as $deger => $ad): ?>
                            <option value="<?= (int) $deger ?>"><?= e($ad) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Bitiş (saat sonra)<br>
                    <input type="number" name="saat" min="0.5" max="<?= (int) ONCELIK_EDITOR_EN_FAZLA_SAAT ?>" step="0.5" value="3" required style="width:8ch;">
                </label>
                <label style="flex:1;min-width:220px;">Gerekçe (zorunlu)<br>
                    <input type="text" name="gerekce" maxlength="300" required style="width:100%;">
                </label>
                <button class="dugme dugme-ana" type="submit">Uygula</button>
            </form>

            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;align-items:end;">
                <?php if ($gecerli || !empty($h['editor_bitis'])): ?>
                    <form method="post" action="islem.php">
                        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
                        <input type="hidden" name="islem" value="oncelik_kaldir">
                        <button class="dugme" type="submit">Müdahaleyi kaldır (otomatik sıraya dön)</button>
                    </form>
                <?php endif; ?>

                <form method="post" action="islem.php" style="display:flex;gap:8px;flex:1;min-width:280px;"
                      onsubmit="return confirm('Haber yayından çekilecek ve otomatik yayın bu olayda duracak. Emin misiniz?');">
                    <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
                    <input type="hidden" name="islem" value="yayin_durdur">
                    <input type="text" name="gerekce" maxlength="300" required placeholder="Geri çekme gerekçesi" style="flex:1;">
                    <button class="dugme dugme-ret" type="submit">Geri çek / otomatik yayını durdur</button>
                </form>
            </div>

            <?php if (oncelik_bant_hazir()): ?>
                <?php $bantEditor = (int) ($h['bant_editor'] ?? 0) !== 0 && strtotime((string) ($h['bant_bitis'] ?? '')) > time(); ?>
                <form method="post" action="islem.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-top:10px;">
                    <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
                    <input type="hidden" name="islem" value="bant_ayarla">
                    <label>Son dakika bandı<br>
                        <select name="yon">
                            <option value="1">banda al</option>
                            <option value="-1">banttan çıkar</option>
                        </select>
                    </label>
                    <label>Bitiş (saat sonra)<br>
                        <input type="number" name="saat" min="0.5" max="<?= (int) ONCELIK_EDITOR_EN_FAZLA_SAAT ?>" step="0.5" value="2" required style="width:8ch;">
                    </label>
                    <label style="flex:1;min-width:220px;">Gerekçe (zorunlu)<br>
                        <input type="text" name="gerekce" maxlength="300" required style="width:100%;">
                    </label>
                    <button class="dugme" type="submit">Bant kararını uygula</button>
                    <?php if ($bantEditor): ?>
                        <button class="dugme" type="submit" name="islem" value="bant_kaldir" formnovalidate>Bant kararını kaldır</button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>

            <p style="margin:10px 0 0;">
                <a class="dugme" href="birlestir.php">Aynı olayın haberlerini birleştir →</a>
                <a class="dugme" href="ajan.php">Otomatik yayını tümden durdur / aç →</a>
            </p>

            <form method="post" action="islem.php" style="display:flex;gap:8px;margin-top:10px;">
                <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                <input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
                <input type="hidden" name="islem" value="duzeltme">
                <input type="text" name="duzeltme" maxlength="600" value="<?= e((string) ($h['duzeltme'] ?? '')) ?>"
                       placeholder="Düzeltme notu (haberin altında okura gösterilir; boş bırakılırsa kaldırılır)" style="flex:1;">
                <button class="dugme" type="submit">Düzeltme notunu kaydet</button>
            </form>
        </details>
    </div>
    <?php
};

require __DIR__ . '/ust.php';
?>

<h1>Öncelik masası</h1>
<p class="ozet">Ana sayfa önce <strong>öncelik sınıfına</strong> (kritik, yüksek, normal, düşük), sonra sınıf içi puana göre sıralanır.
Kritik haber son önemli gelişmeden itibaren <?= (int) ONCELIK_KRITIK_SAAT ?> saat manşet adayıdır; yeni önemli gelişme gelmezse kendiliğinden iner.
Editör kararı, bitiş süresi dolana kadar otomasyonun önündedir.</p>

<?php if ($bildirim !== ''): ?><div class="uyari uyari-basari"><?= e($bildirim) ?></div><?php endif; ?>
<?php if ($hata !== ''): ?><div class="uyari uyari-hata"><?= e($hata) ?></div><?php endif; ?>

<?php if (!$hazir): ?>
    <div class="uyari uyari-hata">Öncelik sütunları veritabanında yok. <a href="veritabani.php">Veritabanı</a> sayfasından şemayı güncelleyin.</div>
<?php else: ?>

    <h2>Acil inceleme <?= $bekleyen !== [] ? '(' . count($bekleyen) . ')' : '' ?></h2>
    <?php if ($bekleyen === []): ?>
        <p class="ozet">Bekleyen haber yok. Haberler onay beklemeden yayımlanır; doğrulanmamış olan yalnızca manşet adayı olmaz. Burada yalnızca sizin elle durdurduğunuz olayların haberleri bekler.</p>
    <?php endif; ?>

    <?php foreach ($bekleyen as $h): ?>
        <?php $kayit = json_decode((string) ($h['karar_kaydi'] ?? ''), true) ?: []; ?>
        <div class="kutu" style="margin-bottom:12px;border-left:4px solid <?= $h['inceleme'] === 'acil' ? '#c62828' : '#c9a227' ?>;">
            <div style="display:flex;gap:10px;align-items:baseline;flex-wrap:wrap;">
                <a href="duzenle.php?id=<?= (int) $h['id'] ?>" style="font-weight:600;flex:1;min-width:240px;"><?= e((string) $h['baslik']) ?></a>
                <span class="rozet rozet-reddedildi"><?= e(['acil' => 'ACİL', 'bekliyor' => 'inceleme', 'durduruldu' => 'yayını durduruldu'][(string) $h['inceleme']] ?? (string) $h['inceleme']) ?></span>
                <span class="rozet rozet-skor"><?= e(ONCELIK_ADLAR[(int) $h['oncelik']]) ?></span>
                <span class="rozet rozet-skor"><?= e(DOGRULAMA_ADLAR[(string) $h['dogrulama']] ?? '—') ?></span>
                <span class="rozet rozet-skor"><?= e((string) $h['kaynak_adi']) ?></span>
            </div>
            <ul style="margin:8px 0;padding-left:18px;">
                <?php foreach ((array) ($kayit['gerekce'] ?? []) as $g): ?><li><?= e((string) $g) ?></li><?php endforeach; ?>
            </ul>
            <p class="ozet" style="margin:0 0 8px;">Yayımlamadan önce metni kaynakla karşılaştırın; doğrulanmamış can kaybı ya da ayrıntı varsa düzenleyin.</p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a class="dugme" href="duzenle.php?id=<?= (int) $h['id'] ?>">Metni aç</a>
                <?php if ((string) $h['kaynak_url'] !== ''): ?><a class="dugme" href="<?= e((string) $h['kaynak_url']) ?>" target="_blank" rel="noopener">Kaynağı aç ↗</a><?php endif; ?>
                <?php foreach (['onayla' => ['Doğruladım, yayımla', 'dugme-onay'], 'reddet' => ['Reddet', 'dugme-ret']] as $islem => [$ad, $sinif]): ?>
                    <form method="post" action="islem.php">
                        <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
                        <input type="hidden" name="islem" value="<?= e($islem) ?>">
                        <input type="hidden" name="donus" value="oncelik">
                        <button class="dugme <?= e($sinif) ?>" type="submit"><?= e($ad) ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php
    /*
     * SON DAKIKA BANDI: ONIZLEME / KARSILASTIRMA (13.11.2026). Solda sitede
     * su an "Son Dakika" adiyla kayan serit (en yeni haberler, kronolojik);
     * sagda yeni bant (acil + onemli + dogrulanmis + Turkiye). Yayina
     * alininca soldaki "Son Haberler" adini alir, sagdaki "Son Dakika" olur.
     */
    $bantHazir   = oncelik_bant_hazir();
    $bantYayinda = oncelik_bant_yayinda();
    $yeniBant    = $bantHazir ? oncelik_bant(6) : [];
    $eskiSerit   = haber_son_dakika_seridi();
    ?>
    <h2 id="bant">Son dakika bandı <span class="rozet <?= $bantYayinda ? 'rozet-yayinda' : 'rozet-taslak' ?>"><?= $bantYayinda ? 'yayında' : 'önizleme' ?></span></h2>
    <?php if (!$bantHazir): ?>
        <div class="uyari uyari-hata">Bant sütunları veritabanında yok. <a href="veritabani.php">Veritabanı</a> sayfasından şemayı güncelleyin.</div>
    <?php else: ?>
        <p class="ozet">Banda yalnızca <strong>acil</strong> (kritik: son önemli gelişmeden <?= (int) ONCELIK_BANT_KRITIK_SAAT ?> saat; yüksek: <?= (int) ONCELIK_BANT_YUKSEK_SAAT ?> saat),
        <strong>doğrulanmış</strong> (resmî açıklama ya da bağımsız kaynaklar) ve <strong>Türkiye'yi doğrudan ilgilendiren</strong> haberler girer; her olaydan tek haber.
        Kronolojik akış ayrı şeritte "Son Haberler" adıyla kalır. <?= $bantYayinda
            ? 'Bant sitede yayında.'
            : 'Şu an <strong>önizleme</strong>: sitede hiçbir şey değişmedi; aşağıda mevcut şerit ile yeni bandı karşılaştırın, uygun bulursanız yayına alın.' ?></p>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px;">
            <div class="kutu">
                <strong><?= $bantYayinda ? 'Son Haberler şeridi (kronolojik)' : 'Mevcut "Son Dakika" şeridi (kronolojik, en yeni haberler)' ?></strong>
                <ol style="margin:8px 0 0;padding-left:20px;">
                    <?php foreach ($eskiSerit as $s): ?><li><?= e($s['zaman']) ?> — <?= e($s['baslik']) ?></li><?php endforeach; ?>
                </ol>
            </div>
            <div class="kutu" style="border-left:4px solid #c62828;">
                <strong>Yeni "Son Dakika" bandı (<?= count($yeniBant) ?> haber)</strong>
                <?php if ($yeniBant === []): ?>
                    <p class="ozet" style="margin:8px 0 0;">Şu an koşulları tutan haber yok: bant sitede hiç görünmez (boş "son dakika" olmaz).</p>
                <?php endif; ?>
                <ol style="margin:8px 0 0;padding-left:20px;">
                    <?php foreach ($yeniBant as $s): ?>
                        <li><?= e((string) $s['zaman']) ?> — <a href="oncelik.php?id=<?= (int) $s['id'] ?>#h<?= (int) $s['id'] ?>"><?= e((string) $s['baslik']) ?></a>
                            <br><small><?= e(implode(' ', oncelik_bant_karari($s)['gerekce'])) ?></small></li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>

        <form method="post" action="islem.php" style="margin:10px 0 18px;"
              onsubmit="return confirm('Son dakika bandının sitedeki görünümü değişecek. Emin misiniz?');">
            <input type="hidden" name="csrf" value="<?= e(csrf_jeton()) ?>">
            <input type="hidden" name="islem" value="bant_modu">
            <input type="hidden" name="mod" value="<?= $bantYayinda ? 'onizleme' : 'yayin' ?>">
            <button class="dugme <?= $bantYayinda ? '' : 'dugme-ana' ?>" type="submit"><?= $bantYayinda ? 'Önizlemeye geri al (sitede eski şerit)' : 'Yeni bandı yayına al' ?></button>
        </form>
    <?php endif; ?>

    <?php if ($odakHaber !== null): ?>
        <h2>Seçilen haber</h2>
        <?php $kart($odakHaber, null); ?>
    <?php endif; ?>

    <h2>Ana sayfa sırası (ilk <?= count($sira) ?>)</h2>
    <?php foreach ($sira as $i => $h): ?>
        <?php $kart($h, $i + 1); ?>
    <?php endforeach; ?>

<?php endif; ?>

<?php require __DIR__ . '/alt.php'; ?>
