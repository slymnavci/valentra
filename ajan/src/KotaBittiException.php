<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * Günlük model kotası tükendiğinde atılır.
 *
 * Bu durumda kalan adayları denemenin anlamı yok; hepsi aynı duvara
 * çarpar ve çalışma boşuna uzar. Toplayıcı bunu yakalayıp o ana kadar
 * yazılanları gönderir ve çalışmayı sonlandırır.
 */
final class KotaBittiException extends \RuntimeException
{
}
