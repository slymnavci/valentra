<?php
declare(strict_types=1);

/**
 * Kurumsal sayfalar: Hakkımızda, Künye, İletişim, Yayın İlkeleri, Gizlilik ve Çerezler.
 *
 * Site sahibinin istegi (26.10.2026): Google Haberler icin eksikler
 * tamamlansin. Google artik basvuru almiyor, siteleri kendisi buluyor;
 * haberin kim tarafindan ve nasil yayimlandiginin acikca gorulmesini
 * istiyor. Kunye ayrica Basin Kanunu geregi internet haber sitelerinde
 * zorunlu.
 *
 * Kunyedeki kisi ve sirket bilgileri koda YAZILMIYOR: site sahibi
 * panelden (Site -> Künye ve iletişim) giriyor, ayarlar tablosunda
 * duruyor. Bos alan sayfada hic gorunmuyor; uydurma bir deger basilmiyor.
 */

require_once __DIR__ . '/ayarlar.php';

/**
 * Künye alanları: ayar anahtarı => [etiket, panel ipucu, çok satırlı mı].
 *
 * @return array<string,array{etiket:string,ipucu:string,cok_satir:bool}>
 */
function kunye_alanlari(): array
{
    return [
        'kunye_sahip'         => ['etiket' => 'Yayın sahibi',
                                  'ipucu'  => 'Gerçek kişiyse adı soyadı, şirketse ticaret unvanı.',
                                  'cok_satir' => false],
        'kunye_sorumlu'       => ['etiket' => 'Sorumlu yazı işleri müdürü',
                                  'ipucu'  => 'Adı soyadı.',
                                  'cok_satir' => false],
        'kunye_adres'         => ['etiket' => 'Yönetim yeri',
                                  'ipucu'  => 'Yayının yönetildiği açık adres.',
                                  'cok_satir' => true],
        'kunye_eposta'        => ['etiket' => 'E-posta',
                                  'ipucu'  => 'Okurların ve kurumların yazacağı adres; düzeltme ve kaldırma talepleri buraya gelir.',
                                  'cok_satir' => false],
        'kunye_telefon'       => ['etiket' => 'Telefon',
                                  'ipucu'  => 'İsteğe bağlı.',
                                  'cok_satir' => false],
        // WhatsApp kanali (03.11.2026): girilirse her haberin altinda "Kanala katil" kutusu (bkz. includes/paylas.php).
        'kunye_whatsapp'      => ['etiket' => 'WhatsApp kanalı',
                                  'ipucu'  => 'Valentra WhatsApp kanalının bağlantısı (https://whatsapp.com/channel/...). Girilirse her haberin ve köşe yazısının altında "Kanala katıl" kutusu çıkar.',
                                  'cok_satir' => false],
        'kunye_yer_saglayici' => ['etiket' => 'Yer sağlayıcı',
                                  'ipucu'  => 'Barındırma firmasının ticaret unvanı ve adresi (ör. hosting faturasındaki unvan).',
                                  'cok_satir' => true],
    ];
}

/** @return array<string,string> anahtar => değer (boşlar dahil) */
/*
 * RESMI E-POSTA (site sahibinin istegi, 20.11.2026: "mail adresi
 * info@valentra.com.tr adresini bildirelim, daha resmi olsun"). Kunyedeki
 * adres bir kez buna cevriliyor; sonra panelden (Kunye ve iletisim)
 * degistirilebilir. Bossa da bu adres gosteriliyor.
 */
const KUNYE_RESMI_EPOSTA = 'info@valentra.com.tr';

/** Künyedeki e-postayı bir kez resmi adrese çevirir. */
function kunye_eposta_gecisi(): void
{
    static $bakildi = false;

    if ($bakildi) {
        return;
    }

    $bakildi = true;

    if (ayar_oku('kunye_eposta_gecis') !== KUNYE_RESMI_EPOSTA) {
        ayar_yaz('kunye_eposta', KUNYE_RESMI_EPOSTA);
        ayar_yaz('kunye_eposta_gecis', KUNYE_RESMI_EPOSTA);
    }
}

function kunye_bilgileri(): array
{
    kunye_eposta_gecisi();
    $bilgi = [];

    foreach (array_keys(kunye_alanlari()) as $anahtar) {
        $bilgi[$anahtar] = trim(ayar_oku($anahtar));
    }

    if ($bilgi['kunye_eposta'] === '') {
        $bilgi['kunye_eposta'] = KUNYE_RESMI_EPOSTA;
    }

    return $bilgi;
}

/** Künyenin zorunlu alanlarından boş olanların etiketleri (panel uyarısı). */
function kunye_eksikleri(): array
{
    $bilgi   = kunye_bilgileri();
    $eksik   = [];

    foreach (['kunye_sahip', 'kunye_sorumlu', 'kunye_adres', 'kunye_eposta', 'kunye_yer_saglayici'] as $anahtar) {
        if ($bilgi[$anahtar] === '') {
            $eksik[] = kunye_alanlari()[$anahtar]['etiket'];
        }
    }

    return $eksik;
}

/**
 * Kurumsal sayfalar: adres anahtarı => başlık.
 *
 * @return array<string,string>
 */
function kurumsal_sayfalar(): array
{
    return [
        'hakkimizda'     => 'Hakkımızda',
        'yayin-ilkeleri' => 'Yayın İlkeleri',
        // Haber imzasi olan masalar (19.11.2026; bkz. includes/masalar.php).
        'yayin-masalari' => 'Yayın Masaları',
        'kunye'          => 'Künye',
        'iletisim'       => 'İletişim',
        // Gizlilik ve cerezler (29.10.2026): Google Analytics ve AdSense icin
        // zorunlu (AdSense gizlilik politikasi olmayan siteyi onaylamiyor).
        'gizlilik'       => 'Gizlilik ve Çerezler',
    ];
}

function kurumsal_yolu(string $sayfa): string
{
    return temiz_adres_acik() ? '/' . $sayfa : '/kurumsal.php?s=' . rawurlencode($sayfa);
}
