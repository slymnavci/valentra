<?php
declare(strict_types=1);

/**
 * Spor sayfası: Süper Lig puan durumu, haftanın maçları ve fikstür (TFF).
 *
 * Site sahibinin istegi (22.10.2026): "spora girince puan durumu ve
 * fiksturu de ceksin, canli gorelim; TFF'den alinabilir".
 *
 * Kaynak TFF'nin "Puan Cetveli ve Fikstur" sayfasi (pageID=198). Ayni
 * sayfada uc bolum var ve ucu de burada okunuyor:
 *   - puan cetveli: class="s-table" tablosu (O G B M A Y AV P);
 *   - haftanin maclari: id'si "dtlHaftaninMaclari" ile biten tablo;
 *     tarih ve saat burada;
 *   - fikstur: class="fiksturListesiTable" icinde hafta hafta
 *     class="softBG" tablolari (ev | skor | deplasman; tarih yok).
 * Yapi kaynak testiyle (kaynak_dene --ham) goruldu.
 *
 * Sayfa Windows-1254 kodlu; UTF-8'e cevrilmeden okunursa "Ş" "Þ" olur.
 *
 * Veri 10 dakikada bir tazeleniyor (bkz. canli_onbellek). Sayfadaki betik
 * bolumu 3 dakikada bir yeniliyor; mac gunu skorlar boylece guncel kalir.
 */

require_once __DIR__ . '/canli_onbellek.php';

const TFF_SUPER_LIG_URL = 'https://www.tff.org/default.aspx?pageID=198';
const SPOR_ONBELLEK_SURE = 600;

/**
 * Süper Lig verisi (önbellekten).
 *
 * @return array{veri:?array{puan:list<array<string,mixed>>,hafta:?int,
 *               haftanin_maclari:list<array<string,string>>,
 *               fikstur:array<int,list<array<string,string>>>},zaman:int}
 */
function spor_super_lig(): array
{
    return canli_onbellek('tff_super_lig', SPOR_ONBELLEK_SURE, static function (): ?array {
        $html = canli_indir(TFF_SUPER_LIG_URL, 12);

        if ($html === null) {
            return null;
        }

        $veri = tff_sayfa_coz($html);

        // Puan cetveli yoksa sayfa duzeni degismis demektir: eski veri
        // kalsin, yanlis ya da bos tablo yazilmasin.
        return count($veri['puan']) >= 10 ? $veri : null;
    });
}

/**
 * TFF puan cetveli ve fikstür sayfasını çözümler.
 *
 * @return array{puan:list<array<string,mixed>>,hafta:?int,
 *               haftanin_maclari:list<array<string,string>>,
 *               fikstur:array<int,list<array<string,string>>>}
 */
function tff_sayfa_coz(string $html): array
{
    if (!mb_check_encoding($html, 'UTF-8')) {
        $cevrilmis = @iconv('CP1254', 'UTF-8//IGNORE', $html);
        $html      = $cevrilmis !== false ? $cevrilmis : mb_convert_encoding($html, 'UTF-8', 'ISO-8859-9');
    }

    // Sayfanin kendi charset bildirimi libxml'i yaniltmasin.
    $html = preg_replace('/<meta[^>]+charset[^>]*>/i', '', $html) ?? $html;

    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    $xp = new DOMXPath($dom);

    $metin = static fn (?DOMNode $n): string
        => $n === null ? '' : trim(preg_replace('/\s+/u', ' ', $n->textContent) ?? '');

    // --- puan cetveli ---------------------------------------------------
    $puan = [];

    foreach ($xp->query("//table[contains(concat(' ', normalize-space(@class), ' '), ' s-table ')]") ?: [] as $tablo) {
        $satirlar = [];

        foreach ($xp->query('.//tr', $tablo) ?: [] as $tr) {
            $hucreler = [];

            foreach ($tr->childNodes as $td) {
                if ($td instanceof DOMElement && $td->tagName === 'td') {
                    $hucreler[] = $metin($td);
                }
            }

            if (count($hucreler) < 9 || !preg_match('/^(\d+)\s*\.\s*(.+)$/u', $hucreler[0], $e)) {
                continue;
            }

            $sayilar = array_slice($hucreler, 1, 8);

            if (array_filter($sayilar, static fn (string $s): bool => !preg_match('/^-?\d+$/', $s)) !== []) {
                continue;
            }

            [$o, $g, $b, $m, $a, $y, $av, $p] = array_map('intval', $sayilar);

            $satirlar[] = [
                'sira' => (int) $e[1], 'takim' => spor_takim_adi($e[2]),
                'o' => $o, 'g' => $g, 'b' => $b, 'm' => $m,
                'a' => $a, 'y' => $y, 'av' => $av, 'p' => $p,
            ];
        }

        // Ilk dolu tablo genel cetvel; sonrakiler ic saha / dis saha.
        if (count($satirlar) >= 10) {
            $puan = $satirlar;
            break;
        }
    }

    // --- haftanin maclari ---------------------------------------------------
    $hafta = null;

    if (preg_match('/«\s*(\d+)\s*\.\s*Hafta/u', $metin($dom->documentElement), $e)) {
        $hafta = (int) $e[1];
    }

    $haftaninMaclari = [];

    foreach ($xp->query("//table[substring(@id, string-length(@id) - string-length('dtlHaftaninMaclari') + 1) = 'dtlHaftaninMaclari']//tr[contains(@class, 'haftaninMaclariTr')]") ?: [] as $tr) {
        $hucreler = [];

        foreach ($tr->childNodes as $td) {
            if ($td instanceof DOMElement && $td->tagName === 'td') {
                $hucreler[] = $metin($td);
            }
        }

        if (count($hucreler) < 4 || !preg_match('/(\d{2})\.(\d{2})\.(\d{4})(?:\s+(\d{2}:\d{2}))?/', $hucreler[0], $t)) {
            continue;
        }

        $haftaninMaclari[] = [
            'tarih' => $t[3] . '-' . $t[2] . '-' . $t[1],
            'saat'  => $t[4] ?? '',
            'ev'    => spor_takim_adi($hucreler[1]),
            'skor'  => spor_skor($hucreler[2]),
            'dep'   => spor_takim_adi($hucreler[3]),
        ];
    }

    // --- fikstur -------------------------------------------------------------
    $fikstur = [];

    foreach ($xp->query("//table[contains(@class, 'fiksturListesiTable')]//table[contains(@class, 'softBG')]") ?: [] as $tablo) {
        if (!preg_match('/^(\d+)\s*\.\s*Hafta/u', $metin($tablo), $e)) {
            continue;
        }

        $no = (int) $e[1];

        foreach ($xp->query('.//tr[count(td) = 3]', $tablo) ?: [] as $tr) {
            $hucreler = [];

            foreach ($tr->childNodes as $td) {
                if ($td instanceof DOMElement && $td->tagName === 'td') {
                    $hucreler[] = $metin($td);
                }
            }

            if (count($hucreler) !== 3 || $hucreler[0] === '' || $hucreler[2] === '') {
                continue;
            }

            $fikstur[$no][] = [
                'ev'   => spor_takim_adi($hucreler[0]),
                'skor' => spor_skor($hucreler[1]),
                'dep'  => spor_takim_adi($hucreler[2]),
            ];
        }
    }

    ksort($fikstur);

    return [
        'puan'             => $puan,
        'hafta'            => $hafta,
        'haftanin_maclari' => $haftaninMaclari,
        'fikstur'          => $fikstur,
    ];
}

/** "2 - 1" -> "2 - 1"; oynanmamış maç ("-" ya da boş) -> "". */
function spor_skor(string $ham): string
{
    return preg_match('/^(\d+)\s*-\s*(\d+)$/', trim($ham), $e) ? $e[1] . ' - ' . $e[2] : '';
}

/**
 * "GAZİANTEP FUTBOL KULÜBÜ A.Ş." -> "Gaziantep FK".
 *
 * TFF adlari resmi unvanla ve buyuk harfle yaziyor; tabloda okunmuyor ve
 * dar ekrana sigmiyor. Sirket eki atiliyor, Turkce kurala gore baslik
 * harfine ceviriliyor; kisaltmalar (FK, SK) buyuk kaliyor.
 */
function spor_takim_adi(string $ad): string
{
    $ad = trim(preg_replace('/\s+/u', ' ', $ad) ?? $ad);
    $ad = preg_replace('/\s+A\.\s*Ş\.?$/u', '', $ad) ?? $ad;
    $ad = preg_replace('/\bFUTBOL KULÜBÜ\b/u', 'FK', $ad) ?? $ad;
    $ad = preg_replace('/\bSPORTİF FAALİYETLER\b/u', 'SPORTİF', $ad) ?? $ad;

    $kelimeler = [];

    foreach (explode(' ', $ad) as $kelime) {
        if ($kelime === '' || in_array($kelime, ['FK', 'SK', 'JK', 'GSK', 'BB'], true)) {
            $kelimeler[] = $kelime;
            continue;
        }

        $kucuk = mb_strtolower(str_replace(['I', 'İ'], ['ı', 'i'], $kelime), 'UTF-8');
        $ilk   = mb_substr($kucuk, 0, 1, 'UTF-8');
        $ilk   = $ilk === 'i' ? 'İ' : ($ilk === 'ı' ? 'I' : mb_strtoupper($ilk, 'UTF-8'));

        $kelimeler[] = $ilk . mb_substr($kucuk, 1, null, 'UTF-8');
    }

    return implode(' ', $kelimeler);
}

/**
 * Son oynanan hafta: fikstürde skoru olan en büyük hafta numarası.
 *
 * @param array<int,list<array<string,string>>> $fikstur
 */
function spor_son_oynanan_hafta(array $fikstur): ?int
{
    $son = null;

    foreach ($fikstur as $no => $maclar) {
        foreach ($maclar as $mac) {
            if ($mac['skor'] !== '') {
                $son = $no;
                break;
            }
        }
    }

    return $son;
}

/** "2026-10-23" -> "23 Eki Cum" */
function spor_tarih(string $tarih): string
{
    $t = strtotime($tarih);

    if ($t === false) {
        return $tarih;
    }

    $aylar   = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
    $gunler  = ['Paz', 'Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt'];

    return (int) date('j', $t) . ' ' . $aylar[(int) date('n', $t) - 1] . ' ' . $gunler[(int) date('w', $t)];
}
