<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * Anthropic (Claude) anahtari gecersiz, yetki yok ya da kredi bitti.
 *
 * Beklemekle duzelmez: Yazar bu calismada Claude'u bir daha denemiyor,
 * istekler siradaki modele (Luna, sonra Gemini) gidiyor.
 */
final class ClaudeErisimYokException extends \RuntimeException
{
    /** @param string $tur 'kredi' (kredi bitti) ya da 'anahtar' (gecersiz anahtar, yetki yok) */
    public function __construct(string $mesaj, public readonly string $tur = 'anahtar')
    {
        parent::__construct($mesaj);
    }
}
