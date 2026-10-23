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
                system: $yonerge,
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

        $this->kullanim['istek']++;
        $this->kullanim['girdi'] += $mesaj->usage->inputTokens;
        // output_tokens dusunme tokenlarini da iceriyor (faturalanan).
        $this->kullanim['cikti'] += $mesaj->usage->outputTokens;

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

    private function hataMesaji(APIStatusException $e): string
    {
        return 'HTTP ' . ($e->status ?? 0) . ' ' . ($e->type?->value ?? '') . ': '
            . mb_substr($e->getMessage(), 0, 300, 'UTF-8');
    }
}
