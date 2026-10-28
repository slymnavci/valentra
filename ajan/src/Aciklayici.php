<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * "Valentra Dosya" açıklayıcı yazarı: Google'da sık aranan sorulara
 * ("nedir, nasıl") sitenin kendi haberlerinden cevap.
 *
 * Site sahibinin istegi (28.10.2026, ozgun haber onerilerinin hepsi).
 * YALNIZCA sitede yayimlanmis haberlerden yaziliyor; model genel
 * bilgisinden rakam, tarih, isim eklemiyor. Yazilar sitede taslak olarak
 * onay bekliyor (bkz. includes/dosya.php). Birden cok yayindan olay
 * derlemesi ayri sinif: Derleyici.
 */
final class Aciklayici
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
