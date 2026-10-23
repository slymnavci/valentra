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
}
