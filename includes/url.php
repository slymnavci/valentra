<?php
declare(strict_types=1);

/**
 * URL yardımcıları.
 *
 * Hem site hem ajan kullanır; tek tanım bulunsun diye ayrı dosyada.
 */

if (!function_exists('guvenli_url')) {
    /**
     * Yalnızca http/https adreslerine izin verir.
     *
     * Ajan dışarıdan URL gönderdiği için şema doğrulaması şart:
     * htmlspecialchars "javascript:" adresini zararsız hale getirmez,
     * href içinde tıklandığında yine çalışır.
     */
    function guvenli_url(?string $url): string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return '';
        }

        $sema = parse_url($url, PHP_URL_SCHEME);

        if (!is_string($sema) || !in_array(strtolower($sema), ['http', 'https'], true)) {
            return '';
        }

        if (parse_url($url, PHP_URL_HOST) === null) {
            return '';
        }

        return $url;
    }
}

if (!function_exists('besleme_url_birlestir')) {
    /**
     * Göreli adresi mutlak adrese çevirir.
     */
    function besleme_url_birlestir(string $taban, string $goreli): string
    {
        $goreli = trim($goreli);

        if (preg_match('#^https?://#i', $goreli) === 1) {
            return $goreli;
        }

        // Semasi olan ama http/https olmayan adres goreli DEGILDIR.
        // Reddedilmezse "javascript:alert(1)" tabanla birlestirilip
        // "https://site/javascript:alert(1)" gibi ise yaramaz bir adrese
        // donusuyor ve sema denetiminden gecip kirik <img> uretiyordu.
        // ("//host/yol" bicimi semasiz mutlak adres, o asagida ele aliniyor.)
        if (!str_starts_with($goreli, '//')
            && preg_match('#^[a-z][a-z0-9+.\-]*:#i', $goreli) === 1) {
            return '';
        }

        $parca = parse_url($taban);

        if (!isset($parca['scheme'], $parca['host'])) {
            return '';
        }

        $kok = $parca['scheme'] . '://' . $parca['host']
             . (isset($parca['port']) ? ':' . $parca['port'] : '');

        if (str_starts_with($goreli, '//')) {
            return $parca['scheme'] . ':' . $goreli;
        }

        if (str_starts_with($goreli, '/')) {
            return $kok . $goreli;
        }

        $yol = $parca['path'] ?? '/';
        $dizin = substr($yol, 0, (int) strrpos($yol, '/') + 1);

        return $kok . $dizin . $goreli;
    }
}
