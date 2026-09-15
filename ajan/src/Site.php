<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * Valentra sitesiyle konuşur: yapılandırmayı çeker, haberleri gönderir.
 */
final class Site
{
    public function __construct(
        private readonly string $taban,
        private readonly string $anahtar,
        private readonly int $zamanAsimi = 30,
    ) {
    }

    /**
     * Taranacak kaynaklar ve konu grupları.
     *
     * @return array{kaynaklar:list<array<string,mixed>>,kategoriler:list<array<string,mixed>>}
     */
    public function yapilandirma(): array
    {
        [$kod, $govde] = $this->istek('GET', '/api/kaynaklar.php');

        if ($kod !== 200) {
            throw new \RuntimeException("Yapılandırma alınamadı (HTTP {$kod}): {$govde}");
        }

        $veri = json_decode($govde, true);

        if (!is_array($veri)) {
            throw new \RuntimeException('Yapılandırma yanıtı çözümlenemedi.');
        }

        return [
            'kaynaklar'   => $veri['kaynaklar'] ?? [],
            'kategoriler' => $veri['kategoriler'] ?? [],
        ];
    }

    /**
     * Haberleri taslak olarak gönderir.
     *
     * @param list<array<string,mixed>> $haberler
     * @return array<string,mixed>
     */
    public function gonder(array $haberler): array
    {
        if ($haberler === []) {
            return ['eklenen' => 0, 'yinelenen' => 0, 'hatalar' => []];
        }

        [$kod, $govde] = $this->istek(
            'POST',
            '/api/ingest.php',
            json_encode(['haberler' => $haberler], JSON_UNESCAPED_UNICODE)
        );

        $veri = json_decode($govde, true);

        if ($kod !== 200 || !is_array($veri)) {
            throw new \RuntimeException("Gönderim başarısız (HTTP {$kod}): {$govde}");
        }

        return $veri;
    }

    /**
     * @return array{0:int,1:string}
     */
    private function istek(string $yontem, string $yol, ?string $govde = null): array
    {
        $ch = curl_init(rtrim($this->taban, '/') . $yol);

        $basliklar = ['Authorization: Bearer ' . $this->anahtar];

        if ($govde !== null) {
            $basliklar[] = 'Content-Type: application/json';
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $yontem,
            CURLOPT_HTTPHEADER     => $basliklar,
            CURLOPT_TIMEOUT        => $this->zamanAsimi,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if ($govde !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $govde);
        }

        $yanit = curl_exec($ch);
        $kod   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hata  = curl_error($ch);
        curl_close($ch);

        if (!is_string($yanit)) {
            throw new \RuntimeException('Siteye ulaşılamadı: ' . $hata);
        }

        return [$kod, $yanit];
    }
}
