<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * OpenAI anahtari gecersiz, yetki yok ya da kredi bitti.
 *
 * Beklemekle duzelmez: Yazar bu calismada OpenAI'yi bir daha denemiyor,
 * istekler dogrudan Gemini'ye gidiyor.
 */
final class OpenAiErisimYokException extends \RuntimeException
{
    /**
     * @param string $tur 'kredi' (kredi/kota bitti) ya da 'anahtar'
     *                    (gecersiz anahtar, yetki yok). Panel uyarisi
     *                    buna gore yaziliyor (api/ajan-durum.php).
     */
    public function __construct(string $mesaj, public readonly string $tur = 'anahtar')
    {
        parent::__construct($mesaj);
    }
}
