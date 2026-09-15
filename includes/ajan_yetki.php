<?php
declare(strict_types=1);

/**
 * Ajan uclarinin ortak Bearer dogrulamasi.
 *
 * Anahtarin kendisi degil SHA-256 ozeti saklandigi icin karsilastirma
 * ozet uzerinden yapilir.
 */

/** Authorization basligini sunucu farkliliklarina ragmen bulur. */
function ajan_yetki_basligi(): string
{
    $baslik = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

    if ($baslik === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $ad => $deger) {
            if (strcasecmp($ad, 'Authorization') === 0) {
                $baslik = $deger;
                break;
            }
        }
    }

    return is_string($baslik) ? $baslik : '';
}

/**
 * Gecerli anahtarin id'sini dondurur, gecersizse null.
 */
function ajan_anahtar_dogrula(): ?int
{
    $baslik = trim(ajan_yetki_basligi());

    if (!preg_match('/^Bearer\s+(\S+)$/i', $baslik, $eslesme)) {
        return null;
    }

    $ifade = db()->prepare(
        'SELECT id FROM ajan_anahtarlari WHERE anahtar_hash = :hash AND aktif = 1 LIMIT 1'
    );
    $ifade->execute(['hash' => hash('sha256', $eslesme[1])]);
    $id = $ifade->fetchColumn();

    if ($id === false) {
        return null;
    }

    db()->prepare('UPDATE ajan_anahtarlari SET son_kullanim = NOW() WHERE id = :id')
        ->execute(['id' => $id]);

    return (int) $id;
}

/** JSON yanit verip cikar. */
function ajan_json(int $kod, array $govde): void
{
    http_response_code($kod);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($govde, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
