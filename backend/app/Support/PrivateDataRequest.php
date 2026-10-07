<?php

namespace App\Support;

/**
 * Questions asking for someone else's private information, or for internal
 * records a student is not entitled to see.
 *
 * SEPARATE FROM A MISSING CAPABILITY, ON PURPOSE
 *
 * PersonalDataCapabilities answers "we could tell you, but that system is not
 * connected yet". This answers "we will not tell you, and connecting a system
 * would not change that". A student's own timetable becomes available the day
 * SIS is wired up; the rector's mobile number never does. Collapsing the two
 * into one list — which is what the code did before — produced a message that
 * was wrong for whichever case it was not written for, and quietly implied
 * that the confidential budget report was merely an integration away.
 *
 * WHY THE PATTERN IS TWO-PART
 *
 * "Address" is in "what is the campus address?". "Phone" is in "what is the
 * library's phone number?". Both are ordinary questions with published
 * answers. What makes a request private is the pairing of a PERSONAL
 * attribute with a PERSON, or an explicitly INTERNAL record: "the rector's
 * personal mobile", "the library director's home address", "the confidential
 * budget". So an attribute alone never fires, exactly as in PromptInjection.
 */
final class PrivateDataRequest
{
    /**
     * Attributes that are private when they belong to a named individual.
     *
     * @var list<string>
     */
    private const PERSONAL_ATTRIBUTES = [
        // English
        'personal mobile', 'personal phone', 'private phone', 'private mobile',
        'mobile number', 'cell number', 'home address', 'house address',
        'private address', 'home phone', 'personal email', 'private email',
        'salary', 'id number', 'passport', 'date of birth', 'personal number',
        // Turkish
        'cep telefonu', 'cep numarasi', 'ozel telefon', 'kisisel telefon',
        'ev adresi', 'ev adres', 'ozel adres', 'ikametgah', 'ev telefonu',
        'kisisel eposta', 'ozel eposta', 'maasi', 'maas', 'kimlik numarasi',
        'tc kimlik', 'dogum tarihi', 'pasaport',
        // Russian
        'личный телефон', 'мобильный номер', 'домашний адрес', 'личный адрес',
        'личная почта', 'зарплата', 'номер паспорта', 'дата рождения',
    ];

    /**
     * Words that indicate the attribute belongs to a specific person.
     *
     * @var list<string>
     */
    private const PERSON_MARKERS = [
        // English
        'rector', 'dean', 'director', 'head of', 'professor', 'lecturer',
        'instructor', 'teacher', 'staff', 'employee', 'student', 'secretary',
        'manager', 'chief', 'president', 'his', 'her', 'their',
        // Turkish
        'rektor', 'dekan', 'mudur', 'muduru', 'baskan', 'hoca', 'hocanin',
        'ogretim uyesi', 'akademisyen', 'personel', 'calisan', 'ogrenci',
        'sekreter', 'yonetici', 'amir', 'sorumlusu',
        // Russian
        'ректор', 'декан', 'директор', 'профессор', 'преподаватель',
        'сотрудник', 'студент', 'секретарь', 'руководитель',
    ];

    /**
     * Records that are internal regardless of whose they are. These fire on
     * their own.
     *
     * @var list<string>
     */
    private const INTERNAL_RECORDS = [
        // English
        'confidential budget', 'secret budget', 'internal budget',
        'confidential report', 'internal report', 'secret report',
        'disciplinary file of', 'personnel file', 'hr file',
        'how many students were expelled', 'expulsion statistics',
        'students were dismissed', 'salary list', 'payroll',
        // Turkish
        'gizli butce', 'gizli butcesi', 'gizli rapor', 'ic rapor',
        'gizli belge', 'personel dosyasi', 'ozluk dosyasi',
        'kac ogrenci atildi', 'atilan ogrenci sayisi', 'uzaklastirilan ogrenci',
        'maas listesi', 'bordro',
        // Russian
        'секретный бюджет', 'конфиденциальный бюджет', 'внутренний отчет',
        'конфиденциальный отчет', 'личное дело', 'сколько студентов отчислили',
        'отчислили студентов', 'список зарплат',
    ];

    /** How close the attribute and the person have to be to be one request. */
    private const WINDOW = 40;

    /** Is this asking for private information about someone, or an internal record? */
    public static function isPrivate(string $query): bool
    {
        $folded = TextFold::fold($query);
        if ($folded === '') {
            return false;
        }
        $folded = preg_replace('/\s+/u', ' ', $folded) ?? $folded;

        foreach (self::INTERNAL_RECORDS as $record) {
            if (str_contains($folded, TextFold::fold($record))) {
                return true;
            }
        }

        foreach (self::PERSONAL_ATTRIBUTES as $attribute) {
            $needle = TextFold::fold($attribute);
            $offset = 0;
            while (($at = strpos($folded, $needle, $offset)) !== false) {
                $from = max(0, $at - self::WINDOW);
                $window = substr($folded, $from, ($at - $from) + strlen($needle) + self::WINDOW);
                foreach (self::PERSON_MARKERS as $marker) {
                    if (str_contains($window, TextFold::fold($marker))) {
                        return true;
                    }
                }
                $offset = $at + 1;
            }
        }

        return false;
    }

    /**
     * The refusal, in the student's language.
     *
     * It declines on principle rather than on availability — saying "I cannot
     * verify that" about a colleague's home address would imply that a better
     * source would have made it fine.
     */
    public static function refusal(?string $language): string
    {
        return match ($language) {
            'en' => 'I will not share personal details about individuals or internal '
                .'ARUCAD records, even where I might have access to them. For official '
                .'contact details, the published directory on arucad.edu.tr and Student '
                .'Affairs in Titan are the right places to ask.',
            'ru' => 'Я не раскрываю личные данные людей и внутренние документы ARUCAD, '
                .'даже если они мне доступны. Официальные контакты есть в справочнике '
                .'на arucad.edu.tr, а также в отделе по работе со студентами в Titan.',
            default => 'Kişilere ait özel bilgileri veya ARUCAD\'ın iç kayıtlarını, '
                .'erişimim olsa bile paylaşmam. Resmî iletişim bilgileri için '
                .'arucad.edu.tr\'deki rehbere veya Titan\'daki Öğrenci İşleri\'ne '
                .'başvurabilirsin.',
        };
    }
}
