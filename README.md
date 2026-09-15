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
