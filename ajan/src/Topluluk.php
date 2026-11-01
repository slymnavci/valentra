<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * V Network yapay zeka ve kurum hesaplarinin yazari (31.10.2026).
 *
 * Site sahibinin istegi: "V Network biraz kalabalik gorunsun". Burada
 * yalnizca "Resmi" rozetli kurum hesaplari yaziyor (YZ hesaplari sadece
 * begeni birakiyor, bkz. includes/topluluk.php): haberlere ve paylasimlara
 * kisa yorum, haberden yola cikan paylasim, begeni. Her calismada birkac hesap secilir, model her biri
 * icin kisiligine uygun 1-3 eylem yazar. Eylemleri site, gercek uyelerin
 * kurallariyla (kufur filtresi, siklik siniri) uygular.
 *
 * Hesap adini model secmiyor: istemde her hesap sira numarasiyla geciyor,
 * yanittaki sira numarasi hesaba geri cevriliyor.
 */
final class Topluluk
{
    private const YONERGE = <<<'METIN'
        Valentra bir Türkçe haber sitesidir. V Network, sitenin sosyal
        alanıdır. Aşağıdaki hesaplar V Network'te "Resmî" rozetiyle işaretli
        Valentra kurum hesaplarıdır (ValHaber, ValSpor...). Görevin, her
        hesap için kendi alanına uygun, kısa ve doğal paylaşım ve yorumlar
        yazmak.

        EN ÖNEMLİ KURALLAR
        - Hesaplar gerçek kişi DEĞİLDİR ve bunu inkâr etmez. Kendini insan
          gibi tanıtma; uydurma kişisel deneyim, aile, iş yeri, gidilen yer,
          hastalık, satın alınan ürün yazma ("dün oradaydım", "eşim dedi ki",
          "bizim şirkette" YOK). Görüş, merak, soru, tepki yazılır.
        - Haberle ilgili bilgi YALNIZCA verilen başlık ve özette geçiyorsa
          kullanılır. Rakam, isim, tarih uydurma.
        - Yayın politikası: iç siyasette tarafsızlık (parti, siyasetçi,
          hükümet ya da muhalefet övülmez, yerilmez); kişi ve kurumlara suç
          isnadı yok; hakaret, küfür, nefret söylemi, kışkırtıcı dil yok;
          Türkiye'yi küçümseyen ifade yok; savaş kışkırtıcılığı yok.
        - Gerçek üyelerin paylaşımlarına yorum yaparken nazik ve konuyla
          ilgili ol; onları yönlendirme, kişisel bilgi sorma, buluşma ya da
          mesajlaşma önerme.
        - Bağlantı, etiket (#), reklam, ürün önerisi yok. En fazla bir emoji,
          çoğu metinde hiç yok.

        ÜSLUP
        - Her hesap kendi kişiliğiyle yazar: ilgi alanına giren haberleri
          seçer, üslubu (meraklı, esprili, ölçülü...) metne yansır.
        - Herkes aynı fikirde olmasın; farklı bakış açıları, sorular,
          çekinceler olsun. Hep övgü ya da hep şikâyet yok.
        - Kalıp girişlerden kaçın ("Çok önemli bir haber", "Bence de"...).
          Her metin farklı başlasın.
        - Doğal, günlük Türkçe; yazım ve noktalama düzgün.

        EYLEMLER (her hesap için 1-3 eylem)
        - "yorum": bir habere (hedef "h<id>") ya da bir paylaşıma (hedef
          "g<id>") yorum. 1-3 cümle, en fazla 280 karakter.
        - "paylasim": haberlerden birinden yola çıkan kendi paylaşımı;
          haberi kendi cümlenle an, bir görüş ya da soru ekle. 1-4 cümle, en
          fazla 400 karakter. hedef boş.
        - "begeni": bir habere ya da paylaşıma beğeni (hedef "h<id>" ya da
          "g<id>"), metin boş.
        - Resmî hesaplar yalnızca kendi alanından bir haberle "günün
          sorusu" niteliğinde paylaşım yapar ya da habere okurları
          tartışmaya çağıran tarafsız bir yorum yazar.
        - Hedef id'leri YALNIZCA aşağıdaki listelerden kullan. Bir hesap
          kendi paylaşımına yorum yapmaz.
        METIN;

    /** @var array<string,mixed> */
    private const SEMA = [
        'type' => 'object',
        'properties' => [
            'sonuclar' => [
                'type'  => 'array',
                'description' => 'Her hesap için bir öğe',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'sira'     => ['type' => 'integer', 'description' => 'Hesabın sıra numarası (HESAP n)'],
                        'eylemler' => [
                            'type'  => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'tur'   => ['type' => 'string', 'enum' => ['yorum', 'paylasim', 'begeni']],
                                    'hedef' => ['type' => 'string', 'description' => '"h<id>", "g<id>" ya da boş'],
                                    'metin' => ['type' => 'string'],
                                ],
                                'required' => ['tur', 'hedef', 'metin'],
                            ],
                        ],
                    ],
                    'required' => ['sira', 'eylemler'],
                ],
            ],
        ],
        'required' => ['sonuclar'],
    ];

    public function __construct(private readonly SemaliIstemci $istemci)
    {
    }

    /**
     * Secilen hesaplar icin eylemleri yazdirir.
     *
     * @param list<array<string,mixed>> $hesaplar   kisilik dosyasindaki kayitlar (rumuz, tur, kisilik)
     * @param list<array<string,mixed>> $haberler   site malzemesi
     * @param list<array<string,mixed>> $paylasimlar site malzemesi
     * @return list<array{hesap:string,tur:string,hedef:string,metin:string}>
     */
    public function eylemleriYaz(array $hesaplar, array $haberler, array $paylasimlar): array
    {
        $istem = $this->istem($hesaplar, $haberler, $paylasimlar);
        $sonuclar = $this->istemci->semaliIstek(self::YONERGE, $istem, self::SEMA, 12000, count($hesaplar), 'eylemler');

        $haberIdleri   = array_map(static fn (array $h): string => 'h' . (int) $h['id'], $haberler);
        $paylasimSahibi = [];

        foreach ($paylasimlar as $p) {
            $paylasimSahibi['g' . (int) $p['id']] = (string) ($p['rumuz'] ?? '');
        }

        $eylemler = [];

        foreach ($sonuclar as $sira => $sonuc) {
            $rumuz = (string) ($hesaplar[$sira]['rumuz'] ?? '');

            foreach (array_slice((array) ($sonuc['eylemler'] ?? []), 0, 3) as $e) {
                $tur   = (string) ($e['tur'] ?? '');
                $hedef = trim((string) ($e['hedef'] ?? ''));
                $metin = trim((string) ($e['metin'] ?? ''));

                // Gecersiz hedef, kendi paylasimina yorum, bos metin: atla.
                $hedefVar = in_array($hedef, $haberIdleri, true) || isset($paylasimSahibi[$hedef]);

                if ($tur === 'paylasim') {
                    $hedef = '';
                } elseif (!$hedefVar || ($paylasimSahibi[$hedef] ?? '') === $rumuz) {
                    continue;
                }

                if ($tur !== 'begeni' && ($metin === '' || mb_strlen($metin, 'UTF-8') > ($tur === 'paylasim' ? 500 : 350))) {
                    continue;
                }

                $eylemler[] = ['hesap' => $rumuz, 'tur' => $tur, 'hedef' => $hedef, 'metin' => $tur === 'begeni' ? '' : $metin];
            }
        }

        return $eylemler;
    }

    /**
     * @param list<array<string,mixed>> $hesaplar
     * @param list<array<string,mixed>> $haberler
     * @param list<array<string,mixed>> $paylasimlar
     */
    private function istem(array $hesaplar, array $haberler, array $paylasimlar): string
    {
        $satirlar = ['HESAPLAR:'];

        foreach (array_values($hesaplar) as $i => $h) {
            $satirlar[] = 'HESAP ' . ($i + 1) . ' — ' . $h['rumuz'] . ' (' . ($h['tur'] === 'kurum' ? 'Resmî Valentra hesabı' : 'YZ hesabı') . '): ' . $h['kisilik'];
        }

        $satirlar[] = "\nHABERLER:";

        foreach ($haberler as $h) {
            $satirlar[] = 'h' . (int) $h['id'] . ' [' . ($h['kategori'] ?? '-') . '] ' . $h['baslik']
                . ' — ' . mb_substr(trim((string) ($h['ozet'] ?? '')), 0, 240, 'UTF-8')
                . ' (' . (int) ($h['yorum'] ?? 0) . ' yorum)';
        }

        $satirlar[] = "\nSON PAYLAŞIMLAR (gerçek üyeler ve diğer hesaplar):";

        if ($paylasimlar === []) {
            $satirlar[] = '(yok)';
        }

        foreach ($paylasimlar as $p) {
            $etiket = match ((string) ($p['tur'] ?? 'uye')) {
                'yz'    => 'YZ hesabı',
                'kurum' => 'Resmî hesap',
                default => 'gerçek üye',
            };
            $satirlar[] = 'g' . (int) $p['id'] . ' — ' . $p['rumuz'] . ' (' . $etiket . '): '
                . mb_substr(trim((string) ($p['metin'] ?? '')), 0, 300, 'UTF-8')
                . ' (' . (int) ($p['yorum'] ?? 0) . ' yorum)';
        }

        return implode("\n", $satirlar);
    }
}
