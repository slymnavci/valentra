# Kök sertifika listesi

`ca-bundle.crt` güncel kök sertifika otoritelerinin listesidir
(Debian `ca-certificates` paketinden, 2024 sürümü, 152 sertifika).

## Neden depoda duruyor

Paylaşımlı hosting sunucularının kök sertifika listesi çoğu zaman
eskidir. Bu durumda PHP'nin curl'ü, aslında geçerli olan sertifikaları
doğrulayamaz ve site dış kaynaklara bağlanamaz. Panelden yapılan kaynak
testinde bunu birkaç kaynakta gördük:

    "Güvenlik sertifikası doğrulanamadı."

Bu liste curl'e açıkça gösterilerek sorun çözülüyor.

## Neden doğrulama kapatılmadı

`CURLOPT_SSL_VERIFYPEER => false` sorunu "çözer" ama siteyi araya giren
birinin sahte sertifikasına açık hale getirir. Eksik listeyi tamamlamak
doğru çözüm; doğrulama açık kalır.

## Güncelleme

Yılda bir yenilemek yeterli:

    https://curl.se/ca/cacert.pem

adresinden indirip bu dosyanın üzerine yazın.
