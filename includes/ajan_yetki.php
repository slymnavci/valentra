<?php
declare(strict_types=1);

/**
 * Ajan uclarinin ortak Bearer dogrulamasi.
 *
 * Anahtarin kendisi degil SHA-256 ozeti saklandigi icin karsilastirma
 * ozet uzerinden yapilir.
 */

/**
 * Istekteki anahtari bulur.
 *
 * Paylasimli hostingde Apache "Authorization" basligini PHP'ye
 * gecirmeyebilir; bu yuzden birden cok yere bakariz. Son care olarak
 * X-Valentra-Key basligi kullanilir: ozel basliklar her zaman gecer.
 */
function ajan_istek_anahtari(): string
{
    // 1) Ozel baslik - hicbir sunucu tarafindan silinmez
    $ozel = $_SERVER['HTTP_X_VALENTRA_KEY'] ?? '';

    if (is_string($ozel) && trim($ozel) !== '') {
        return trim($ozel);
    }

    // 2) Standart Authorization basligi (api/.htaccess bunu aktarir)
    $baslik = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

    if ((!is_string($baslik) || $baslik === '') && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $ad => $deger) {
            if (strcasecmp($ad, 'Authorization') === 0) {
                $baslik = $deger;
                break;
            }
        }
    }

    if (!is_string($baslik) || trim($baslik) === '') {
        return '';
    }

    if (preg_match('/^Bearer\s+(\S+)$/i', trim($baslik), $eslesme)) {
        return $eslesme[1];
    }

    return '';
}

/**
 * Gecerli anahtarin id'sini dondurur.
 *
 * Basarisizlikta neden bilgisini $neden'e yazar; boylece uc, anahtarin
 * hic gelmedigini mi yoksa eslesmedigini mi soyleyebilir. Ikisinin
 * cozumu farkli: biri sunucu yapilandirmasi, digeri yanlis secret.
 */
function ajan_anahtar_dogrula(?string &$neden = null): ?int
{
    $anahtar = ajan_istek_anahtari();

    if ($anahtar === '') {
        $neden = 'baslik_yok';

        return null;
    }

    $ifade = db()->prepare(
        'SELECT id FROM ajan_anahtarlari WHERE anahtar_hash = :hash AND aktif = 1 LIMIT 1'
    );
    $ifade->execute(['hash' => hash('sha256', $anahtar)]);
    $id = $ifade->fetchColumn();

    if ($id === false) {
        $neden = 'eslesmedi';

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

/**
 * 401 yanitini sebebe gore aciklar.
 */
function ajan_yetki_hatasi(?string $neden): array
{
    if ($neden === 'baslik_yok') {
        return [
            'hata'  => 'Anahtar isteğe ulaşmadı.',
            'detay' => 'Authorization başlığı sunucu tarafından iletilmemiş olabilir. '
                     . 'api/.htaccess dosyasının yüklendiğini doğrulayın; alternatif olarak '
                     . 'anahtarı X-Valentra-Key başlığıyla gönderin.',
        ];
    }

    $aktif = (int) db()->query('SELECT COUNT(*) FROM ajan_anahtarlari WHERE aktif = 1')->fetchColumn();

    return [
        'hata'  => 'Anahtar eşleşmedi.',
        'detay' => $aktif === 0
            ? 'Veritabanında hiç aktif ajan anahtarı yok. Yönetim panelinden '
              . '"Ajan anahtarları" sayfasında bir anahtar üretin ve VALENTRA_AGENT_KEY '
              . 'secret değerine yazın.'
            : $aktif . ' aktif anahtar var ama gönderilen bunlardan hiçbiriyle eşleşmiyor. '
              . 'Secret değerini panelde ürettiğiniz anahtarla karşılaştırın; '
              . 'kopyalarken boşluk kalmamış olmalı.',
    ];
}
