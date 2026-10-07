<?php

namespace App\Services\Ai\Facts;

/**
 * Completes an answer that silently skipped a task's verified part.
 *
 * The model words facts; it does not decide which verified facts the
 * student is told about. When the final answer uses none of a task's facts
 * (a supported task, or the known destination of a route that lacks an
 * origin), one short sentence is appended from those facts — the
 * system stating its own verified value, with no extra model call.
 * Only fact types with an unambiguous one-line statement are supplemented.
 */
final class FactSupplement
{
    private const TEMPLATES = [
        'tr' => ['place_coordinates' => 'Konum: %s binası.', 'current_opening_hours' => 'Çalışma saatleri: %s.',
            'is_open_now' => ['Şu an açık.', 'Şu an kapalı.'], 'program_language' => '%s programının eğitim dili %s.',
            'programme_duration' => '%s programının eğitim süresi %s.', 'food_places' => 'Kayıtlı yemek yeri: %s.'],
        'en' => ['place_coordinates' => 'Location: the %s building.', 'current_opening_hours' => 'Opening hours: %s.',
            'is_open_now' => ['It is open now.', 'It is closed now.'], 'program_language' => '%s is taught in %s.',
            'programme_duration' => 'The %s programme takes %s.', 'food_places' => 'Recorded food place: %s.'],
        'ru' => ['place_coordinates' => 'Местоположение: здание %s.', 'current_opening_hours' => 'Часы работы: %s.',
            'is_open_now' => ['Сейчас открыто.', 'Сейчас закрыто.'], 'program_language' => 'Язык обучения программы %s: %s.',
            'programme_duration' => 'Срок обучения по программе %s: %s.', 'food_places' => 'Место питания в базе: %s.'],
    ];

    /** What each task asked for, said plainly, for an answer that has none of it. */
    private const TASK_PHRASES = [
        'tr' => ['club_social_profile' => 'kulübün resmî sosyal medya hesabı', 'location' => 'konum bilgisi', 'route' => 'rota', 'opening_hours' => 'çalışma saatleri',
            'filter_open_now' => 'yerlerin açık olup olmadığı', 'find_food_places' => 'yemek yerleri', 'rank_by_distance' => 'en yakın yer', 'current_menu' => 'bugünkü menü',
            'current_events' => 'güncel etkinlikler', 'required_documents' => 'gerekli belgelerin listesi', 'program_language' => 'programın eğitim dili',
            'programme_duration' => 'programın eğitim süresi', 'academic_dates' => 'akademik takvim tarihi', 'contact_details' => 'iletişim bilgisi'],
        'en' => ['club_social_profile' => "the club's official social-media account", 'location' => 'the location', 'route' => 'a route', 'opening_hours' => 'the opening hours',
            'filter_open_now' => "the places' current opening status", 'find_food_places' => 'food places', 'rank_by_distance' => 'the nearest place', 'current_menu' => "today's menu",
            'current_events' => 'current events', 'required_documents' => 'the list of required documents', 'program_language' => "the programme's language of instruction",
            'programme_duration' => "the programme's duration", 'academic_dates' => 'the academic calendar date', 'contact_details' => 'the contact details'],
        'ru' => ['club_social_profile' => 'официальный аккаунт клуба в соцсетях', 'location' => 'местоположение', 'route' => 'маршрут', 'opening_hours' => 'часы работы',
            'filter_open_now' => 'открыты ли места сейчас', 'find_food_places' => 'места питания', 'rank_by_distance' => 'ближайшее место', 'current_menu' => 'меню на сегодня',
            'current_events' => 'текущие мероприятия', 'required_documents' => 'список необходимых документов', 'program_language' => 'язык обучения программы',
            'programme_duration' => 'срок обучения по программе', 'academic_dates' => 'дата академического календаря', 'contact_details' => 'контактные данные'],
    ];

    private const LANGUAGE_NAMES = [
        'tr' => ['en' => 'İngilizce', 'tr' => 'Türkçe'],
        'en' => ['en' => 'English', 'tr' => 'Turkish'],
        'ru' => ['en' => 'английский', 'tr' => 'турецкий'],
    ];

    /**
     * @param  list<string>  $usedFactIds
     * @return array{text: string, fact_ids: list<string>} sentences to append (empty when nothing was skipped)
     */
    public function forSkippedTasks(FactResult $facts, array $usedFactIds, ?string $language): array
    {
        $lang = isset(self::TEMPLATES[$language ?? '']) ? $language : 'tr';
        $sentences = $ids = [];
        foreach ($facts->plan->tasks as $task) {
            // Any task with a verified part — supported, partial or missing
            // only its context (a route without an origin) — keeps that part.
            if ($task['fact_ids'] === [] || array_intersect($task['fact_ids'], $usedFactIds) !== []) {
                continue;
            }
            foreach ($task['fact_ids'] as $id) {
                $fact = $facts->fact($id);
                $sentence = $fact === null ? null : $this->sentence($fact, $lang);
                if ($sentence !== null) {
                    $sentences[] = $sentence;
                    $ids[] = $id;
                }
            }
        }

        return ['text' => implode(' ', $sentences), 'fact_ids' => $ids];
    }

    /** Whether a task's verified part can be restated by a template (no model call). */
    public function canRestate(FactResult $facts, string $taskId): bool
    {
        foreach ($facts->plan->task($taskId)['fact_ids'] ?? [] as $id) {
            $fact = $facts->fact($id);
            if ($fact !== null && $this->sentence($fact, 'tr') !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The answer to a plan with NO supported fact (outcome UNAVAILABLE): what
     * was asked and is not in the current data, said by the system itself.
     * The model is not called for it — with nothing verified to say, a
     * generated answer can only add unverifiable text (measured in the 4D
     * smoke pack: an invented Instagram handle and a guessed room).
     */
    public function absence(FactResult $facts, ?string $language): string
    {
        $lang = isset(self::TASK_PHRASES[$language ?? '']) ? $language : 'tr';
        $asked = array_values(array_unique(array_filter(array_map(fn (array $t) => self::TASK_PHRASES[$lang][$t['task_type']] ?? null, $facts->plan->tasks))));
        $list = implode('; ', $asked);

        return match ($lang) {
            'en' => "I couldn't find this in the current data".($list !== '' ? ": {$list}" : '').'. I can answer once it has been recorded.',
            'ru' => 'В текущих данных не найдено'.($list !== '' ? ": {$list}" : '').'. Я смогу ответить, когда эти данные будут внесены.',
            default => 'Bunu mevcut verilerde bulamadım'.($list !== '' ? ": {$list}" : '').'. Bu bilgiler kayda geçtiğinde yanıtlayabilirim.',
        };
    }

    private function sentence(SupportedFact $fact, string $lang): ?string
    {
        $template = self::TEMPLATES[$lang][$fact->factType] ?? null;

        return match ($fact->factType) {
            'place_coordinates' => sprintf($template, $fact->subject['name'] ?? ''),
            'current_opening_hours' => sprintf($template, $fact->display()),
            'is_open_now' => $template[$fact->value ? 0 : 1],
            'program_language' => isset($fact->subject['name'])
                ? sprintf($template, $fact->subject['name'], self::LANGUAGE_NAMES[$lang][$fact->normalizedValue] ?? (string) $fact->value) : null,
            'programme_duration' => isset($fact->subject['name']) && ($length = ProgrammeDuration::phrase($fact->normalizedValue, $lang)) !== null
                ? sprintf($template, $fact->subject['name'], $length) : null,
            'food_places' => sprintf($template, (string) $fact->value),
            default => null,
        };
    }

    /**
     * When the final answer reports no gap at all but some tasks had nothing
     * verified, one sentence saying what was not found — so a partial answer
     * never reads as complete (measured in the 4D smoke pack: after an
     * unsupported "it is open" was removed, nothing said the hours were
     * unknown). Empty when the answer already reports a gap.
     */
    public function forUnstatedGaps(FactResult $facts, string $answer, ?string $language): string
    {
        if (app(ClaimVerifier::class)->reportsGap($answer)) {
            return '';
        }
        $lang = isset(self::TASK_PHRASES[$language ?? '']) ? $language : 'tr';
        $missing = array_values(array_unique(array_filter(array_map(
            fn (array $t) => $t['fact_ids'] === [] && in_array($t['status'], [AnswerPlan::UNAVAILABLE, AnswerPlan::INSUFFICIENT, AnswerPlan::STALE], true)
                ? (self::TASK_PHRASES[$lang][$t['task_type']] ?? null) : null,
            $facts->plan->tasks))));
        if ($missing === []) {
            return '';
        }
        $list = implode('; ', $missing);

        return match ($lang) {
            'en' => "Not found in the current data: {$list}.",
            'ru' => "В текущих данных не найдено: {$list}.",
            // Said as a reported gap ("bulunamadı"), naming nothing the facts do not.
            default => "Mevcut verilerde bulunamadı: {$list}.",
        };
    }
}
