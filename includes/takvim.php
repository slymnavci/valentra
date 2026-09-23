<?php
declare(strict_types=1);

/**
 * Vergi takvimi: yaklaşan beyan ve ödeme tarihleri.
 *
 * Tarihler tek tek saklanmiyor; kural saklaniyor ("her ayin 26'si")
 * ve yaklasan tarihler o kuraldan hesaplaniyor. Boylece liste her yil
 * elle yenilenmek zorunda kalmiyor.
 */

/**
 * Önümüzdeki günlerde gelen yükümlülükler.
 *
 * @param int $gunSayisi Kac gun ilerisine bakilacak
 * @param int $limit     En fazla kac satir
 * @return list<array{baslik:string,aciklama:string,tarih:string,kalan:int,kaynak_url:string}>
 */
function takvim_yaklasanlar(int $gunSayisi = 45, int $limit = 5): array
{
    try {
        $satirlar = db()->query(
            'SELECT baslik, aciklama, tekrar, gun, aylar, kaynak_url
               FROM vergi_takvimi
              WHERE aktif = 1
              ORDER BY sira'
        )->fetchAll();
    } catch (PDOException $e) {
        // Tablo henuz olusmamis olabilir; takvim yan pencerede bir
        // eklenti, sayfayi dusurmemeli.
        return [];
    }

    $bugun   = new DateTimeImmutable('today');
    $sinir   = $bugun->modify('+' . max(1, $gunSayisi) . ' days');
    $sonuc   = [];

    foreach ($satirlar as $satir) {
        $tarih = takvim_sonraki_tarih($satir, $bugun);

        if ($tarih === null || $tarih > $sinir) {
            continue;
        }

        $sonuc[] = [
            'baslik'     => (string) $satir['baslik'],
            'aciklama'   => (string) $satir['aciklama'],
            'tarih'      => $tarih->format('Y-m-d'),
            'kalan'      => (int) $bugun->diff($tarih)->days,
            'kaynak_url' => (string) $satir['kaynak_url'],
        ];
    }

    // Sirala: en yakin tarih basta. Ayni gune denk gelenlerde
    // schema'daki sira korunuyor (usort kararli degil, o yuzden
    // ikincil olcut olarak basligi kullaniyoruz).
    usort($sonuc, static function (array $a, array $b): int {
        return [$a['tarih'], $a['baslik']] <=> [$b['tarih'], $b['baslik']];
    });

    return array_slice($sonuc, 0, $limit);
}

/**
 * Bir kuralın bugünden sonraki ilk tarihi.
 *
 * ONEMLI: ayin 30 ya da 31'i gibi bir gun, o ayda yoksa AYIN SON GUNU
 * kullaniliyor. Subatta "30" yazan bir kural aksi halde 2 Mart'a
 * tasardi ve yanlis tarih gosterirdi.
 *
 * @param array<string,mixed> $kural
 */
function takvim_sonraki_tarih(array $kural, DateTimeImmutable $bugun): ?DateTimeImmutable
{
    $gun   = max(1, min(31, (int) $kural['gun']));
    $aylar = [];

    if ((string) $kural['tekrar'] === 'secili') {
        foreach (explode(',', (string) $kural['aylar']) as $parca) {
            $ay = (int) trim($parca);

            if ($ay >= 1 && $ay <= 12) {
                $aylar[] = $ay;
            }
        }

        if ($aylar === []) {
            return null;
        }
    }

    // On dort ay ileriye bakmak yilda bir tekrar eden kurallar icin de
    // yeterli; bir sonraki yilin ayni ayina ulasiyor.
    for ($ileri = 0; $ileri <= 14; $ileri++) {
        $ayBasi = $bugun->modify('first day of this month')->modify('+' . $ileri . ' months');

        if ($aylar !== [] && !in_array((int) $ayBasi->format('n'), $aylar, true)) {
            continue;
        }

        $sonGun = (int) $ayBasi->format('t');
        $aday   = $ayBasi->setDate(
            (int) $ayBasi->format('Y'),
            (int) $ayBasi->format('n'),
            min($gun, $sonGun)
        );

        if ($aday >= $bugun) {
            return $aday;
        }
    }

    return null;
}

/**
 * Bir yılın tamamını ay ay döndürür.
 *
 * Yaklasan tarihler yan pencere icin; bu ise takvim SAYFASI icin.
 * Fark: burada gecmis aylar da var, cunku okuyucu "gecen ay neyi
 * kacirdim" diye de bakiyor.
 *
 * @return array<int,list<array{baslik:string,aciklama:string,gun:int,kaynak_url:string}>>
 *         Ay numarasi (1-12) => o aydaki yukumlulukler, gune gore sirali
 */
function takvim_yili(int $yil): array
{
    try {
        $satirlar = db()->query(
            'SELECT baslik, aciklama, tekrar, gun, aylar, kaynak_url
               FROM vergi_takvimi
              WHERE aktif = 1
              ORDER BY sira'
        )->fetchAll();
    } catch (PDOException $e) {
        return [];
    }

    $yillik = array_fill(1, 12, []);

    foreach ($satirlar as $satir) {
        $secili = [];

        if ((string) $satir['tekrar'] === 'secili') {
            foreach (explode(',', (string) $satir['aylar']) as $parca) {
                $ay = (int) trim($parca);

                if ($ay >= 1 && $ay <= 12) {
                    $secili[] = $ay;
                }
            }

            // Ay listesi bos ya da bozuksa kural hic uygulanmaz; her aya
            // yaymak sessizce yanlis takvim uretirdi.
            if ($secili === []) {
                continue;
            }
        }

        for ($ay = 1; $ay <= 12; $ay++) {
            if ($secili !== [] && !in_array($ay, $secili, true)) {
                continue;
            }

            /*
             * Ayda olmayan gun ayin son gunune cekiliyor — yaklasan
             * tarih hesabiyla ayni kural. Ikisi ayrilirsa yan pencere
             * ile takvim sayfasi farkli tarih gosterir.
             */
            $sonGun = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $yil, $ay)))->format('t');

            $yillik[$ay][] = [
                'baslik'     => (string) $satir['baslik'],
                'aciklama'   => (string) $satir['aciklama'],
                'gun'        => min(max(1, (int) $satir['gun']), $sonGun),
                'kaynak_url' => (string) $satir['kaynak_url'],
            ];
        }
    }

    foreach ($yillik as $ay => $olaylar) {
        usort($olaylar, static fn (array $a, array $b): int => [$a['gun'], $a['baslik']] <=> [$b['gun'], $b['baslik']]);
        $yillik[$ay] = $olaylar;
    }

    return $yillik;
}

/** Ay numarasından tam Türkçe ay adı. */
function takvim_ay_adi(int $ay): string
{
    $adlar = [
        1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan',
        5 => 'Mayıs', 6 => 'Haziran', 7 => 'Temmuz', 8 => 'Ağustos',
        9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık',
    ];

    return $adlar[$ay] ?? '';
}
