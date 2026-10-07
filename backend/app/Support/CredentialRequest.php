<?php

namespace App\Support;

/**
 * Questions asking for a secret: a password, a PIN, an access code.
 *
 * Refused as a category, before retrieval is consulted at all, because the
 * right answer does not depend on what any page happens to contain.
 *
 * Measured: "wifi şifresi ne" was answered `Wi-Fi şifresi genellikle
 * "ARUCAD" olarak ayarlanmıştır` — invented, confidently, and survived every
 * grounding check because there is no URL, figure or acronym in it to verify.
 * A student reads that, fails to connect, and stops trusting the assistant.
 *
 * Two reasons this is a rule rather than a ranking or grounding problem:
 *
 *  - A credential found in crawled public text is either stale or was never
 *    meant to be public. Repeating it is wrong even when it is accurate.
 *  - "I don't know" is not the useful answer either. The useful answer is
 *    who to ask, and that is the same answer every time — so a human writes
 *    it once instead of a model composing it from fragments.
 */
class CredentialRequest
{
    /** Words that name a secret. */
    private const SECRETS = [
        // tr
        'sifre', 'parola', 'wifi sifresi', 'kablosuz sifre', 'pin kodu',
        'erisim kodu', 'giris kodu', 'kullanici adi ve sifre',
        // en
        'password', 'passcode', 'wifi password', 'wi fi password',
        'pin code', 'access code', 'login code', 'credentials',
        // ru
        'пароль', 'пароля', 'пин код', 'код доступа',
    ];

    /**
     * Words that turn "şifre" into something other than a request for one.
     *
     * A student asking how to RESET or CHANGE a password wants a procedure,
     * which is a normal support question we should answer from the pages.
     * Only "what is it" is refused.
     */
    private const PROCEDURAL = [
        'nasil degistir', 'degistirmek', 'sifirla', 'sifirlama', 'unuttum',
        'yenile', 'reset', 'change my', 'forgot', 'recover', 'сменить',
        'изменить', 'восстановить', 'забыл',
    ];

    public static function isCredentialRequest(string $query): bool
    {
        $q = TextFold::fold($query);
        if ($q === '') {
            return false;
        }

        foreach (self::PROCEDURAL as $marker) {
            if (str_contains($q, TextFold::fold($marker))) {
                return false;
            }
        }

        foreach (self::SECRETS as $secret) {
            if (str_contains($q, TextFold::fold($secret))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Declines on principle and says where to go.
     *
     * Deliberately not "I could not find it": that invites the student to
     * ask again in the hope of a different answer. We will not carry
     * credentials at all.
     */
    public static function refusal(?string $language): string
    {
        return match ($language) {
            'en' => 'I do not hold passwords or access codes, and I will not '
                .'guess one. Campus Wi-Fi and account credentials come from IT '
                .'Support or your student account page — ask there and you will '
                .'get the current one.',
            'ru' => 'У меня нет паролей и кодов доступа, и я не буду их угадывать. '
                .'Пароль от кампусного Wi-Fi и данные учётной записи выдаёт '
                .'IT-поддержка или страница вашего студенческого аккаунта.',
            default => 'Şifre ve erişim kodlarını tutmuyorum, tahmin de etmem. '
                .'Kampüs Wi-Fi şifresi ve hesap bilgilerini BT Destek biriminden '
                .'veya öğrenci hesap sayfandan alabilirsin — güncel olanı orada.',
        };
    }
}
