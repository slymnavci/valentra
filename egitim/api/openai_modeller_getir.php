<?php
/**
 * Kullanıcının sunucuda kayıtlı OpenAI API anahtarıyla erişebildiği
 * modelleri sorgular (GET /v1/models) ve yalnızca model KİMLİKLERİNİ
 * (id) döner.
 * GÜVENLİK: API anahtarının kendisi bu uç noktadan da (ai_sohbet.php
 * gibi) istemciye ASLA dönmez — yalnızca sunucu tarafında, OpenAI'a
 * istek atmak için okunur. Üyelik ve Ayarlar > Yapay Zekâ Asistanı
 * bölümündeki OpenAI model dropdown'ı bu listeyi kullanır.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/yetki.php';
ppYetkiKontrol();

$pdo = ppBaglan();
/* Kullanıcı kimliği istekten değil, doğrulanan oturum jetonundan alınır —
   başkasının kayıtlı OpenAI anahtarıyla model listesi sorgulanamaz. */
$oturum = ppOturumDogrula($pdo);
$kullaniciAdi = $oturum['kullaniciAdi'];

$sorgu = $pdo->prepare('SELECT anahtar FROM kullanici_sir WHERE kullanici_adi = ? AND saglayici = ?');
$sorgu->execute([$kullaniciAdi, 'openai']);
$satir = $sorgu->fetch();

if (!$satir || $satir['anahtar'] === '') {
    ppJsonYanit(['ok' => false, 'hata' => "OpenAI için Üyelik ve Ayarlar'da kayıtlı bir API anahtarı yok."], 400);
}
$anahtar = $satir['anahtar'];

$ch = curl_init('https://api.openai.com/v1/models');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $anahtar],
    CURLOPT_TIMEOUT => 20,
]);
$yanit = curl_exec($ch);
$curlHata = curl_error($ch);
$httpKod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($yanit === false) {
    ppJsonYanit(['ok' => false, 'hata' => "OpenAI'a bağlanılamadı: $curlHata"], 502);
}

$d = json_decode($yanit, true);

if ($httpKod < 200 || $httpKod >= 300) {
    $h = $d['error']['message'] ?? 'Bilinmeyen hata';
    ppJsonYanit(['ok' => false, 'hata' => "OpenAI hatası: $h — API anahtarınızın geçerli ve model listeleme izni olduğundan emin olun."], 502);
}

$hepsi = array_values(array_filter(array_map(
    fn($m) => (string)($m['id'] ?? ''),
    $d['data'] ?? []
), fn($id) => $id !== ''));

/* Bu asistanla kullanılamayacak (metin/sohbet üretmeyen) model
   ailelerini ele — kullanıcıya yalnızca sohbet için anlamlı modeller
   gösterilsin. */
$uygunDegil = '/embedding|whisper|tts|moderation|dall-e|[^a-z]image|davinci-002|babbage|realtime|audio|transcribe|computer-use/i';
$modeller = array_values(array_filter($hepsi, fn($id) => !preg_match($uygunDegil, $id)));

/* Öncelikli/önerilen modeller (gpt-5.6 ailesi) listenin başına alınır,
   geri kalanı alfabetik sıralanır. */
$oncelik = ['gpt-5.6-sol', 'gpt-5.6-terra', 'gpt-5.6-luna'];
usort($modeller, function ($a, $b) use ($oncelik) {
    $ia = array_search($a, $oncelik, true);
    $ib = array_search($b, $oncelik, true);
    $ia = $ia === false ? PHP_INT_MAX : $ia;
    $ib = $ib === false ? PHP_INT_MAX : $ib;
    if ($ia !== $ib) return $ia <=> $ib;
    return strcmp($a, $b);
});

ppJsonYanit(['ok' => true, 'modeller' => $modeller]);
