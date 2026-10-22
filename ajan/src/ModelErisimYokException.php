<?php
declare(strict_types=1);

namespace Valentra\Ajan;

/**
 * Model (Gemini) hiç kullanılamıyor: kredi bitmiş, anahtar geçersiz ya da
 * yetkisiz. Günlük kotadan farkı, beklemekle düzelmemesi; yöneticinin bir
 * şey yapması gerekiyor.
 *
 * KotaBittiException'dan türüyor: mevcut yakalayıcılar kalan adayları
 * denemeden çalışmayı erken bitiriyor (hepsi aynı duvara çarpardı).
 * Çağıranlar ayrıca Bildirim::sorun ile çalışmayı kırmızıya çeviriyor;
 * Ekim 2026'da ön ödemeli kredi bittiğinde ajan iki gün boyunca "0 haber
 * yazıldı" deyip yeşil bitti ve kimse fark etmedi.
 *
 * Mesaj yöneticiye gösterilecek açıklama: ne oldu, ne yapmalı.
 */
final class ModelErisimYokException extends KotaBittiException
{
    /** Gemini yanıtı bu türden mi? Değilse null, öyleyse açıklama. */
    public static function aciklama(int $kod, string $mesaj): ?string
    {
        $ham = ' (Google: ' . mb_substr(trim($mesaj), 0, 200, 'UTF-8') . ')';

        if ($kod === 402 || stripos($mesaj, 'credits are depleted') !== false
            || ($kod !== 429 && stripos($mesaj, 'billing') !== false)) {
            return 'Gemini ön ödemeli kredisi bitti; haber, köşe yazısı ve pratik bilgi yazılamıyor. '
                 . 'AI Studio (https://ai.studio/projects) → projeniz → Billing bölümünden kredi yükleyin.' . $ham;
        }

        if ($kod === 401 || stripos($mesaj, 'API key not valid') !== false || stripos($mesaj, 'API_KEY_INVALID') !== false
            || stripos($mesaj, 'API key expired') !== false) {
            return 'Gemini API anahtarı geçersiz ya da süresi dolmuş. AI Studio\'dan yeni anahtar alıp '
                 . 'GitHub → depo Settings → Secrets → GEMINI_API_KEY değerini güncelleyin.' . $ham;
        }

        if ($kod === 403) {
            return 'Gemini API anahtarının bu modele erişim izni yok (API kapatılmış ya da proje kısıtlı). '
                 . 'AI Studio\'da anahtarın projesini ve Gemini API\'nin açık olduğunu kontrol edin.' . $ham;
        }

        return null;
    }
}
