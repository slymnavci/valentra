<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * Gemini API ile görsel üretir (köşe yazarı portreleri; bkz. ajan/portre.php).
 *
 * Model GEMINI_GORSEL_MODEL ile secilir. "imagen-" ile baslayan modeller
 * :predict ucunu (Imagen), digerleri :generateContent ucunu IMAGE yanit
 * kipiyle kullanir; iki yanit bicimi de cozuluyor. Basarisizlikta
 * RuntimeException: sebep (HTTP kodu, API'nin mesaji) gunluge ve GitHub
 * ozetine gitsin, model adi ya da anahtar izni duzeltilebilsin.
 */
final class GorselUretici
{
    private const API = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /** Varsayılan görüntü modeli. */
    public const VARSAYILAN_MODEL = 'imagen-4.0-generate-001';

    private string $model;

    public function __construct(private readonly string $apiKey, string $model = '')
    {
        $model = trim($model);
        $this->model = preg_match('/^[a-z0-9][a-z0-9._-]{1,99}$/i', $model) ? $model : self::VARSAYILAN_MODEL;
    }

    public function model(): string
    {
        return $this->model;
    }

    /** Sonraki üretimler bu modelle (bkz. gorselModelleri). */
    public function modelSec(string $model): void
    {
        $this->model = $model;
    }

    /**
     * Bu anahtarla görsel üretebilen modeller (ListModels), en uygunu önce.
     *
     * Varsayilan model bir hesapta yoksa (23.10.2026: imagen-4.0 icin 404)
     * kullanilabilir olanlar buradan bulunuyor: once Imagen (:predict),
     * sonra goruntu ureten Gemini modelleri (adinda "image" gecen,
     * :generateContent). Adlar "models/" oneksiz.
     *
     * @return list<string>
     */
    public function gorselModelleri(): array
    {
        $ch = curl_init(self::API . '?pageSize=500');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['x-goog-api-key: ' . $this->apiKey],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $yanit = curl_exec($ch);
        curl_close($ch);

        $veri = is_string($yanit) ? json_decode($yanit, true) : null;
        $imagen = [];
        $gemini = [];

        foreach ((array) ($veri['models'] ?? []) as $m) {
            $ad      = preg_replace('#^models/#', '', (string) ($m['name'] ?? '')) ?? '';
            $yontem  = (array) ($m['supportedGenerationMethods'] ?? []);

            if (str_starts_with($ad, 'imagen') && in_array('predict', $yontem, true)) {
                $imagen[] = $ad;
            } elseif (str_contains($ad, 'image') && !str_contains($ad, 'embedding')
                && in_array('generateContent', $yontem, true)) {
                $gemini[] = $ad;
            }
        }

        // Yeni surum once (ad sirasi tersine: 4.0 > 3.0, 3-pro > 2.5).
        rsort($imagen);
        rsort($gemini);

        return array_values(array_merge($imagen, $gemini));
    }

    /** İstemden kare bir görsel üretir; ikili veri (PNG/JPEG). */
    public function uret(string $istem): string
    {
        $imagen = str_starts_with($this->model, 'imagen');
        $adres  = self::API . $this->model . ($imagen ? ':predict' : ':generateContent');
        $govde  = $imagen
            ? ['instances' => [['prompt' => $istem]],
               'parameters' => ['sampleCount' => 1, 'aspectRatio' => '1:1', 'personGeneration' => 'allow_adult']]
            : ['contents' => [['parts' => [['text' => $istem]]]],
               'generationConfig' => ['responseModalities' => ['IMAGE']]];

        $ch = curl_init($adres);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($govde, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $this->apiKey],
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $yanit = curl_exec($ch);
        $kod   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hata  = curl_error($ch);
        curl_close($ch);

        if (!is_string($yanit)) {
            throw new \RuntimeException('Görsel API\'sine ulaşılamadı: ' . $hata);
        }

        $veri = json_decode($yanit, true);

        if ($kod !== 200) {
            $mesaj = is_array($veri) ? (string) ($veri['error']['message'] ?? '') : '';

            throw new \RuntimeException('Görsel API HTTP ' . $kod . ' (' . $this->model . ')'
                . ($mesaj !== '' ? ': ' . mb_substr($mesaj, 0, 300) : ''));
        }

        $b64 = $veri['predictions'][0]['bytesBase64Encoded'] ?? null;

        if ($b64 === null) {
            foreach ($veri['candidates'][0]['content']['parts'] ?? [] as $parca) {
                if (isset($parca['inlineData']['data'])) {
                    $b64 = $parca['inlineData']['data'];
                    break;
                }
            }
        }

        $ikili = is_string($b64) ? base64_decode($b64, true) : false;

        if ($ikili === false || $ikili === '') {
            throw new \RuntimeException('Görsel API yanıtında resim yok (' . $this->model . '); model görsel üretmiyor ya da istem reddedildi.');
        }

        return $ikili;
    }
}
