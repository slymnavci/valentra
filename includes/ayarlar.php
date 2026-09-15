<?php
declare(strict_types=1);

/**
 * Panelden girilen ayarlar (anahtar/değer).
 *
 * Kodda sabit tutulmaması gereken ve deploy'a bağlamak istemediğimiz
 * değerler burada durur: örneğin ajanı panelden tetiklemek için gereken
 * GitHub erişim anahtarı.
 */

function ayar_oku(string $anahtar, string $varsayilan = ''): string
{
    try {
        $ifade = db()->prepare('SELECT deger FROM ayarlar WHERE anahtar = :a LIMIT 1');
        $ifade->execute(['a' => $anahtar]);
        $deger = $ifade->fetchColumn();
    } catch (PDOException $e) {
        return $varsayilan;
    }

    return $deger === false ? $varsayilan : (string) $deger;
}

function ayar_yaz(string $anahtar, string $deger): void
{
    db()->prepare(
        'INSERT INTO ayarlar (anahtar, deger) VALUES (:a, :d)
         ON DUPLICATE KEY UPDATE deger = VALUES(deger)'
    )->execute(['a' => $anahtar, 'd' => $deger]);
}

function ayar_sil(string $anahtar): void
{
    db()->prepare('DELETE FROM ayarlar WHERE anahtar = :a')->execute(['a' => $anahtar]);
}

/**
 * Gizli bir değerin maskelenmiş hâli: son dört karakter dışında gizli.
 */
function ayar_maskele(string $deger): string
{
    $uzunluk = strlen($deger);

    if ($uzunluk === 0) {
        return '';
    }

    if ($uzunluk <= 8) {
        return str_repeat('•', $uzunluk);
    }

    return str_repeat('•', 12) . substr($deger, -4);
}
