<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * Yöneticinin bilmesi ve bir şey yapması gereken sorunlar.
 *
 * Eskiden gönderim düştüğünde ajan bunu yalnızca günlüğe yazıyor ve
 * çalışma yeşil bitiyordu: haberler günlerce siteye ulaşmadığı halde
 * kimse fark etmiyordu. Artık her sorun iki yere yazılıyor:
 *
 *   - çalışmanın özet sayfası (Actions'ta çalışmayı açınca ilk görünen)
 *   - AJAN_SORUN_DOSYASI: iş akışının son adımı bu dosyayı okuyup
 *     çalışmayı KIRMIZI bitiriyor. GitHub kırmızı çalışmayı e-postayla
 *     bildiriyor, panel de nedenini gösteriyor.
 *
 * Çalışmayı burada durdurmuyoruz: sonraki adımlar (köşe yazısı, ajan
 * deposunun kaydı) yine koşmalı. Kırmızıya çevirme işi son adımda.
 *
 * GitHub dışında (yerelde) çalışırken yalnızca günlüğe yazar.
 */
final class Bildirim
{
    /** Çalışmayı kırmızı bitirecek, yöneticinin müdahale etmesi gereken sorun. */
    public static function sorun(string $mesaj): void
    {
        self::yaz('❌ ' . $mesaj);

        $dosya = getenv('AJAN_SORUN_DOSYASI');

        if (is_string($dosya) && $dosya !== '') {
            @file_put_contents($dosya, self::tekSatir($mesaj) . "\n", FILE_APPEND);
        }
    }

    /** Bilinsin ama çalışmayı kırmızıya çevirmesin (ör. model kotası). */
    public static function uyari(string $mesaj): void
    {
        self::yaz('⚠️ ' . $mesaj);

        if (getenv('GITHUB_ACTIONS') === 'true') {
            echo '::warning::' . self::kacir($mesaj) . PHP_EOL;
        }
    }

    private static function yaz(string $satir): void
    {
        $ozet = getenv('GITHUB_STEP_SUMMARY');

        if (is_string($ozet) && $ozet !== '') {
            @file_put_contents($ozet, '- ' . self::tekSatir($satir) . "\n", FILE_APPEND);
        }
    }

    private static function tekSatir(string $metin): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $metin));
    }

    /** GitHub iş akışı komutlarında %, satır sonu özel anlamlı. */
    private static function kacir(string $metin): string
    {
        return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $metin);
    }
}
