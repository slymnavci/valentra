<?php
declare(strict_types=1);

/**
 * V Network bildirimleri (31.10.2026, site sahibinin secimi: "biri seni
 * takip ettiginde, paylasimini begendiginde, yorum yaptiginda ust bantta
 * zil ve okunmamis sayisi; bildirimler sayfasi").
 *
 * Bildirim yalnizca baskasinin yaptigi isten: kendi paylasimini begenen ya
 * da yorumlayan uyeye bildirim gitmiyor. Begeni ve takip geri alininca
 * bildirimi de siliniyor; ayni kisinin ayni ise ikinci bildirimi
 * olusmuyor (begen-vazgec-begen tekrar zil caldirmasin diye once siliniyor).
 * Bildirim yazilamazsa (tablo henuz yoksa) asil islem yine tamamlaniyor.
 */

function bildirim_ekle(int $uyeId, int $yapanId, string $tur, ?int $gonderiId = null, ?int $yorumId = null): void
{
    if ($uyeId === $yapanId || $uyeId <= 0) {
        return;
    }

    try {
        if ($tur !== 'yorum') {
            bildirim_sil($uyeId, $yapanId, $tur, $gonderiId);
        }

        db()->prepare('INSERT INTO bildirimler (uye_id, yapan_id, tur, gonderi_id, yorum_id) VALUES (:u, :y, :t, :g, :c)')
            ->execute(['u' => $uyeId, 'y' => $yapanId, 't' => $tur, 'g' => $gonderiId, 'c' => $yorumId]);
    } catch (PDOException $e) {
        error_log('[valentra] bildirim yazilamadi: ' . $e->getMessage());
    }
}

function bildirim_sil(int $uyeId, int $yapanId, string $tur, ?int $gonderiId = null): void
{
    try {
        db()->prepare('DELETE FROM bildirimler WHERE uye_id = :u AND yapan_id = :y AND tur = :t AND gonderi_id <=> :g')
            ->execute(['u' => $uyeId, 'y' => $yapanId, 't' => $tur, 'g' => $gonderiId]);
    } catch (PDOException $e) {
        // Tablo yoksa bildirim de yok.
    }
}

/** Paylasimin sahibi (bildirimin alicisi); paylasim yoksa 0. */
function bildirim_gonderi_sahibi(int $gonderiId): int
{
    $ifade = db()->prepare('SELECT uye_id FROM gonderiler WHERE id = :id');
    $ifade->execute(['id' => $gonderiId]);

    return (int) $ifade->fetchColumn();
}

function bildirim_okunmamis(int $uyeId): int
{
    try {
        $ifade = db()->prepare('SELECT COUNT(*) FROM bildirimler WHERE uye_id = :u AND okundu = 0');
        $ifade->execute(['u' => $uyeId]);

        return (int) $ifade->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Son bildirimler (en yeni ustte), yapanin adi ve resmiyle; yorumda yorumun
 * ilk satiri. Okunmamislar ayri isaretli doner, sonra hepsi okundu yapilir.
 *
 * @return list<array<string,mixed>>
 */
function bildirim_listesi(int $uyeId, int $limit = 100): array
{
    try {
        $ifade = db()->prepare(
            "SELECT b.id, b.tur, b.gonderi_id, b.yorum_id, b.okundu, b.olusturuldu,
                    u.id AS yapan_id, u.ad, u.foto, u.tur, u.rol, y.metin AS yorum_metni, g.metin AS gonderi_metni
               FROM bildirimler b
               JOIN uyeler u ON u.id = b.yapan_id AND u.durum = 'aktif'
               LEFT JOIN yorumlar y ON y.id = b.yorum_id
               LEFT JOIN gonderiler g ON g.id = b.gonderi_id
              WHERE b.uye_id = :u
              ORDER BY b.id DESC LIMIT " . max(1, min(300, $limit))
        );
        $ifade->execute(['u' => $uyeId]);
        $liste = $ifade->fetchAll();

        db()->prepare('UPDATE bildirimler SET okundu = 1 WHERE uye_id = :u AND okundu = 0')->execute(['u' => $uyeId]);

        // 90 gunden eski okunmus bildirimler ara sira temizleniyor.
        if (random_int(1, 50) === 1) {
            db()->exec('DELETE FROM bildirimler WHERE okundu = 1 AND olusturuldu < NOW() - INTERVAL 90 DAY');
        }

        return $liste;
    } catch (PDOException $e) {
        return [];
    }
}
