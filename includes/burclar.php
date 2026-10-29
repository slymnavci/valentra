<?php
declare(strict_types=1);

/**
 * Günlük burç yorumları.
 *
 * Site sahibinin istegi (29.10.2026): "burc ve yorumlarini gunluk
 * yapabilir miyiz? Ana baslik yapalim; her gun bunlari okuyanlar
 * cikabilir, bu okuyucu kitlesini de cekelim; yorumlari yapay zeka
 * yapsin". Yorumlari ajan her sabah 12 burc icin yaziyor (ajan/burc.php)
 * ve api/burc.php ile gonderiyor; site burada sakliyor ve gosteriyor.
 * Sayfalarda "eglence amaclidir, yapay zeka ile hazirlanir" notu var.
 */

require_once __DIR__ . '/ayarlar.php';

/**
 * Burçlar, takvim sırasıyla: anahtar => [ad, sembol, tarih aralığı, element].
 * Sembolun sonundaki U+FE0E: telefonlar ve tarayicilar burc isaretini
 * renkli emoji olarak cizmesin, yazi karakteri olarak sitenin renginde.
 */
const BURCLAR = [
    'koc'     => ['Koç',     "♈\u{FE0E}", '21 Mart - 19 Nisan',      'Ateş'],
    'boga'    => ['Boğa',    "♉\u{FE0E}", '20 Nisan - 20 Mayıs',     'Toprak'],
    'ikizler' => ['İkizler', "♊\u{FE0E}", '21 Mayıs - 20 Haziran',   'Hava'],
    'yengec'  => ['Yengeç',  "♋\u{FE0E}", '21 Haziran - 22 Temmuz',  'Su'],
    'aslan'   => ['Aslan',   "♌\u{FE0E}", '23 Temmuz - 22 Ağustos',  'Ateş'],
    'basak'   => ['Başak',   "♍\u{FE0E}", '23 Ağustos - 22 Eylül',   'Toprak'],
    'terazi'  => ['Terazi',  "♎\u{FE0E}", '23 Eylül - 22 Ekim',      'Hava'],
    'akrep'   => ['Akrep',   "♏\u{FE0E}", '23 Ekim - 21 Kasım',      'Su'],
    'yay'     => ['Yay',     "♐\u{FE0E}", '22 Kasım - 21 Aralık',    'Ateş'],
    'oglak'   => ['Oğlak',   "♑\u{FE0E}", '22 Aralık - 19 Ocak',     'Toprak'],
    'kova'    => ['Kova',    "♒\u{FE0E}", '20 Ocak - 18 Şubat',      'Hava'],
    'balik'   => ['Balık',   "♓\u{FE0E}", '19 Şubat - 20 Mart',      'Su'],
];

function burclar_yolu(): string
{
    return temiz_adres_acik() ? '/burclar' : '/burclar.php';
}

function burc_yolu(string $burc, string $gun = ''): string
{
    $yol = temiz_adres_acik() ? '/burc/' . rawurlencode($burc) : '/burc.php?b=' . rawurlencode($burc);

    return $gun === '' ? $yol : $yol . (str_contains($yol, '?') ? '&' : '?') . 'gun=' . rawurlencode($gun);
}

/** Bugün (İstanbul) "Y-m-d". */
function burc_bugun(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul')))->format('Y-m-d');
}

/** Yorumu olan en yeni gün; hiç yoksa null. */
function burc_son_gun(): ?string
{
    try {
        $gun = db()->query('SELECT MAX(gun) FROM burc_yorumlari')->fetchColumn();
    } catch (PDOException $e) {
        return null;   // tablo henuz yok
    }

    return is_string($gun) && $gun !== '' ? $gun : null;
}

/**
 * Bir günün yorumları: burç => satır (takvim sırasıyla).
 *
 * @return array<string,array<string,mixed>>
 */
function burc_gun_yorumlari(string $gun): array
{
    try {
        $ifade = db()->prepare('SELECT * FROM burc_yorumlari WHERE gun = :g');
        $ifade->execute(['g' => $gun]);
        $satirlar = $ifade->fetchAll();
    } catch (PDOException $e) {
        return [];
    }

    $sonuc = [];

    foreach (array_keys(BURCLAR) as $b) {
        foreach ($satirlar as $s) {
            if ($s['burc'] === $b) {
                $sonuc[$b] = $s;
            }
        }
    }

    return $sonuc;
}

/** Bir burcun son günlerdeki yorumları (arşiv), en yenisi önce. */
function burc_gecmis(string $burc, int $limit = 7): array
{
    try {
        $ifade = db()->prepare('SELECT gun, baslik FROM burc_yorumlari WHERE burc = :b ORDER BY gun DESC LIMIT ' . max(1, $limit));
        $ifade->execute(['b' => $burc]);

        return $ifade->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Ajanın gönderdiği bir günün yorumlarını kaydeder (varsa günceller).
 *
 * @param list<array<string,mixed>> $yorumlar
 * @return array{kaydedilen:int,hatalar:list<string>}
 */
function burc_kaydet(string $gun, array $yorumlar): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $gun)) {
        throw new InvalidArgumentException('Gün Y-m-d biçiminde olmalı.');
    }

    $kesik = static fn (string $s, int $n): string => mb_substr(trim($s), 0, $n, 'UTF-8');
    $ifade = db()->prepare(
        'INSERT INTO burc_yorumlari (gun, burc, baslik, genel, ask, para, saglik, sans_sayisi, sans_rengi)
         VALUES (:gun, :burc, :baslik, :genel, :ask, :para, :saglik, :sayi, :renk)
         ON DUPLICATE KEY UPDATE baslik = VALUES(baslik), genel = VALUES(genel), ask = VALUES(ask),
             para = VALUES(para), saglik = VALUES(saglik), sans_sayisi = VALUES(sans_sayisi), sans_rengi = VALUES(sans_rengi)'
    );

    $kaydedilen = 0;
    $hatalar    = [];

    foreach ($yorumlar as $y) {
        $burc  = (string) ($y['burc'] ?? '');
        $genel = trim((string) ($y['genel'] ?? ''));

        if (!isset(BURCLAR[$burc])) {
            $hatalar[] = 'Bilinmeyen burç: ' . $burc;
            continue;
        }

        if (mb_strlen($genel, 'UTF-8') < 120) {
            $hatalar[] = $burc . ': genel yorum çok kısa.';
            continue;
        }

        $sayi = (int) ($y['sans_sayisi'] ?? 0);

        $ifade->execute([
            'gun'    => $gun,
            'burc'   => $burc,
            'baslik' => $kesik((string) ($y['baslik'] ?? ''), 200),
            'genel'  => $kesik($genel, 3000),
            'ask'    => $kesik((string) ($y['ask'] ?? ''), 2000),
            'para'   => $kesik((string) ($y['para'] ?? ''), 2000),
            'saglik' => $kesik((string) ($y['saglik'] ?? ''), 2000),
            'sayi'   => $sayi >= 1 && $sayi <= 99 ? $sayi : null,
            'renk'   => $kesik((string) ($y['sans_rengi'] ?? ''), 40),
        ]);
        $kaydedilen++;
    }

    return ['kaydedilen' => $kaydedilen, 'hatalar' => $hatalar];
}

/** "29 Ekim 2026 Perşembe" */
function burc_tarih(string $gun): string
{
    $aylar  = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    $gunler = ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];
    $t      = new DateTimeImmutable($gun);

    return (int) $t->format('j') . ' ' . $aylar[(int) $t->format('n') - 1] . ' ' . $t->format('Y') . ' ' . $gunler[(int) $t->format('N') - 1];
}

/** Sayfalarda yorumların altındaki not. */
const BURC_NOTU = 'Burç yorumları eğlence amaçlıdır ve yapay zekâ ile hazırlanır; '
    . 'sağlık, para ve hukuki konularda karar vermeden önce bir uzmana danışın.';
