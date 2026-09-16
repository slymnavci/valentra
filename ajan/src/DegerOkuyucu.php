<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * Resmî sayfalardan pratik bilgi değerlerini okur.
 *
 * Neden Yazar'dan ayri: Yazar haber YAZIYOR, burada is metin uretmek
 * degil sayfadaki bir SAYIYI bulup aynen aktarmak. Yonergeler
 * birbirinin tam tersi — biri "kendi cumlelerinle yaz" derken digeri
 * "hicbir sey ekleme, gordugunu yaz" diyor. Ayni sinifa tikmak ikisini
 * de bulandirirdi.
 *
 * Yanlis okumaya karsi iki koruma var:
 *   - Deger yalnizca verilen resmi sayfadan okunuyor; model kendi
 *     bilgisinden cevap veremez, sayfada yoksa "bulunamadi" demeli.
 *   - Cikan deger siteye ADAY olarak gidiyor, onaysiz yayimlanmiyor.
 */
final class DegerOkuyucu
{
    private const YONERGE = <<<'METIN'
    Sen bir mali müşavirlik sitesi için resmî kaynaklardan güncel
    değerleri okuyan bir yardımcısın.

    Sana bir bilginin adı, ne aranacağına dair kısa bir yönerge ve bir
    resmî sayfanın metni verilecek.

    KURALLAR — bunlar kesin:

    1. Değeri YALNIZCA verilen sayfa metninden oku. Kendi bilginden,
       hatırladığından ya da tahminden ASLA değer üretme. Bu değerler
       doğrudan vergi hesaplamasında kullanılıyor; uydurulmuş bir rakam
       gerçek zarar verir.

    2. Sayfada aranan bilgi YOKSA "bulundu" alanını false yap ve
       nedenini kısaca yaz. Yaklaşık, eski ya da benzer bir değeri
       "yakın olsun" diye vermek en kötü sonuçtur.

    3. Sayfada eski bir döneme ait değer varsa onu verme; hangi döneme
       ait olduğu belirsizse "bulundu" false olmalı.

    4. Rakamları sayfadaki gibi, Türkçe biçimiyle yaz (1.234,56).
       Para birimini belirt (TL). Oranları yüzde işaretiyle yaz.

    5. Bilgi bir tablo ise (gelir vergisi tarifesi, KDV oranları)
       satırları kısa ve düzenli aktar; her satırı yeni satıra yaz.
       Örnek:
         158.000 TL'ye kadar %15
         330.000 TL'nin 158.000 TL'si için 23.700 TL, fazlası %20

    6. DÖNEM alanına değerin hangi dönem için geçerli olduğunu yaz
       (örnek: "2026 yılı", "1 Ocak 2026'dan itibaren"). Sayfada
       yazmıyorsa boş bırak.

    7. GÜVEN (0-100): Sayfada açıkça ve tek anlamlı yazıyorsa yüksek
       (85-100). Çıkarım yapman gerektiyse ya da birden fazla aday
       değer varsa düşür (50-80). Şüpheliysen 50'nin altı ve tercihen
       "bulundu" false.

    8. NOT alanına, onaylayacak mali müşavire tek cümlelik bir not yaz:
       değeri sayfanın neresinde bulduğun ve dikkat etmesi gereken bir
       şey varsa o.
    METIN;

    /** @var array<string,mixed> */
    private const SEMA = [
        'type' => 'object',
        'properties' => [
            'sonuclar' => [
                'type'  => 'array',
                'description' => 'Her bilgi icin bir sonuc, sirasiyla',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'sira'    => ['type' => 'integer', 'description' => 'Istekteki SIRA numarasi (1 den baslar)'],
                        'bulundu' => ['type' => 'boolean', 'description' => 'Deger sayfada bulundu mu'],
                        'deger'   => ['type' => 'string', 'description' => 'Bulunan deger; bulunmadiysa bos'],
                        'donem'   => ['type' => 'string', 'description' => 'Gecerlilik donemi; bilinmiyorsa bos'],
                        'guven'   => ['type' => 'integer', 'description' => '0-100 arasi guven'],
                        'not'     => ['type' => 'string', 'description' => 'Onaylayana tek cumlelik not'],
                    ],
                    'required' => ['sira', 'bulundu', 'deger', 'guven', 'not'],
                ],
            ],
        ],
        'required' => ['sonuclar'],
    ];

    public function __construct(private readonly SemaliIstemci $yazar)
    {
    }

    /**
     * Bir grup bilgiyi tek istekte okur.
     *
     * @param list<array{bilgi:array<string,mixed>,sayfaMetni:string}> $adaylar
     * @return array<int,array<string,mixed>> sira => sonuc
     */
    public function oku(array $adaylar): array
    {
        if ($adaylar === []) {
            return [];
        }

        return $this->yazar->semaliIstek(
            self::YONERGE,
            $this->istemHazirla($adaylar),
            self::SEMA,
            12000
        );
    }

    /** @param list<array{bilgi:array<string,mixed>,sayfaMetni:string}> $adaylar */
    private function istemHazirla(array $adaylar): string
    {
        $satirlar = ['Aşağıdaki bilgileri kendi sayfalarından oku.', ''];

        foreach ($adaylar as $sira => $aday) {
            $bilgi = $aday['bilgi'];

            /*
             * Numaralandirma 1'den baslamali.
             *
             * Yazar::semaliIstek yaniti dizi indisine cevirirken 1
             * cikariyor (haber istemi 1'den numaralandirdigi icin).
             * Burada 0'dan baslatinca tum sonuclar bir kayiyordu:
             * calismada SGK'ya ait not TCMB Politika Faizi'ne
             * iliskilendirildi. Bu ozellikte en tehlikeli hata turu —
             * deger dogru okunsa bile yanlis basliga baglanir.
             */
            $satirlar[] = '--- SIRA ' . ($sira + 1) . ' ---';
            $satirlar[] = 'BİLGİ: ' . (string) $bilgi['baslik'];
            $satirlar[] = 'NE ARANACAK: ' . (string) ($bilgi['arama_ipucu'] ?? '');
            $satirlar[] = 'KAYNAK: ' . (string) ($bilgi['kaynak_adi'] ?? '')
                        . ' — ' . (string) ($bilgi['kaynak_url'] ?? '');

            $mevcut = trim((string) ($bilgi['deger'] ?? ''));

            if ($mevcut !== '') {
                // Mevcut deger karsilastirma icin veriliyor, kopyalamak
                // icin degil; model "ayni kalmis olabilir" diye eskisini
                // tekrar yazmasin diye acikca uyariliyor.
                $satirlar[] = 'SİTEDEKİ MEVCUT DEĞER (yalnızca karşılaştırma için, '
                            . 'sayfada doğrulamadan tekrar yazma): ' . $mevcut;
            }

            $satirlar[] = 'SAYFA METNİ:';
            $satirlar[] = mb_substr($aday['sayfaMetni'], 0, 6000, 'UTF-8');
            $satirlar[] = '';
        }

        return implode("\n", $satirlar);
    }
}
