<?php
/**
 * Forum konu açma/mesaj yazma, yönetim işlemleri gibi uygulama anahtarı
 * gerektirmez (bkz. giris_dogrula.php'deki aynı gerekçe: sıradan bir üye
 * uygulama anahtarını bilemez). Bunun yerine, gönderilen kullanici_adi'nin
 * gerçekten kayıtlı bir hesap olduğu doğrulanır — hafif ama üye olmayanın
 * konu/mesaj oluşturmasını engelleyen bir kontrol.
 */
function ppUyeDogrula(PDO $pdo, string $kullaniciAdi): bool {
    if ($kullaniciAdi === '') return false;
    $sorgu = $pdo->prepare('SELECT 1 FROM kullanici_hesap WHERE kullanici_adi = ?');
    $sorgu->execute([$kullaniciAdi]);
    return (bool)$sorgu->fetch();
}
