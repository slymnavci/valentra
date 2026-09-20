<?php
declare(strict_types=1);

/**
 * Haber veri katmani.
 *
 * Akis: ajan 'taslak' olarak yazar -> yonetici onaylar -> 'yayinda' olur ve
 * ana sayfada gorunur. Yayinda olmayan hicbir kayit herkese acik sayfalarda
 * sorgulanmaz.
 */

const HABER_TASLAK      = 'taslak';
const HABER_YAYINDA     = 'yayinda';
const HABER_REDDEDILDI  = 'reddedildi';

/**
 * Ayni haberin iki kez girmesini engelleyen parmak izi.
 * Kaynak URL varsa onu, yoksa basligi esas alir.
 *
 * ESKI ALAN. Yeni kayitlarda hala dolduruluyor ki once eklenmis
 * haberlerin parmak izleriyle karsilastirma bozulmasin, ama kopya
 * denetimi artik haber_url_parmak() ve haber_baslik_parmak()
 * uzerinden yapiliyor. Bu hesap ham adresi oldugu gibi kullandigi
 * icin "?utm_source=..." eklenmis ya da sonuna egik cizgi gelmis
 * ayni haberi FARKLI sayiyordu.
 */
function haber_parmak_izi(string $kaynakUrl, string $baslik): string
{
    $temel = $kaynakUrl !== ''
        ? mb_strtolower(trim($kaynakUrl), 'UTF-8')
        : 'baslik:' . mb_strtolower(trim(preg_replace('/\s+/u', ' ', $baslik) ?? ''), 'UTF-8');

    return hash('sha256', $temel);
}

/**
 * Adresi, aynı haberin farklı yazımlarını tek biçime indirger.
 *
 * Ayni haber her calismada yeniden geliyordu cunku adres her seferinde
 * birazcik farkliydi. Gorulen farklar:
 *   - izleme parametreleri (utm_*, fbclid, gclid, ref, amp)
 *   - http/https ve www olan/olmayan yazim
 *   - sonda egik cizgi olan/olmayan yazim
 *   - "#icerik" gibi capalar
 *
 * Hicbiri farkli bir haber demek degil; hepsi ayni sayfa. Bu yuzden
 * parmak izi ham adresten degil, sadelestirilmis adresten hesaplaniyor.
 *
 * Kalan parametreler siralaniyor: "?a=1&b=2" ile "?b=2&a=1" ayni sayfa.
 */
function haber_url_sadelestir(string $url): string
{
    $url = trim($url);

    if ($url === '') {
        return '';
    }

    $parca = parse_url($url);

    if ($parca === false || !isset($parca['host'])) {
        return mb_strtolower($url, 'UTF-8');
    }

    $sunucu = strtolower($parca['host']);
    $sunucu = preg_replace('/^www\./', '', $sunucu) ?? $sunucu;

    $yol = rtrim((string) ($parca['path'] ?? ''), '/');

    $sorgu = '';

    if (isset($parca['query']) && $parca['query'] !== '') {
        parse_str($parca['query'], $parametreler);

        foreach (array_keys($parametreler) as $ad) {
            $kucuk = strtolower((string) $ad);

            /*
             * Izleme parametreleri atiliyor. Bunlar sayfayi degil,
             * ziyaretcinin nereden geldigini anlatir; iceride ayni
             * haber durur.
             */
            if (str_starts_with($kucuk, 'utm_')
                || in_array($kucuk, ['fbclid', 'gclid', 'yclid', 'mc_cid', 'mc_eid',
                                     'ref', 'referrer', 'amp', 'source', 'src',
                                     'sessionid', 'phpsessid'], true)) {
                unset($parametreler[$ad]);
            }
        }

        ksort($parametreler);
        $sorgu = http_build_query($parametreler);
    }

    // Semayi atiyoruz: http ve https ayni sayfayi gosterir.
    return $sunucu . $yol . ($sorgu !== '' ? '?' . $sorgu : '');
}

/**
 * Başlığı karşılaştırmaya uygun biçime indirger.
 *
 * Turkce katlama sart: mb_strtolower("İ") "i" + birlesen nokta
 * uretiyor ve ayni baslik iki farkli dizgeye donusebiliyor. Noktalama
 * ve fazla bosluk da atiliyor; kaynaklar ayni basligi tirnak, tire ve
 * bosluk farklariyla yaziyor.
 */
function haber_baslik_sadelestir(string $baslik): string
{
    $baslik = str_replace(['İ', 'I', 'ı'], 'i', $baslik);
    $baslik = mb_strtolower($baslik, 'UTF-8');
    $baslik = str_replace("\xCC\x87", '', $baslik);

    // Harf ve rakam disinda ne varsa tek bosluga indir.
    $baslik = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $baslik) ?? $baslik;

    return trim($baslik);
}

/** Sadelestirilmis adresin parmak izi; adres yoksa bos. */
function haber_url_parmak(string $kaynakUrl): string
{
    $sade = haber_url_sadelestir($kaynakUrl);

    return $sade === '' ? '' : hash('sha256', 'url:' . $sade);
}

/** Sadelestirilmis basligin parmak izi. */
function haber_baslik_parmak(string $baslik): string
{
    $sade = haber_baslik_sadelestir($baslik);

    return $sade === '' ? '' : hash('sha256', 'baslik:' . $sade);
}

/**
 * Verilen parmak izlerinden herhangi biriyle eşleşen haberin id'si.
 *
 * Durum farketmez: reddedilmis bir haberi yeniden yazdirmak da
 * istemiyoruz, yoksa ayni haber her calismada geri gelirdi.
 */
function haber_kopya_bul(string $parmak, string $urlParmak, string $baslikParmak): ?int
{
    $kosullar = ['kaynak_parmak = :p'];
    $degerler = ['p' => $parmak];

    if ($urlParmak !== '') {
        $kosullar[] = 'url_parmak = :u';
        $degerler['u'] = $urlParmak;
    }

    if ($baslikParmak !== '') {
        $kosullar[] = 'baslik_parmak = :b';
        $degerler['b'] = $baslikParmak;
    }

    $ifade = db()->prepare(
        'SELECT id FROM haberler WHERE ' . implode(' OR ', $kosullar) . ' LIMIT 1'
    );
    $ifade->execute($degerler);

    $id = $ifade->fetchColumn();

    return $id === false ? null : (int) $id;
}

/**
 * Yeni parmak izi alanları boş kalan eski kayıtları doldurur.
 *
 * Alanlar sonradan eklendi; eski satirlarda NULL duruyorlar. Doldurmayi
 * SQL'de yapamiyoruz (adres sadelestirme ve Turkce katlama PHP'de),
 * bu yuzden ajan siteye her bagland8ginda birkac yuz satir isleniyor.
 * Tek seferde hepsini yapmaya kalkmak buyuk bir arsivde istegi
 * zaman asimina ugratirdi.
 */
function haber_parmaklari_tamamla(int $adet = 400): int
{
    $satirlar = db()->query(
        'SELECT id, baslik, kaynak_url
           FROM haberler
          WHERE baslik_parmak IS NULL
          ORDER BY id DESC
          LIMIT ' . max(1, min(2000, $adet))
    )->fetchAll();

    if ($satirlar === []) {
        return 0;
    }

    $guncelle = db()->prepare(
        'UPDATE haberler SET url_parmak = :u, baslik_parmak = :b WHERE id = :id'
    );

    foreach ($satirlar as $satir) {
        $guncelle->execute([
            'u'  => haber_url_parmak((string) ($satir['kaynak_url'] ?? '')) ?: null,
            'b'  => haber_baslik_parmak((string) $satir['baslik']),
            'id' => (int) $satir['id'],
        ]);
    }

    return count($satirlar);
}

/**
 * Slug'i benzersizlestirir: "vergi-duzenlemesi", "vergi-duzenlemesi-2", ...
 */
function haber_benzersiz_slug(string $baslik, ?int $haricId = null): string
{
    $temel = slug_uret($baslik);
    $aday  = $temel;
    $ek    = 1;

    while (true) {
        $sql = 'SELECT id FROM haberler WHERE slug = :slug';
        $par = ['slug' => $aday];

        if ($haricId !== null) {
            $sql .= ' AND id <> :haric';
            $par['haric'] = $haricId;
        }

        $ifade = db()->prepare($sql . ' LIMIT 1');
        $ifade->execute($par);

        if ($ifade->fetchColumn() === false) {
            return $aday;
        }

        $ek++;
        $aday = $temel . '-' . $ek;
    }
}

/**
 * Ajanin buldugu haberi taslak olarak kaydeder.
 *
 * @return array{durum:string,id:?int} durum: 'eklendi' | 'yinelenen'
 */
function haber_taslak_ekle(array $veri): array
{
    $baslik    = trim((string) ($veri['baslik'] ?? ''));
    $icerik    = trim((string) ($veri['icerik'] ?? ''));
    $kaynakUrl = guvenli_url((string) ($veri['kaynak_url'] ?? ''));

    if ($baslik === '' || $icerik === '') {
        throw new InvalidArgumentException('Baslik ve icerik zorunludur.');
    }

    $parmak       = haber_parmak_izi($kaynakUrl, $baslik);
    $urlParmak    = haber_url_parmak($kaynakUrl);
    $baslikParmak = haber_baslik_parmak($baslik);

    /*
     * Uc olcut de deneniyor ve biri tutarsa haber eklenmiyor.
     *
     * Tek olcut (ham adres) yetmiyordu: ayni haber izleme
     * parametresi eklenmis bir adresle ya da baska bir kaynaktan
     * geldiginde yeniden yaziliyordu. Basliga da bakmak ayni haberin
     * iki kaynaktan gelen kopyasini da yakaliyor.
     */
    $mevcutId = haber_kopya_bul($parmak, $urlParmak, $baslikParmak);

    if ($mevcutId !== null) {
        return ['durum' => 'yinelenen', 'id' => $mevcutId];
    }

    $ozet = trim((string) ($veri['ozet'] ?? ''));
    if ($ozet === '') {
        $ozet = kisalt($icerik, 220);
    }

    $ifade = db()->prepare(
        'INSERT INTO haberler
            (baslik, slug, ozet, icerik, gorsel_url, etiketler, durum, kategori_id,
             kaynak_id, kaynak_adi, kaynak_url, kaynak_parmak, url_parmak,
             baslik_parmak, guven_skoru, ajan_notu)
         VALUES
            (:baslik, :slug, :ozet, :icerik, :gorsel_url, :etiketler, :durum, :kategori_id,
             :kaynak_id, :kaynak_adi, :kaynak_url, :kaynak_parmak, :url_parmak,
             :baslik_parmak, :guven_skoru, :ajan_notu)'
    );

    $ifade->execute([
        'baslik'        => mb_substr($baslik, 0, 300, 'UTF-8'),
        'slug'          => haber_benzersiz_slug($baslik),
        'ozet'          => mb_substr($ozet, 0, 600, 'UTF-8'),
        'icerik'        => $icerik,
        'gorsel_url'    => guvenli_url((string) ($veri['gorsel_url'] ?? '')) ?: null,
        'etiketler'     => mb_substr(trim((string) ($veri['etiketler'] ?? '')), 0, 400, 'UTF-8'),
        'durum'         => HABER_TASLAK,
        'kategori_id'   => kategori_id_cozumle((string) ($veri['kategori'] ?? '')),
        'kaynak_id'     => $veri['kaynak_id'] ?? null,
        'kaynak_adi'    => mb_substr(trim((string) ($veri['kaynak_adi'] ?? '')), 0, 160, 'UTF-8'),
        'kaynak_url'    => mb_substr($kaynakUrl, 0, 500, 'UTF-8'),
        'kaynak_parmak' => $parmak,
        'url_parmak'    => $urlParmak !== '' ? $urlParmak : null,
        'baslik_parmak' => $baslikParmak !== '' ? $baslikParmak : null,
        'guven_skoru'   => max(0, min(100, (int) ($veri['guven_skoru'] ?? 0))),
        'ajan_notu'     => mb_substr(trim((string) ($veri['ajan_notu'] ?? '')), 0, 600, 'UTF-8'),
    ]);

    return ['durum' => 'eklendi', 'id' => (int) db()->lastInsertId()];
}

/**
 * Onay bekleyen taslaklar (admin paneli).
 */
function haber_bekleyenler(int $limit = 100): array
{
    $ifade = db()->prepare(
        'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = :durum
          ORDER BY h.guven_skoru DESC, h.olusturuldu DESC
          LIMIT :limit'
    );
    $ifade->bindValue('durum', HABER_TASLAK);
    $ifade->bindValue('limit', $limit, PDO::PARAM_INT);
    $ifade->execute();

    return $ifade->fetchAll();
}

/**
 * Duruma gore listeleme (admin paneli sekmeler).
 */
function haber_listele(string $durum, int $limit = 100): array
{
    $ifade = db()->prepare(
        'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = :durum
          ORDER BY COALESCE(h.yayin_tarihi, h.guncellendi) DESC
          LIMIT :limit'
    );
    $ifade->bindValue('durum', $durum);
    $ifade->bindValue('limit', $limit, PDO::PARAM_INT);
    $ifade->execute();

    return $ifade->fetchAll();
}

function haber_bul(int $id): ?array
{
    $ifade = db()->prepare('SELECT * FROM haberler WHERE id = :id LIMIT 1');
    $ifade->execute(['id' => $id]);
    $satir = $ifade->fetch();

    return $satir === false ? null : $satir;
}

/**
 * Herkese acik sayfalar icin: yalnizca yayinda olan haberi slug ile getirir.
 */
function haber_yayinda_bul(string $slug): ?array
{
    $ifade = db()->prepare(
        'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.slug = :slug AND h.durum = :durum LIMIT 1'
    );
    $ifade->execute(['slug' => $slug, 'durum' => HABER_YAYINDA]);
    $satir = $ifade->fetch();

    return $satir === false ? null : $satir;
}

/**
 * Ana sayfa listesi: sadece yayinda olanlar, yeniden eskiye.
 */
function haber_yayindakiler(int $limit = 20, int $atla = 0): array
{
    $ifade = db()->prepare(
        'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = :durum
          ORDER BY h.one_cikan DESC, h.yayin_tarihi DESC
          LIMIT :limit OFFSET :atla'
    );
    $ifade->bindValue('durum', HABER_YAYINDA);
    $ifade->bindValue('limit', $limit, PDO::PARAM_INT);
    $ifade->bindValue('atla', $atla, PDO::PARAM_INT);
    $ifade->execute();

    return $ifade->fetchAll();
}

function haber_yayinda_sayisi(): int
{
    $ifade = db()->prepare('SELECT COUNT(*) FROM haberler WHERE durum = :durum');
    $ifade->execute(['durum' => HABER_YAYINDA]);

    return (int) $ifade->fetchColumn();
}

function haber_durum_sayilari(): array
{
    $satirlar = db()->query(
        'SELECT durum, COUNT(*) AS adet FROM haberler GROUP BY durum'
    )->fetchAll();

    $sayilar = [HABER_TASLAK => 0, HABER_YAYINDA => 0, HABER_REDDEDILDI => 0];
    foreach ($satirlar as $satir) {
        $sayilar[$satir['durum']] = (int) $satir['adet'];
    }

    return $sayilar;
}

/**
 * Onay: haberi ana sayfaya alir.
 */
function haber_onayla(int $id, int $yoneticiId): bool
{
    $ifade = db()->prepare(
        'UPDATE haberler
            SET durum = :durum,
                onaylayan_id = :yonetici,
                onay_tarihi = NOW(),
                yayin_tarihi = COALESCE(yayin_tarihi, NOW())
          WHERE id = :id'
    );
    $ifade->execute(['durum' => HABER_YAYINDA, 'yonetici' => $yoneticiId, 'id' => $id]);

    return $ifade->rowCount() > 0;
}

/**
 * Yayindan geri cekme veya reddetme.
 */
function haber_durum_degistir(int $id, string $durum): bool
{
    if (!in_array($durum, [HABER_TASLAK, HABER_YAYINDA, HABER_REDDEDILDI], true)) {
        throw new InvalidArgumentException('Gecersiz durum.');
    }

    $ifade = db()->prepare('UPDATE haberler SET durum = :durum WHERE id = :id');
    $ifade->execute(['durum' => $durum, 'id' => $id]);

    return $ifade->rowCount() > 0;
}

/**
 * Yonetici duzenlemesi.
 */
function haber_guncelle(int $id, array $veri): bool
{
    $baslik = trim((string) ($veri['baslik'] ?? ''));
    $icerik = trim((string) ($veri['icerik'] ?? ''));

    if ($baslik === '' || $icerik === '') {
        throw new InvalidArgumentException('Baslik ve icerik bos birakilamaz.');
    }

    $ifade = db()->prepare(
        'UPDATE haberler
            SET baslik = :baslik,
                slug = :slug,
                ozet = :ozet,
                icerik = :icerik,
                gorsel_url = :gorsel_url,
                iframe_url = :iframe_url,
                etiketler = :etiketler,
                one_cikan = :one_cikan,
                kategori_id = :kategori_id
          WHERE id = :id'
    );

    $ozet = trim((string) ($veri['ozet'] ?? ''));

    return $ifade->execute([
        'baslik'     => mb_substr($baslik, 0, 300, 'UTF-8'),
        'slug'       => haber_benzersiz_slug($baslik, $id),
        'ozet'       => mb_substr($ozet !== '' ? $ozet : kisalt($icerik, 220), 0, 600, 'UTF-8'),
        'icerik'     => $icerik,
        'gorsel_url' => guvenli_url((string) ($veri['gorsel_url'] ?? '')) ?: null,
        'iframe_url' => ($iframeUrl = guvenli_url((string) ($veri['iframe_url'] ?? ''))) !== ''
            ? mb_substr($iframeUrl, 0, 1000, 'UTF-8')
            : null,
        'etiketler'  => mb_substr(trim((string) ($veri['etiketler'] ?? '')), 0, 400, 'UTF-8'),
        'one_cikan'  => !empty($veri['one_cikan']) ? 1 : 0,
        'kategori_id'=> ($veri['kategori_id'] ?? '') !== '' ? (int) $veri['kategori_id'] : null,
        'id'         => $id,
    ]);
}

function haber_sil(int $id): bool
{
    $ifade = db()->prepare('DELETE FROM haberler WHERE id = :id');
    $ifade->execute(['id' => $id]);

    return $ifade->rowCount() > 0;
}

// ---------------------------------------------------------------------------
// Konu gruplari
// ---------------------------------------------------------------------------

/**
 * Ust menude gosterilecek aktif kategoriler.
 */
function kategori_listesi(bool $sadeceAktif = true): array
{
    $sql = 'SELECT * FROM kategoriler';

    if ($sadeceAktif) {
        $sql .= ' WHERE aktif = 1';
    }

    return db()->query($sql . ' ORDER BY sira, ad')->fetchAll();
}

/**
 * Ust menu agaci.
 *
 * Ust basliklar (ust_id'si olmayanlar) menude gorunur; altlarindaki
 * gruplar acilir listede listelenir. Her grubun yaninda yayindaki haber
 * sayisi gosterilir; ust basligin sayisi altlarinin toplamidir.
 *
 * @return list<array{ad:string,slug:string,adet:int,altlar:list<array<string,mixed>>}>
 */
function kategori_menusu(): array
{
    $satirlar = db()->query(
        "SELECT k.id, k.ad, k.slug, k.ust_id, k.sira,
                COUNT(h.id) AS adet
           FROM kategoriler k
           LEFT JOIN haberler h
             ON h.kategori_id = k.id AND h.durum = " . db()->quote(HABER_YAYINDA) . "
          WHERE k.aktif = 1
             OR k.slug IN ('vergi-kanunlari','muhasebe-denetim','ekonomi','tms-tfrs','genel')
          GROUP BY k.id, k.ad, k.slug, k.ust_id, k.sira
          ORDER BY k.sira, k.ad"
    )->fetchAll();

    $altlar = [];

    foreach ($satirlar as $satir) {
        if ($satir['ust_id'] !== null) {
            $altlar[(int) $satir['ust_id']][] = [
                'ad'   => $satir['ad'],
                'slug' => $satir['slug'],
                'adet' => (int) $satir['adet'],
            ];
        }
    }

    $menu = [];

    foreach ($satirlar as $satir) {
        if ($satir['ust_id'] !== null) {
            continue;
        }

        $cocuklar = $altlar[(int) $satir['id']] ?? [];

        $gorunum = [
            'vergi-kanunlari'   => ['ad' => 'Vergi Kanunları',     'sira' => 10],
            'muhasebe-denetim'  => ['ad' => 'Muhasebe ve Denetim', 'sira' => 20],
            'ekonomi'            => ['ad' => 'Ekonomik Gündem',     'sira' => 30],
            'tms-tfrs'           => ['ad' => 'TMS/TFRS',            'sira' => 40],
            'genel'              => ['ad' => 'Diğer',               'sira' => 50],
        ];

        $slug = (string) $satir['slug'];
        $ad   = $gorunum[$slug]['ad'] ?? (string) $satir['ad'];

        $menu[] = [
            'ad'     => $ad,
            'slug'   => $slug,
            'adet'   => (int) $satir['adet'] + array_sum(array_column($cocuklar, 'adet')),
            'altlar' => $cocuklar,
            '_sira'  => $gorunum[$slug]['sira'] ?? (100 + (int) $satir['sira']),
        ];
    }

    usort($menu, static fn (array $a, array $b): int => $a['_sira'] <=> $b['_sira']);

    foreach ($menu as &$oge) {
        unset($oge['_sira']);
    }
    unset($oge);

    return $menu;
}

function kategori_slug_bul(string $slug): ?array
{
    $takmaAdlar = [
        'diger' => 'genel',
        'diğer' => 'genel',
        'tms' => 'tms-tfrs',
        'tfrs' => 'tms-tfrs',
    ];

    $slug = $takmaAdlar[$slug] ?? $slug;

    $ifade = db()->prepare(
        "SELECT * FROM kategoriler
          WHERE slug = :slug
            AND (aktif = 1 OR slug IN ('ekonomi','tms-tfrs','genel'))
          LIMIT 1"
    );
    $ifade->execute(['slug' => $slug]);
    $satir = $ifade->fetch();

    return $satir === false ? null : $satir;
}

/**
 * Slug'dan kategori id'si; bulunamazsa null.
 */
function kategori_id_cozumle(?string $slug): ?int
{
    if ($slug === null || trim($slug) === '') {
        return null;
    }

    $ifade = db()->prepare('SELECT id FROM kategoriler WHERE slug = :slug LIMIT 1');
    $ifade->execute(['slug' => trim($slug)]);
    $id = $ifade->fetchColumn();

    return $id === false ? null : (int) $id;
}

/**
 * Bir kategorideki yayinda olan haberler.
 */
function haber_kategoride(int $kategoriId, int $limit = 20, int $atla = 0): array
{
    $ifade = db()->prepare(
        'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = :durum AND h.kategori_id = :kategori
          ORDER BY h.yayin_tarihi DESC
          LIMIT :limit OFFSET :atla'
    );
    $ifade->bindValue('durum', HABER_YAYINDA);
    $ifade->bindValue('kategori', $kategoriId, PDO::PARAM_INT);
    $ifade->bindValue('limit', $limit, PDO::PARAM_INT);
    $ifade->bindValue('atla', $atla, PDO::PARAM_INT);
    $ifade->execute();

    return $ifade->fetchAll();
}

function haber_kategoride_sayi(int $kategoriId): int
{
    $ifade = db()->prepare(
        'SELECT COUNT(*) FROM haberler WHERE durum = :durum AND kategori_id = :kategori'
    );
    $ifade->execute(['durum' => HABER_YAYINDA, 'kategori' => $kategoriId]);

    return (int) $ifade->fetchColumn();
}

/**
 * Mansette kayacak haberler (en yeni, one cikanlar once).
 */
function haber_manset(int $limit = 8): array
{
    $ifade = db()->prepare(
        'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = :durum
          ORDER BY h.one_cikan DESC, h.yayin_tarihi DESC
          LIMIT :limit'
    );
    $ifade->bindValue('durum', HABER_YAYINDA);
    $ifade->bindValue('limit', $limit, PDO::PARAM_INT);
    $ifade->execute();

    return $ifade->fetchAll();
}

/**
 * Yan kolondaki "son eklenen" listesi.
 *
 * Mansetten farkli siralama: one_cikan dikkate alinmaz, yalnizca en
 * yeniler. Boylece yan kolon mansetin kopyasi olmaz.
 */
function haber_son_eklenenler(int $limit = 8): array
{
    $ifade = db()->prepare(
        'SELECT h.*, k.ad AS kategori_adi, k.slug AS kategori_slug
           FROM haberler h
           LEFT JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = :durum
          ORDER BY h.yayin_tarihi DESC
          LIMIT :limit'
    );
    $ifade->bindValue('durum', HABER_YAYINDA);
    $ifade->bindValue('limit', $limit, PDO::PARAM_INT);
    $ifade->execute();

    return $ifade->fetchAll();
}

/**
 * Ana sayfanin alt bolumu: haberi olan her konu grubu ve o gruptaki son
 * haberler.
 *
 * Tek sorguda cekip PHP tarafinda gruplamak, grup basina ayri sorgu
 * acmaktan hizli; grup sayisi buyudukce fark artar.
 *
 * @return list<array{ad:string,slug:string,haberler:list<array<string,mixed>>}>
 */
function kategori_bloklari(int $grupBasiHaber = 4, int $enFazlaGrup = 6): array
{
    $satirlar = db()->query(
        'SELECT h.id, h.baslik, h.slug, h.ozet, h.gorsel_url, h.yayin_tarihi,
                h.kaynak_adi, k.ad AS kategori_adi, k.slug AS kategori_slug, k.sira
           FROM haberler h
           JOIN kategoriler k ON k.id = h.kategori_id
          WHERE h.durum = ' . db()->quote(HABER_YAYINDA) . '
            AND k.aktif = 1
          ORDER BY k.sira, k.ad, h.yayin_tarihi DESC'
    )->fetchAll();

    $bloklar = [];

    foreach ($satirlar as $satir) {
        $slug = (string) $satir['kategori_slug'];

        if (!isset($bloklar[$slug])) {
            $bloklar[$slug] = [
                'ad'       => (string) $satir['kategori_adi'],
                'slug'     => $slug,
                'haberler' => [],
            ];
        }

        if (count($bloklar[$slug]['haberler']) < $grupBasiHaber) {
            $bloklar[$slug]['haberler'][] = $satir;
        }
    }

    // Tek haberi olan grup blok olarak anlamli gorunmuyor; en az iki
    // haberi olanlari gosteriyoruz.
    $bloklar = array_filter(
        $bloklar,
        static fn (array $b): bool => count($b['haberler']) >= 2
    );

    return array_slice(array_values($bloklar), 0, $enFazlaGrup);
}

/**
 * En cok kullanilan etiketler (yan kolon icin).
 *
 * @return list<array{etiket:string,adet:int}>
 */
function haber_etiket_bulutu(int $limit = 12): array
{
    $satirlar = db()->query(
        'SELECT etiketler FROM haberler WHERE durum = ' . db()->quote(HABER_YAYINDA)
    )->fetchAll(PDO::FETCH_COLUMN);

    $sayimlar = [];

    foreach ($satirlar as $ham) {
        foreach (etiketleri_coz((string) $ham) as $etiket) {
            $anahtar = mb_strtolower($etiket, 'UTF-8');

            if (!isset($sayimlar[$anahtar])) {
                $sayimlar[$anahtar] = ['etiket' => $etiket, 'adet' => 0];
            }

            $sayimlar[$anahtar]['adet']++;
        }
    }

    usort($sayimlar, static fn (array $a, array $b): int => $b['adet'] <=> $a['adet']);

    return array_slice($sayimlar, 0, $limit);
}
