<?php

namespace App\Services\Ai\Facts;

use App\Support\PromptInjection;
use App\Support\TextFold;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Decides whether a CandidateFact may become a SupportedFact. One rule set
 * per fact type — there is no generic "looks similar" check, because what
 * makes a time, a language, a URL or a document list valid differs.
 */
final class FactValidator
{
    private const TIME_RANGE = '/(\d{1,2})[:.](\d{2})\s*[–\-—]\s*(\d{1,2})[:.](\d{2})/u';

    /** Words that name the category, not a document: "belgeler" is not a requirement. */
    private const GENERIC_DOCUMENT_WORDS = ['belge', 'belgeler', 'evrak', 'evraklar', 'document', 'documents', 'документы'];

    /**
     * @param  string|null  $source  the evidence text the candidate was extracted from (document-backed types)
     * @return array{ok: bool, method: string, reason: string, normalized: string}
     */
    public function validate(CandidateFact $candidate, ?string $source = null): array
    {
        $v = $candidate->value;
        $pass = fn (string $method, string $reason, string $normalized) => ['ok' => true, 'method' => $method, 'reason' => $reason, 'normalized' => $normalized];
        $fail = fn (string $method, string $reason) => ['ok' => false, 'method' => $method, 'reason' => $reason, 'normalized' => ''];
        if ($candidate->evidenceIds === []) {
            return $fail('provenance', 'no evidence id');
        }

        switch ($candidate->factType) {
            case 'program_language':
                $code = ['ingilizce' => 'en', 'english' => 'en', 'turkce' => 'tr', 'turkish' => 'tr'][TextFold::fold((string) $v)] ?? null;
                if ($code === null) {
                    return $fail('language_enum', 'not an allowed language of instruction: '.$v);
                }
                if ($candidate->subject === null) {
                    return $fail('language_enum', 'language not attributed to a programme');
                }

                return $pass('language_enum', 'allowed language attributed to '.$candidate->subject['name'], $code);

            case 'required_documents':
                $items = array_values(array_filter((array) $v, 'is_string'));
                if ($items === []) {
                    return $fail('document_list', 'empty list');
                }
                $haystack = TextFold::fold((string) $source);
                foreach ($items as $item) {
                    $folded = TextFold::fold($item);
                    if (! str_contains($haystack, $folded)) {
                        return $fail('document_list', 'item not in the evidence: '.$item);
                    }
                    if (in_array($folded, self::GENERIC_DOCUMENT_WORDS, true)) {
                        return $fail('document_list', 'item names the category, not a document: '.$item);
                    }
                    if (PromptInjection::isInjection($item)) {
                        return $fail('document_list', 'item is an instruction, not a document');
                    }
                }

                return $pass('document_list', count($items).' item(s), each verbatim in the evidence', implode('|', array_map(fn ($i) => TextFold::fold($i), $items)));

            case 'current_opening_hours':
                $v = is_array($v) ? (string) ($v['text'] ?? '') : (string) $v;
                if (! preg_match_all(self::TIME_RANGE, $v, $m, PREG_SET_ORDER)) {
                    return $fail('time_range', 'no time range in "'.$v.'"');
                }
                $ranges = [];
                foreach ($m as $r) {
                    if ((int) $r[1] > 24 || (int) $r[3] > 24 || (int) $r[2] > 59 || (int) $r[4] > 59) {
                        return $fail('time_range', 'invalid time in "'.$v.'"');
                    }
                    $ranges[] = sprintf('%02d:%s-%02d:%s', $r[1], $r[2], $r[3], $r[4]);
                }

                return $pass('time_range', 'parsed '.implode(', ', $ranges), implode(',', $ranges));

            case 'is_open_now':
                if (! is_bool($v) || ! isset($candidate->qualifiers['evaluated_at'], $candidate->qualifiers['timezone'])) {
                    return $fail('open_now', 'needs a boolean evaluated at a stated time and timezone');
                }

                return $pass('open_now', 'evaluated by OpeningHours at '.$candidate->qualifiers['evaluated_at'].' ('.$candidate->qualifiers['timezone'].')', $v ? 'true' : 'false');

            case 'place_coordinates':
            case 'user_location':
                $lat = $v['lat'] ?? null;
                $lng = $v['lng'] ?? null;
                if (! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
                    return $fail('coordinates', 'not a valid coordinate pair');
                }
                if ($candidate->factType === 'place_coordinates' && ($candidate->subject['type'] ?? null) !== 'place') {
                    return $fail('coordinates', 'coordinates without a canonical place');
                }

                return $pass('coordinates', 'canonical coordinates', $candidate->factType === 'user_location' ? 'request' : 'place:'.$candidate->subject['id']);

            case 'route_distance_m':
            case 'route_duration_min':
                $limit = $candidate->factType === 'route_distance_m' ? 50000 : 600;

                return is_numeric($v) && $v > 0 && $v <= $limit
                    ? $pass('routing_number', 'RoutingService value within bounds', (string) (int) $v)
                    : $fail('routing_number', 'out of bounds: '.json_encode($v));

            case 'club_social_profile':
                $text = trim((string) $v);
                $url = filter_var($text, FILTER_VALIDATE_URL) !== false && in_array(parse_url($text, PHP_URL_SCHEME), ['http', 'https'], true);
                $prefixed = ! $url && filter_var('https://'.$text, FILTER_VALIDATE_URL) !== false && str_contains($text, '.') && str_contains($text, '/');
                $handle = (bool) preg_match('/^@[\w.]{2,30}$/u', $text);
                if (! $url && ! $prefixed && ! $handle) {
                    return $fail('social_handle', 'neither a URL nor a handle: '.$text);
                }
                if ($source !== null && ! str_contains(mb_strtolower($source), mb_strtolower($text))) {
                    return $fail('social_handle', 'not present in the canonical record');
                }

                return $pass('social_handle', 'present verbatim in the canonical record', mb_strtolower($text));

            case 'current_events':
                $title = trim((string) ($v['title'] ?? ''));
                try {
                    $date = isset($v['date']) ? CarbonImmutable::parse((string) $v['date'])->toDateString() : null;
                } catch (Throwable) {
                    $date = null;
                }

                return $title !== '' && $date !== null
                    ? $pass('event_record', 'canonical event with a date', TextFold::fold($title).'@'.$date)
                    : $fail('event_record', 'event without a title or a valid date');

            case 'current_menu':
                $items = array_values(array_filter(array_map('strval', (array) ($v['items'] ?? $v))));

                return $items !== [] ? $pass('menu_record', count($items).' menu item(s)', implode('|', array_map(fn ($i) => TextFold::fold($i), $items)))
                    : $fail('menu_record', 'empty menu');

            case 'programme_duration':
                // "4 Yıl", "2 yıl", "1-2 Yarıyıl" — the forms the programme pages use.
                if (! preg_match('/^(\d)(?:\s*[-–]\s*(\d))?\s*(yil|yariyil|years?|semesters?)$/u', TextFold::fold(trim((string) $v)), $m)) {
                    return $fail('duration', 'not a programme duration: '.$v);
                }
                if ($candidate->subject === null) {
                    return $fail('duration', 'duration not attributed to a programme');
                }
                $unit = in_array($m[3], ['yil', 'year', 'years'], true) ? 'y' : 's';

                return $pass('duration', 'stated programme length attributed to '.$candidate->subject['name'], $m[1].($m[2] !== '' ? '-'.$m[2] : '').$unit);

            case 'academic_date':
                $start = (string) ($v['start'] ?? '');
                $end = (string) ($v['end'] ?? $start);
                $valid = fn (string $d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
                $recent = $v['recent'] ?? null;
                if (! $valid($start) || ! $valid($end) || $end < $start || ($v['kind'] ?? '') === ''
                    || ($recent !== null && (! $valid((string) ($recent['start'] ?? '')) || $recent['start'] > $start))) {
                    return $fail('calendar_date', 'not a valid calendar entry');
                }

                return $pass('calendar_date', 'official calendar entry '.$v['kind'].' ('.$v['academic_year'].')', $v['kind'].':'.$start.'..'.$end);

            case 'contact_email':
                $email = trim((string) $v);
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    return $fail('contact', 'not an e-mail address');
                }

                return $source !== null && str_contains(mb_strtolower($source), mb_strtolower($email))
                    ? $pass('contact', 'verbatim in the canonical record', mb_strtolower($email))
                    : $fail('contact', 'not present in the canonical record');

            case 'contact_phone':
                $digits = preg_replace('/\D+/', '', (string) $v);
                if (strlen((string) $digits) < 7) {
                    return $fail('contact', 'not a phone number');
                }

                return $source !== null && str_contains(preg_replace('/\D+/', '', $source), (string) $digits)
                    ? $pass('contact', 'digits verbatim in the canonical record', (string) $digits)
                    : $fail('contact', 'not present in the canonical record');

            case 'food_places':
                return trim((string) $v) !== '' ? $pass('canonical_name', 'canonical venue', TextFold::fold((string) $v)) : $fail('canonical_name', 'empty name');
        }

        return $fail('unknown', 'no validation rule for '.$candidate->factType);
    }
}
