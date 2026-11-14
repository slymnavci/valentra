<?php
declare(strict_types=1);

/**
 * Sayfaya ozel stil paketleri (10.11.2026).
 *
 * assets/style.css 246 KB'ti ve her sayfa tamamini indirip isliyordu; ana
 * sayfa bunun ~%13'unu kullaniyor (Lighthouse mobil FCP 2,9 sn, LCP 3,2 sn).
 * Yalnizca belirli sayfalarda kullanilan kurallar ayri dosyalara tasindi:
 *
 *   style.css            ortak: ana sayfa ve haber sayfasinin kullanabilecegi
 *                        her kural + birden cok grupta gecenler (148 KB)
 *   stil-ek.css          ana sayfa ve haber DISINDAKI butun sayfalar
 *   stil-network.css     V Network ve uyelik
 *   stil-bolumler.css    ValPapel/ValSpor/ValMagazin, piyasalar, sirket, canli,
 *                        mac ozetleri, burclar, depremler
 *   stil-bilgi.css       kanunlar, pratik bilgiler, takvim, Resmi Gazete,
 *                        araclar, rehberler
 *   stil-sayfalar.css    kategori, konu, arama, etiket, yazar, kose yazilari,
 *                        video, kurumsal, bulten, gunun kelimesi
 *
 * Paketler style.css'ten SONRA yuklenir. Bolme betigi (.github/stil/bol.py)
 * bir sinifin hangi sayfalarda uretilebilecegini koddan (islev cagri zinciri)
 * cikariyor ve sira degisince sonucu degisebilecek kurallari ortakta birakiyor.
 *
 * YENI KURAL: style.css'e yazin; her sayfada yuklenir, her zaman dogru.
 * PAKETTEKI BIR SINIFI BASKA SAYFADA KULLANACAKSANIZ: kurali o paketten
 * style.css'e tasiyin (ya da betigi yeniden calistirin); yoksa o sayfada
 * stilsiz kalir. Deploy'daki "Stil paketleri denetimi" adimi bunu uyarir.
 *
 * Listede olmayan sayfa (yeni eklenen): butun paketler yuklenir (guvenli taraf).
 */

const STIL_PAKETLERI = ['ek', 'network', 'bolumler', 'bilgi', 'sayfalar'];

/** Sayfa betigi => paketler (yukleme sirasiyla). index.php ve haber.php: yok. */
const STIL_SAYFA_PAKETLERI = [
    'index.php'              => [],
    'haber.php'              => [],
    'ara.php'                => ['ek', 'sayfalar'],
    'araclar.php'            => ['ek', 'bilgi'],
    'bulten.php'             => ['ek', 'sayfalar'],
    'burc.php'               => ['ek', 'bolumler'],
    'burclar.php'            => ['ek', 'bolumler'],
    'canli.php'              => ['ek', 'bolumler'],
    'deprem.php'             => ['ek', 'bolumler'],
    'etiket.php'             => ['ek', 'sayfalar'],
    'gazete.php'             => ['ek', 'bolumler'],
    'gunun-kelimesi.php'     => ['ek', 'sayfalar'],
    'kanun.php'              => ['ek', 'bilgi'],
    'kanunlar.php'           => ['ek', 'bilgi'],
    'kategori.php'           => ['ek', 'sayfalar'],
    // Kim ne yazdi (13.11.2026): kendi kurallari style.css'te.
    'kim-ne-yazdi.php'       => ['ek', 'sayfalar'],
    'konu.php'               => ['ek', 'sayfalar'],
    'kose-yazilari.php'      => ['ek', 'sayfalar'],
    'kose-yazisi.php'        => ['ek', 'sayfalar'],
    'kurumsal.php'           => ['ek', 'sayfalar'],
    'mac-ozetleri.php'       => ['ek', 'bolumler'],
    'network.php'            => ['ek', 'network'],
    'piyasalar.php'          => ['ek', 'bolumler'],
    'pratik-bilgi.php'       => ['ek', 'bilgi'],
    'pratik-bilgiler.php'    => ['ek', 'bilgi'],
    'rehber.php'             => ['ek', 'bilgi'],
    'rehberler.php'          => ['ek', 'bilgi'],
    'resmi-gazete.php'       => ['ek', 'bilgi'],
    'sirket.php'             => ['ek', 'bolumler'],
    'uye-eposta.php'         => ['ek', 'network'],
    'uye.php'                => ['ek', 'network'],
    'vergi-takvimi.php'      => ['ek', 'bilgi'],
    'video.php'              => ['ek', 'sayfalar'],
    'yazar.php'              => ['ek', 'sayfalar'],
];

/**
 * Ortak stil dosyasi (14.11.2026, hiz): V Network sayfalari style.css'in
 * tamami yerine ince kopyasini yukler (assets/stil-temel-network.css;
 * .github/stil/temel.py uretir, deploy her yuklemede yeniler). Kopya yoksa
 * style.css (guvenli taraf).
 */
const STIL_TEMEL_NETWORK = ['network.php', 'uye.php', 'uye-eposta.php'];

function stil_temel(?string $betik = null): string
{
    $betik ??= basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    return in_array($betik, STIL_TEMEL_NETWORK, true) && is_file(__DIR__ . '/../assets/stil-temel-network.css')
        ? '/assets/stil-temel-network.css' : '/assets/style.css';
}

/**
 * Bu istekte style.css'ten sonra yuklenecek paket dosyalari.
 *
 * @return list<string> /assets/stil-*.css yollari
 */
function stil_paketleri(?string $betik = null): array
{
    $betik ??= basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    return array_map(
        static fn (string $ad): string => '/assets/stil-' . $ad . '.css',
        STIL_SAYFA_PAKETLERI[$betik] ?? STIL_PAKETLERI
    );
}
