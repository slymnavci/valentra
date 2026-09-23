# Valentra — Vergi Haberleri

Vergi gündemine odaklanan haber portalı. Haberleri bir ajan derler, **yönetici
onayladıktan sonra** ana sayfada yayımlanır.

## Akış

```
Ajan  ──POST /api/ingest.php──▶  taslak  ──yönetici onayı──▶  yayında (ana sayfa)
```

Ingest ucu haberleri **her zaman taslak** olarak yazar. Bu uç hiçbir koşulda
haberi yayına alamaz; yayına alma yetkisi yalnızca yönetim panelindedir.

## Dizinler

| Yol | İşlev |
|-----|-------|
| `index.php` | Ana sayfa — yalnızca onaylanmış haberler |
| `haber.php` | Haber detayı — yayında olmayan kayıt 404 döner |
| `admin/` | Yönetim paneli (giriş zorunlu) |
| `api/ingest.php` | Ajanın haber gönderdiği uç |
| `includes/` | Ortak katman — doğrudan erişime kapalı |
| `sql/schema.sql` | Veritabanı şeması |
| `kurulum.php` | Tek seferlik ilk yönetici kurulumu |

## Kurulum

1. `sql/schema.sql` dosyasını veritabanında çalıştırın.
2. `https://siteadresi/kurulum.php` adresine gidin, yönetici hesabını oluşturun.
   Sayfa ilk hesap oluşunca kendini kapatır; yine de dosyayı sunucudan silin.
3. `https://siteadresi/admin/` adresinden giriş yapın.
4. **Ajan anahtarları** sayfasından bir anahtar üretin. Anahtar yalnızca bir kez
   gösterilir; veritabanında sadece SHA-256 özeti saklanır.

## Ajan arayüzü

```
POST /api/ingest.php
Authorization: Bearer <anahtar>
Content-Type: application/json

{
  "haberler": [
    {
      "baslik":      "KDV tevkifat oranlarında değişiklik",
      "ozet":        "Bir cümlelik spot (boş bırakılırsa içerikten üretilir)",
      "icerik":      "Paragraflar boş satırla ayrılır.",
      "etiketler":   "KDV, Tebliğ",
      "kaynak_adi":  "Resmî Gazete",
      "kaynak_url":  "https://...",
      "gorsel_url":  "https://...",
      "guven_skoru": 92,
      "ajan_notu":   "Onaylayana not — neden seçildiği, nereden doğrulandığı"
    }
  ]
}
```

Yanıt: `{"durum":"tamam","eklenen":2,"yinelenen":0,"hatalar":[]}`

Aynı `kaynak_url` ile gelen haber ikinci kez eklenmez (`yinelenen` sayılır),
böylece ajan her gün çalışsa da kopya birikmez.

## Ekonomik göstergeler

Pratik bilgilerin sayısal olanları sayfa kazınarak değil, kaynağın kendi
**makine okunur ucundan** alınır (`includes/ekonomi.php`). Hangi satırın hangi
seriden okunacağı orada tanımlıdır; model bu yolda hiç devreye girmez.

| Kaynak | Gösterge | Anahtar |
|--------|----------|---------|
| Dünya Bankası | GSYH, kişi başına gelir, büyüme | gerekmez |
| IMF — World Economic Outlook | İşsizlik, kamu borcu / GSYH | gerekmez |
| TCMB — EVDS | Politika faizi, TÜFE | **gerekli** |

EVDS anahtarı ücretsizdir: [evds3.tcmb.gov.tr](https://evds3.tcmb.gov.tr/)
adresinden üye olup *Profil → API Anahtarı* bölümünden alınır ve panelde
**Pratik bilgiler** sayfasına yapıştırılır. Ortam değişkeni değil, ayar olarak
tutulur: anahtarı girecek kişi mali müşavir, GitHub ayarlarına girmiyor.
Anahtar girilene kadar politika faizi ve enflasyon eski yoldan, sayfa okunarak
toplanmaya devam eder.

İki nokta bilinçli:

- **Onay şartı değişmiyor.** API'den gelen değer de ADAY olarak yazılır.
  API'den gelmesi, doğru seriden ve doğru dönemden geldiğini kanıtlamaz.
- **IMF tahminleri ayıklanır.** DataMapper gelecek yılların öngörülerini de
  aynı dizide verir; içinde bulunulan yıldan sonrası atılır, cari yıl için de
  "tahmin olabilir" uyarısı nota yazılır.

## Güvenlik notları

- Veritabanı bilgileri repoda **yoktur**; deploy sırasında GitHub Secrets'tan
  `includes/database.php` olarak üretilir.
- Yönetici parolası hiçbir dosyada tutulmaz, yalnızca bcrypt özeti veritabanında.
- Ajan anahtarı veritabanında SHA-256 özeti olarak saklanır.
- Tüm form gönderimleri CSRF jetonu ile doğrulanır.
- Ajandan gelen tüm metinler çıktıda kaçırılır; `kaynak_url` ve `gorsel_url`
  yalnızca `http`/`https` şemasına izin veren doğrulamadan geçer.

## Yerel geliştirme

`includes/database.local.php` dosyası (gitignore'da) `$pdo` tanımlar.
`php -S 127.0.0.1:8000 -t .` ile çalıştırılır.
