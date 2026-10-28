<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * "Valentra Dosya" yazarı: derleme ("Ne biliyoruz, ne bilmiyoruz") ve
 * açıklayıcı ("nedir, nasıl") yazılar.
 *
 * Site sahibinin istegi (28.10.2026, ozgun haber onerilerinin hepsi).
 * Ikisi de YALNIZCA sitede yayimlanmis haberlerden yaziliyor; model genel
 * bilgisinden rakam, tarih, isim eklemiyor. Yazilar sitede taslak olarak
 * onay bekliyor (bkz. includes/dosya.php).
 */
final class Derleyici
{
    /** Kabul edilecek en kısa metin (karakter); sitedeki sınırla aynı. */
    public const ASGARI_METIN = 600;

    private const ORTAK = <<<'METIN'
        Valentra, Türkiye'den ve dünyadan güncel haberleri yayımlayan bir
        haber sitesidir. "Valentra Dosya" bölümü, sitede yayımlanmış
        haberlerden derlenen özgün yazılardır.

        YAYIN POLİTİKASI (tartışmasız):
        - İç siyasette tarafsızlık: hiçbir partiyi, siyasetçiyi, hükümeti ya
          da muhalefeti övme, yerme, hedef gösterme.
        - Türkiye'nin bakış açısı: Türkiye'yi küçümseyen ya da başka ülkeleri
          Türkiye'nin aleyhine öven karşılaştırma yok. Dış meselelerde
          Türkiye'nin resmî tutumu esas; İsrail hükümetinin politikalarını
          savunan ya da Filistin halkını yeren ifade yok; savaş kışkırtıcılığı
          ve nefret söylemi yok.
        - Kişi ya da kurumlara suç isnat edilmez; yargı süreci sürüyorsa
          sonuç önceden ilan edilmez.
        - Hakaret, kışkırtıcı ve kutuplaştırıcı dil yok; abartı ve tık
          tuzağı yok ("şok", "flaş", "bomba", "skandal").

        EN ÖNEMLİ KURAL — UYDURMA.
        - Rakam, tarih, isim, kurum, alıntı YALNIZCA verilen haberlerde
          geçiyorsa yazılır. Genel bilginle ayrıntı ekleme, tahminle boşluk
          doldurma.
        - Bilgi hangi haberden geliyorsa kaynağın adıyla atıf yap
          ("Anadolu Ajansı'nın aktardığına göre…").
        - Haberler arasında çelişki varsa ikisini de kaynaklarıyla aktar,
          hangisinin doğru olduğuna hüküm verme.
        - Belli olmayan bir şeyi "henüz belli değil" diye açıkça söyle.
        - Kaynak cümlelerini kopyalama; kendi cümlelerinle yaz.

        BİÇİM
        - Türkçe. Paragraflar boş satırla ayrılır.
        - Ara başlık satırı "## " ile başlar ve tek satırdır.
        - Madde listesinde her satır "- " ile başlar.
        - Kalın, italik, bağlantı, tablo, emoji yok.
        METIN;

    private const DERLEME_SECIM = self::ORTAK . <<<'METIN'


        Görevin: aşağıdaki haberlerden "Ne biliyoruz, ne bilmiyoruz"
        derlemesine uygun TEK bir olay seçmek.

        - Olay, EN AZ 3 FARKLI KAYNAĞIN (Kaynak alanı farklı) haber verdiği
          aynı olay olmalı. Aynı olayı anlatan bütün haberlerin numaralarını
          ver.
        - Önemli ve gelişmekte olan bir olay seç: çok kişiyi etkileyen,
          hakkında farklı bilgiler ve açıklamalar gelen. Önem puanı yüksek
          olanlara öncelik ver.
        - "ÖNCEKİ DOSYALAR"daki olayı, yeni ve somut gelişme yoksa seçme.
        - Yayın politikasına takılacak olay (yargı süreci süren suçlama,
          siyasi polemik, doğrulanmamış iddia) ve magazin seçme.
        - Uygun olay yoksa boş liste döndür.
        METIN;

    private const DERLEME_YAZI = self::ORTAK . <<<'METIN'


        Görevin: verilen olay için "Ne biliyoruz, ne bilmiyoruz" derlemesi
        yazmak. Malzeme, aynı olayı farklı kaynaklardan veren haberlerdir.

        - 400-800 kelime.
        - İlk paragraf: olay ne, neden önemli; iki-üç cümle.
        - Sonra şu ara başlıklar (malzemede karşılığı olmayanı atla):
          "## Ne biliyoruz?" (kesinleşen bilgiler, madde listesi olabilir),
          "## Olayın kronolojisi" (saat ya da gün sırasıyla, yalnızca
          malzemedeki zamanlar), "## Tarafların açıklamaları" (adıyla ve
          kaynağıyla), "## Henüz belli olmayanlar" (kaynakların açık
          bıraktığı ya da çelişen noktalar).
        - Son paragraf kısa: bundan sonra neyin takip edileceği.
        - Başlık "Ne biliyoruz?" kalıbını kullanabilir ama olayı açıkça
          söylemeli; en fazla 110 karakter.

        editor_notu: onaylayacak editör için doğrulanması gereken noktalar
        ve hassas yönler.
        METIN;

    private const ACIKLAYICI_YAZI = self::ORTAK . <<<'METIN'


        Görevin: Google'da sık aranan bir soruya cevap veren AÇIKLAYICI
        yazı. Okur bu soruyu arayıp siteye gelecek; ona açık, düzenli ve
        doğru bir cevap ver.

        - Aşağıda ARAMA SORGUSU ve sitede o konuda yayımlanmış haberler
          var. Yazıyı YALNIZCA bu haberlerdeki bilgilerle yaz.
        - Haberler soruyu cevaplamaya yetmiyorsa uygun=false döndür ve
          başka alanları boş bırak. Zorlama yazı yazma.
        - 400-700 kelime. İlk paragraf sorunun kısa cevabı.
        - Sonra 2-4 ara başlık: konuya göre "## ... nedir?", "## Nasıl
          işliyor?", "## Kimleri ilgilendiriyor?", "## Son gelişmeler",
          "## Dikkat edilmesi gerekenler".
        - Hukuki, mali ya da sağlıkla ilgili konuda kesin tavsiye verme;
          resmî kaynağa (malzemede adı geçen kurum) yönlendir.
        - Başlık arama sorgusuna doğrudan cevap verir biçimde, en fazla 110
          karakter (ör. "MASAK ŞİB nedir, rehberde neler var?").

        editor_notu: onaylayacak editör için doğrulanması gereken noktalar.
        METIN;

    /** @var array<string,mixed> */
    private const SECIM_SEMA = [
        'type' => 'object',
        'properties' => [
            'sonuclar' => [
                'type'  => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'sira'     => ['type' => 'integer', 'description' => 'Her zaman 1'],
                        'olay'     => ['type' => 'string', 'description' => 'Olayın kısa adı'],
                        'haberler' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Olayı anlatan HABER numaraları'],
                        'kategori' => ['type' => 'string', 'description' => 'gundem, politika, ekonomi, dunya, spor, teknoloji, saglik ya da kultur-sanat'],
                    ],
                    'required' => ['sira', 'olay', 'haberler', 'kategori'],
                ],
            ],
        ],
        'required' => ['sonuclar'],
    ];

    /** @var array<string,mixed> */
    private const YAZI_SEMA = [
        'type' => 'object',
        'properties' => [
            'sonuclar' => [
                'type'  => 'array',
                'description' => 'Tek eleman',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'sira'        => ['type' => 'integer', 'description' => 'Her zaman 1'],
                        'uygun'       => ['type' => 'boolean', 'description' => 'Malzeme yazıya yetiyor mu'],
                        'baslik'      => ['type' => 'string'],
                        'ozet'        => ['type' => 'string', 'description' => '1-2 cümle, en fazla 220 karakter'],
                        'icerik'      => ['type' => 'string'],
                        'etiketler'   => ['type' => 'string', 'description' => 'Virgülle ayrılmış 3-6 etiket'],
                        'editor_notu' => ['type' => 'string'],
                    ],
                    'required' => ['sira', 'uygun', 'baslik', 'ozet', 'icerik', 'etiketler', 'editor_notu'],
                ],
            ],
        ],
        'required' => ['sonuclar'],
    ];

    public function __construct(private readonly SemaliIstemci $istemci)
    {
    }

    /**
     * Derlemeye uygun olay: en az 3 farklı kaynağın verdiği.
     *
     * @param list<array<string,mixed>> $haberler
     * @param list<array<string,mixed>> $onceki
     * @return array{olay:string,haberler:list<int>,kategori:string}|null haberler: 0 tabanli indis
     */
    public function olaySec(array $haberler, array $onceki): ?array
    {
        if (count($haberler) < 3) {
            return null;
        }

        $sonuclar = $this->istemci->semaliIstek(
            self::DERLEME_SECIM,
            $this->secimIstemi($haberler, $onceki),
            self::SECIM_SEMA,
            6000,
            0,
            'haberler'
        );

        foreach ($sonuclar as $s) {
            $indisler = [];
            $kaynaklar = [];

            foreach ((array) ($s['haberler'] ?? []) as $no) {
                $i = (int) $no - 1;

                if (isset($haberler[$i]) && !in_array($i, $indisler, true)) {
                    $indisler[] = $i;
                    $kaynaklar[mb_strtolower(trim((string) ($haberler[$i]['kaynak_adi'] ?? '')), 'UTF-8')] = true;
                }
            }

            // Kural kodda da: modelin "3 kaynak" demesine guvenme.
            if (count($kaynaklar) >= 3) {
                return [
                    'olay'     => mb_substr(trim((string) ($s['olay'] ?? '')), 0, 200, 'UTF-8'),
                    'haberler' => array_slice($indisler, 0, 8),
                    'kategori' => trim((string) ($s['kategori'] ?? 'gundem')),
                ];
            }
        }

        return null;
    }

    /**
     * @param array{olay:string,haberler:list<int>} $olay
     * @param list<array<string,mixed>> $haberler
     * @return array{baslik:string,ozet:string,icerik:string,etiketler:string,editor_notu:string}|null
     */
    public function derlemeYaz(array $olay, array $haberler): ?array
    {
        $malzeme = array_map(static fn (int $i): array => $haberler[$i], $olay['haberler']);

        return $this->yazdir(self::DERLEME_YAZI, 'OLAY: ' . $olay['olay'] . "\n\n" . self::malzemeBlogu($malzeme));
    }

    /**
     * @param array{sorgu:string,haberler:list<array<string,mixed>>} $firsat
     * @return array{baslik:string,ozet:string,icerik:string,etiketler:string,editor_notu:string}|null
     */
    public function aciklayiciYaz(array $firsat): ?array
    {
        return $this->yazdir(self::ACIKLAYICI_YAZI, 'ARAMA SORGUSU: ' . $firsat['sorgu'] . "\n\n" . self::malzemeBlogu($firsat['haberler']));
    }

    /** @return array{baslik:string,ozet:string,icerik:string,etiketler:string,editor_notu:string}|null */
    private function yazdir(string $yonerge, string $istem): ?array
    {
        $sonuclar = $this->istemci->semaliIstek($yonerge, $istem, self::YAZI_SEMA, 16000, 1, 'icerik');
        $y = $sonuclar[0] ?? null;

        if (!is_array($y) || empty($y['uygun'])) {
            return null;
        }

        $baslik = trim((string) ($y['baslik'] ?? ''));
        $icerik = KoseYazari::metniDuzelt((string) ($y['icerik'] ?? ''));

        if (mb_strlen($baslik, 'UTF-8') < 10 || mb_strlen($icerik, 'UTF-8') < self::ASGARI_METIN) {
            return null;
        }

        return [
            'baslik'      => mb_substr($baslik, 0, 200, 'UTF-8'),
            'ozet'        => mb_substr(trim((string) ($y['ozet'] ?? '')), 0, 600, 'UTF-8'),
            'icerik'      => $icerik,
            'etiketler'   => mb_substr(trim((string) ($y['etiketler'] ?? '')), 0, 300, 'UTF-8'),
            'editor_notu' => mb_substr(trim((string) ($y['editor_notu'] ?? '')), 0, 500, 'UTF-8'),
        ];
    }

    /** @param list<array<string,mixed>> $haberler */
    private function secimIstemi(array $haberler, array $onceki): string
    {
        $satirlar = [];

        foreach ($haberler as $i => $h) {
            $satirlar[] = 'HABER ' . ($i + 1) . ' — ' . $h['baslik'] . "\n"
                . '  Kaynak: ' . ($h['kaynak_adi'] ?? '-') . ' · Konu: ' . ($h['kategori'] ?? '-')
                . ' · Önem: ' . (int) ($h['onem'] ?? 50) . ' · Tarih: ' . ($h['tarih'] ?? '-') . "\n"
                . '  Özet: ' . mb_substr(trim((string) ($h['ozet'] ?? '')), 0, 300, 'UTF-8');
        }

        $oncekiBlok = $onceki === []
            ? '(yok)'
            : implode("\n", array_map(static fn (array $o): string => '- ' . ($o['gun'] ?? '') . ' · ' . ($o['baslik'] ?? ''), array_slice($onceki, 0, 30)));

        return "ÖNCEKİ DOSYALAR:\n{$oncekiBlok}\n\nHABERLER:\n\n" . implode("\n\n", $satirlar);
    }

    /** @param list<array<string,mixed>> $haberler */
    private static function malzemeBlogu(array $haberler): string
    {
        $bloklar = [];

        foreach (array_values($haberler) as $i => $h) {
            $bloklar[] = 'HABER ' . ($i + 1) . ' — ' . $h['baslik'] . "\n"
                . 'Kaynak: ' . ($h['kaynak_adi'] ?? '-') . ' · Tarih: ' . ($h['tarih'] ?? '-') . "\n"
                . 'Özet: ' . trim((string) ($h['ozet'] ?? '')) . "\n"
                . 'Metin: ' . mb_substr(trim((string) ($h['icerik'] ?? '')), 0, 2500, 'UTF-8');
        }

        return "MALZEME (" . count($haberler) . " haber):\n\n" . implode("\n\n---\n\n", $bloklar);
    }
}
