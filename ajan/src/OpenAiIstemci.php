<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * OpenAI Chat Completions istemcisi (varsayilan model: GPT-6 Luna).
 *
 * Site sahibinin karari (23.10.2026): haber toplama once Luna ile
 * calissin, basarisiz olursa Gemini ile devam etsin. Luna 1M token
 * basina $0.10 / $0.50; Gemini 3.1 Flash-Lite $0.25 / $1.50.
 *
 * Bu sinif yalnizca istegi atar ve JSON yaniti cozer. Yedege dusme
 * karari Yazar'da: buradaki her hata bir istisna, Yazar onu yakalayip
 * ayni istegi Gemini'ye gonderiyor.
 *
 * Yapisal cikti "json_schema" + strict ile: model semaya uymayan yanit
 * uretemiyor. Strict mod her nesnede additionalProperties:false ve
 * butun alanlarin "required" olmasini istiyor; Gemini semalari bunu
 * zaten saglıyor (butun alanlar required), eksik olan
 * additionalProperties'i semaya burada ekliyoruz.
 */
final class OpenAiIstemci
{
    private const API = 'https://api.openai.com/v1/chat/completions';

    /** @var array{girdi:int,cikti:int,istek:int} */
    private array $kullanim = ['girdi' => 0, 'cikti' => 0, 'istek' => 0];

    private readonly string $adres;

    /**
     * $taban: OpenAI uyumlu baska bir uc icin (OPENAI_API_TABAN, ornegin
     * Azure ya da testte sahte sunucu); bossa OpenAI.
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        string $taban = '',
    ) {
        $this->adres = $taban !== '' ? rtrim($taban, '/') . '/chat/completions' : self::API;
    }

    public function model(): string
    {
        return $this->model;
    }

    /** @return array{girdi:int,cikti:int,istek:int} */
    public function kullanim(): array
    {
        return $this->kullanim;
    }

    /**
     * Şemaya uyan JSON nesnesini döndürür; olmazsa istisna atar.
     *
     * @param array<string,mixed> $sema
     * @return array<string,mixed>
     * @throws OpenAiErisimYokException anahtar gecersiz / kredi yok: bu calismada bir daha deneme
     * @throws \RuntimeException diger her hata (ag, zaman asimi, kesik ya da bozuk yanit)
     */
    public function jsonIstek(string $yonerge, string $istem, array $sema, int $enFazlaToken): array
    {
        $govde = [
            'model'    => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $yonerge],
                ['role' => 'user', 'content' => $istem],
            ],
            'response_format' => [
                'type'        => 'json_schema',
                'json_schema' => [
                    'name'   => 'sonuc',
                    'strict' => true,
                    'schema' => self::kesinSema($sema),
                ],
            ],
            'max_completion_tokens' => $enFazlaToken,
        ];

        $yanit = $this->gonder($govde);
        $secim = $yanit['choices'][0] ?? null;

        if (!is_array($secim)) {
            throw new \RuntimeException('OpenAI yanıtında seçenek yok.');
        }

        $mesaj = $secim['message'] ?? [];

        if (is_array($mesaj) && is_string($mesaj['refusal'] ?? null) && $mesaj['refusal'] !== '') {
            throw new \RuntimeException('OpenAI isteği reddetti: ' . mb_substr($mesaj['refusal'], 0, 200, 'UTF-8'));
        }

        // Kesilmis JSON cozulse bile eksik olur; yedege birakmak daha iyi.
        if (($secim['finish_reason'] ?? '') === 'length') {
            throw new \RuntimeException('OpenAI yanıtı token sınırında kesildi.');
        }

        $metin = is_array($mesaj) ? ($mesaj['content'] ?? null) : null;
        $veri  = is_string($metin) ? json_decode($metin, true) : null;

        if (!is_array($veri)) {
            throw new \RuntimeException('OpenAI yanıtı JSON olarak çözülemedi.');
        }

        return $veri;
    }

    /**
     * @param array<string,mixed> $govde
     * @return array<string,mixed>
     */
    private function gonder(array $govde): array
    {
        $json = json_encode($govde, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($json)) {
            throw new \RuntimeException('OpenAI isteği JSON olarak hazırlanamadı.');
        }

        // Yedek var: uzun israr etmeye gerek yok, iki kisa yeniden deneme.
        $beklemeler = [3, 8];
        $sonMesaj   = '';

        for ($deneme = 0; $deneme <= count($beklemeler); $deneme++) {
            $ch = curl_init($this->adres);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $json,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apiKey,
                ],
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            ]);

            $ham  = curl_exec($ch);
            $kod  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $hata = curl_error($ch);
            curl_close($ch);

            if (!is_string($ham)) {
                $sonMesaj = 'ağ hatası: ' . ($hata !== '' ? $hata : 'bilinmiyor');
            } else {
                $veri = json_decode($ham, true);

                if ($kod >= 200 && $kod < 300 && is_array($veri)) {
                    $this->kullanimiTopla($veri);

                    return $veri;
                }

                $hataKodu = is_array($veri) ? (string) ($veri['error']['code'] ?? '') : '';
                $sonMesaj = 'HTTP ' . $kod . ': ' . (is_array($veri)
                    ? (string) ($veri['error']['message'] ?? '')
                    : mb_substr($ham, 0, 200, 'UTF-8'));

                // Anahtar gecersiz, yetki yok ya da kredi bitti: beklemekle
                // duzelmez, bu calismada Luna bir daha denenmemeli.
                if ($hataKodu === 'insufficient_quota' || str_starts_with($hataKodu, 'billing_')) {
                    throw new OpenAiErisimYokException($sonMesaj, 'kredi');
                }

                if (in_array($kod, [401, 403], true)) {
                    throw new OpenAiErisimYokException($sonMesaj, 'anahtar');
                }

                // 4xx (429 haric) istegin kendisiyle ilgili: yeniden denemek
                // ayni cevabi getirir.
                if ($kod < 500 && $kod !== 429) {
                    throw new \RuntimeException($sonMesaj);
                }
            }

            if ($deneme < count($beklemeler)) {
                sleep($beklemeler[$deneme]);
            }
        }

        throw new \RuntimeException($sonMesaj);
    }

    /** @param array<string,mixed> $veri */
    private function kullanimiTopla(array $veri): void
    {
        $olcum = $veri['usage'] ?? [];

        if (!is_array($olcum)) {
            return;
        }

        // completion_tokens akil yurutme tokenlarini da iceriyor (faturalanan).
        $this->kullanim['istek']++;
        $this->kullanim['girdi'] += (int) ($olcum['prompt_tokens'] ?? 0);
        $this->kullanim['cikti'] += (int) ($olcum['completion_tokens'] ?? 0);
    }

    /**
     * Strict mod icin her nesneye additionalProperties:false ekler.
     *
     * @param array<string,mixed> $sema
     * @return array<string,mixed>
     */
    public static function kesinSema(array $sema): array
    {
        if (($sema['type'] ?? null) === 'object') {
            $sema['additionalProperties'] = false;

            foreach ($sema['properties'] ?? [] as $ad => $alt) {
                if (is_array($alt)) {
                    $sema['properties'][$ad] = self::kesinSema($alt);
                }
            }
        }

        if (($sema['type'] ?? null) === 'array' && is_array($sema['items'] ?? null)) {
            $sema['items'] = self::kesinSema($sema['items']);
        }

        return $sema;
    }
}
