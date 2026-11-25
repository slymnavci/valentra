<?php
declare(strict_types=1);

namespace Valentra\Ajan;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;

/**
 * Anthropic Claude istemcisi (varsayilan model: Claude Haiku 5.5).
 *
 * Site sahibinin karari (23.10.2026): "once Haiku 5.5 baksin, sonra Luna,
 * en son bunlar da yapamiyorsa Gemini". Haiku 5.5 1M token basina
 * $0.10 / $0.50 (100K token'a kadar istemde).
 *
 * OpenAiIstemci ile ayni sozlesme: yalnizca istegi atar ve JSON yaniti
 * cozer; her hata bir istisna, Yazar onu yakalayip ayni istegi siradaki
 * modele (Luna, sonra Gemini) gonderiyor.
 *
 * Yapisal cikti output_config.format (json_schema): yanit semaya uymak
 * zorunda. Sema OpenAI'deki gibi her nesnede additionalProperties:false
 * ile (OpenAiIstemci::kesinSema). Haber yazimi okuyup ozetleme isi:
 * dusunme derinligi (effort) dusuk; daha az token, daha hizli yanit.
 */
final class ClaudeIstemci
{
    /** @var array{girdi:int,cikti:int,istek:int} */
    private array $kullanim = ['girdi' => 0, 'cikti' => 0, 'istek' => 0];

    /**
     * Onbellek ve toplu gonderim olcumleri (25.11.2026, site sahibi:
     * "maliyet artiyor, azaltalim"). girdi alani yalnizca onbellege
     * girmeyen token; okunan/yazilan ayri tutuluyor. toplu_*: Batch ile
     * yanitlanan istekler (yari fiyat).
     *
     * @var array{okuma:int,yazma:int,toplu_istek:int,toplu_girdi:int,toplu_cikti:int}
     */
    private array $onbellek = ['okuma' => 0, 'yazma' => 0, 'toplu_istek' => 0, 'toplu_girdi' => 0, 'toplu_cikti' => 0];

    private readonly Client $istemci;

    public function __construct(string $apiKey, private readonly string $model)
    {
        $this->istemci = new Client(apiKey: $apiKey);
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

    /** @return array{okuma:int,yazma:int,toplu_istek:int,toplu_girdi:int,toplu_cikti:int} */
    public function onbellek(): array
    {
        return $this->onbellek;
    }

    /**
     * Yonerge (sistem istemi) onbellege alinacak blok olarak. Haber
     * yonergesi ~100 bin karakter ve bir calismada onlarca istekte aynen
     * gidiyor; onbellekten okunan kisim normal girdi fiyatinin onda biri.
     * Kisa yonergede (en az onbellek boyunun altinda) isaret zararsiz:
     * API sessizce onbelleksiz isler.
     *
     * @return list<array<string,mixed>>
     */
    private static function sistem(string $yonerge): array
    {
        return [['type' => 'text', 'text' => $yonerge, 'cacheControl' => ['type' => 'ephemeral']]];
    }

    /**
     * Şemaya uyan JSON nesnesini döndürür; olmazsa istisna atar.
     *
     * @param array<string,mixed> $sema
     * @return array<string,mixed>
     * @throws ClaudeErisimYokException anahtar gecersiz / kredi yok: bu calismada bir daha deneme
     * @throws \RuntimeException diger her hata (ag, zaman asimi, kesik ya da bozuk yanit)
     */
    public function jsonIstek(string $yonerge, string $istem, array $sema, int $enFazlaToken): array
    {
        try {
            $mesaj = $this->istemci->messages->create(
                maxTokens: $enFazlaToken,
                messages: [['role' => 'user', 'content' => $istem]],
                model: $this->model,
                system: self::sistem($yonerge),
                outputConfig: [
                    'effort' => 'low',
                    'format' => ['type' => 'json_schema', 'schema' => OpenAiIstemci::kesinSema($sema)],
                ],
                // Yedek var: uzun israr etmeye gerek yok. SDK 429 ve 5xx'i
                // kendisi yeniden deniyor.
                requestOptions: ['timeout' => 240.0, 'maxRetries' => 2],
            );
        } catch (AuthenticationException | PermissionDeniedException $e) {
            throw new ClaudeErisimYokException($this->hataMesaji($e), 'anahtar');
        } catch (BadRequestException $e) {
            // Anthropic kredi bitince 400 donduruyor ("credit balance is too low").
            if (stripos($e->getMessage(), 'credit balance') !== false) {
                throw new ClaudeErisimYokException($this->hataMesaji($e), 'kredi');
            }

            throw new \RuntimeException($this->hataMesaji($e), 0, $e);
        } catch (RateLimitException $e) {
            throw new \RuntimeException('hız sınırı: ' . $this->hataMesaji($e), 0, $e);
        } catch (APIStatusException $e) {
            if ($e->type?->value === 'billing_error') {
                throw new ClaudeErisimYokException($this->hataMesaji($e), 'kredi');
            }

            throw new \RuntimeException($this->hataMesaji($e), 0, $e);
        } catch (APIConnectionException $e) {
            throw new \RuntimeException('ağ hatası: ' . $e->getMessage(), 0, $e);
        }

        $this->olc($mesaj, false);

        return $this->coz($mesaj);
    }

    private function olc(\Anthropic\Messages\Message $mesaj, bool $toplu): void
    {
        $this->kullanim['istek']++;
        $this->kullanim['girdi'] += $mesaj->usage->inputTokens;
        // output_tokens dusunme tokenlarini da iceriyor (faturalanan).
        $this->kullanim['cikti'] += $mesaj->usage->outputTokens;
        $this->onbellek['okuma'] += (int) ($mesaj->usage->cacheReadInputTokens ?? 0);
        $this->onbellek['yazma'] += (int) ($mesaj->usage->cacheCreationInputTokens ?? 0);

        if ($toplu) {
            $this->onbellek['toplu_istek']++;
            $this->onbellek['toplu_girdi'] += $mesaj->usage->inputTokens;
            $this->onbellek['toplu_cikti'] += $mesaj->usage->outputTokens;
        }
    }

    /**
     * @return array<string,mixed>
     * @throws \RuntimeException
     */
    private function coz(\Anthropic\Messages\Message $mesaj): array
    {
        if ($mesaj->stopReason === 'refusal') {
            throw new \RuntimeException('Claude isteği reddetti'
                . ($mesaj->stopDetails?->category !== null ? ' (' . $mesaj->stopDetails->category . ')' : '') . '.');
        }

        // Kesilmis JSON cozulse bile eksik olur; siradaki modele birakmak daha iyi.
        if ($mesaj->stopReason === 'max_tokens') {
            throw new \RuntimeException('Claude yanıtı token sınırında kesildi.');
        }

        foreach ($mesaj->content as $blok) {
            if ($blok->type === 'text') {
                $veri = json_decode($blok->text, true);

                if (is_array($veri)) {
                    return $veri;
                }
            }
        }

        throw new \RuntimeException('Claude yanıtı JSON olarak çözülemedi.');
    }

    /**
     * TOPLU GONDERIM (Message Batches; 25.11.2026, site sahibi: "maliyet
     * artiyor" — ilk iki oneri). Istekler tek seferde gonderilir, Anthropic
     * yari fiyatla isler. Sonuc birkac dakikadan bir saate kadar surebilir;
     * $beklemeSn dolunca toplu is iptal edilir, o ana kadar biten yanitlar
     * yine alinir. Yaniti gelmeyen ya da hatali istek sonuc dizisinde
     * Throwable olarak doner: cagiran onu tek tek (normal fiyatla, yedek
     * model zinciriyle) yeniden ister. Hicbir istek kaybolmaz.
     *
     * @param array<string,array{yonerge:string,istem:string,sema:array<string,mixed>,token:int}> $istekler anahtar => istek
     * @param callable(string):void $gunluk
     * @return array<string,array<string,mixed>|\Throwable>
     * @throws ClaudeErisimYokException anahtar gecersiz / kredi yok
     */
    public function topluGonder(array $istekler, int $beklemeSn, callable $gunluk): array
    {
        $sonuc = [];

        if ($istekler === []) {
            return $sonuc;
        }

        $liste = [];
        $kimlik = [];
        $i = 0;

        foreach ($istekler as $anahtar => $istek) {
            // custom_id: harf, rakam, - ve _ (en fazla 64).
            $id = 'g' . $i++;
            $kimlik[$id] = $anahtar;
            $liste[] = ['customID' => $id, 'params' => [
                'model'        => $this->model,
                'maxTokens'    => $istek['token'],
                'system'       => self::sistem($istek['yonerge']),
                'messages'     => [['role' => 'user', 'content' => $istek['istem']]],
                'outputConfig' => [
                    'effort' => 'low',
                    'format' => ['type' => 'json_schema', 'schema' => OpenAiIstemci::kesinSema($istek['sema'])],
                ],
            ]];
        }

        try {
            $is = $this->istemci->messages->batches->create(requests: $liste, requestOptions: ['timeout' => 120.0, 'maxRetries' => 2]);
        } catch (AuthenticationException | PermissionDeniedException $e) {
            throw new ClaudeErisimYokException($this->hataMesaji($e), 'anahtar');
        } catch (\Throwable $e) {
            if ($e instanceof APIStatusException && ($e->type?->value === 'billing_error' || stripos($e->getMessage(), 'credit balance') !== false)) {
                throw new ClaudeErisimYokException($this->hataMesaji($e), 'kredi');
            }

            $hata = new \RuntimeException('toplu gönderim başlatılamadı: ' . mb_substr($e->getMessage(), 0, 300, 'UTF-8'), 0, $e);

            foreach ($istekler as $anahtar => $_) {
                $sonuc[$anahtar] = $hata;
            }

            return $sonuc;
        }

        $gunluk(sprintf('Toplu gönderim başladı: %d istek (%s).', count($liste), $is->id));
        $son   = time() + $beklemeSn;
        $bekle = 15;
        $durum = $is->processingStatus;

        while ($durum !== 'ended' && time() < $son) {
            sleep(min($bekle, max(1, $son - time())));
            $bekle = min(60, $bekle + 15);

            try {
                $is    = $this->istemci->messages->batches->retrieve($is->id, requestOptions: ['timeout' => 60.0, 'maxRetries' => 2]);
                $durum = $is->processingStatus;
            } catch (\Throwable $e) {
                // Gecici; bir sonraki yoklamada yeniden denenir.
            }
        }

        if ($durum !== 'ended') {
            $gunluk(sprintf('Toplu gönderim %d dakikada bitmedi; iptal ediliyor, biten yanıtlar alınacak, kalanlar tek tek istenecek.', intdiv($beklemeSn, 60)));

            try {
                $this->istemci->messages->batches->cancel($is->id);
            } catch (\Throwable $e) {
            }

            // Iptal birkac saniyede "ended" olur; sonuclar ancak o zaman okunabiliyor.
            for ($k = 0; $k < 12 && $durum !== 'ended'; $k++) {
                sleep(5);

                try {
                    $durum = $this->istemci->messages->batches->retrieve($is->id)->processingStatus;
                } catch (\Throwable $e) {
                }
            }
        }

        if ($durum === 'ended') {
            try {
                foreach ($this->istemci->messages->batches->resultsStream($is->id, requestOptions: ['timeout' => 120.0]) as $yanit) {
                    $anahtar = $kimlik[$yanit->customID] ?? null;

                    if ($anahtar === null) {
                        continue;
                    }

                    $sonucu = $yanit->result;

                    if ($sonucu->type !== 'succeeded') {
                        $sonuc[$anahtar] = new \RuntimeException('toplu yanıt: ' . $sonucu->type);
                        continue;
                    }

                    $this->olc($sonucu->message, true);

                    try {
                        $sonuc[$anahtar] = $this->coz($sonucu->message);
                    } catch (\Throwable $e) {
                        $sonuc[$anahtar] = $e;
                    }
                }
            } catch (\Throwable $e) {
                $gunluk('Toplu sonuçlar okunamadı: ' . mb_substr($e->getMessage(), 0, 200, 'UTF-8'));
            }
        }

        foreach ($istekler as $anahtar => $_) {
            $sonuc[$anahtar] ??= new \RuntimeException('toplu yanıt gelmedi');
        }

        $gunluk(sprintf('Toplu gönderim bitti: %d istekten %d yanıt alındı.', count($liste),
            count(array_filter($sonuc, static fn ($v): bool => is_array($v)))));

        return $sonuc;
    }

    private function hataMesaji(APIStatusException $e): string
    {
        return 'HTTP ' . ($e->status ?? 0) . ' ' . ($e->type?->value ?? '') . ': '
            . mb_substr($e->getMessage(), 0, 300, 'UTF-8');
    }
}
