<?php

namespace App\Services\Ai;

use App\Models\Club;
use App\Models\Event;
use App\Models\FoodVenue;
use App\Models\Place;
use App\Models\ServiceItem;
use App\Models\ShuttleRoute;
use App\Models\Sport;
use App\Services\RoutingService;
use App\Support\CredentialRequest;
use App\Support\PrivateDataRequest;
use App\Support\QueryLanguage;
use App\Support\TextFold;

/** Builds trusted optional Ask components from application data, never model JSON. */
final class AskOperations
{
    public function __construct(
        private readonly PlaceResolver $places,
        private readonly PersonalDataCapabilities $capabilities = new PersonalDataCapabilities,
    ) {}

    /**
     * @param  array{lat?: mixed, lng?: mixed}|null  $currentLocation
     * @param  string|null  $context  The question with the subject an earlier
     *                                turn supplied, when this one is a
     *                                follow-up. Used ONLY to name a place the
     *                                message refers to without naming ("oraya
     *                                nasıl giderim?"); what the question asks
     *                                for is still read from $query alone, so a
     *                                route asked two turns ago cannot turn a
     *                                later opening-hours question into a route.
     * @return array{places: list<array<string,mixed>>, route: ?array<string,mixed>, events: list<array<string,mixed>>, warnings: list<array<string,string>>, answer: ?string}
     */
    public function resolve(string $query, ?array $currentLocation = null, string $mode = 'walking', ?string $context = null): array
    {
        $language = $this->language($query);
        $mentioned = $this->places->mentioned($query);
        if ($mentioned === [] && $context !== null && trim($context) !== '') {
            $mentioned = $this->places->mentioned($context);
        }
        $placeCards = array_map(
            fn (array $match) => $this->places->component($match['place'], $match['service']),
            $mentioned,
        );
        $out = [
            'places' => $placeCards,
            'route' => null,
            'events' => $this->eventComponents($query),
            'warnings' => [],
            'answer' => null,
        ];

        /*
         * Someone else's private data, or an internal record. Checked before
         * the capability registry because no integration would ever make this
         * answerable, and saying "that system is not connected yet" about a
         * colleague's home address would imply that one day it will be.
         */
        if (PrivateDataRequest::isPrivate($query)) {
            $out['warnings'][] = ['code' => 'PRIVATE_DATA_REFUSED', 'message' => $this->unavailableWarning($language)];
            $out['answer'] = PrivateDataRequest::refusal($language);

            return $out;
        }

        /*
         * A password is never ours to give, whatever a crawled page says.
         *
         * Handled here rather than by grounding because there is nothing in
         * `Wi-Fi şifresi genellikle "ARUCAD" olarak ayarlanmıştır` for a
         * grounding check to catch — no URL, no figure, no acronym — and it
         * was produced confidently from seven retrieved sources. The answer
         * is the same every time, so a human writes it once.
         */
        if (CredentialRequest::isCredentialRequest($query)) {
            $out['warnings'][] = ['code' => 'CREDENTIAL_REFUSED', 'message' => $this->unavailableWarning($language)];
            $out['answer'] = CredentialRequest::refusal($language);

            return $out;
        }

        /*
         * The student's own data, from a system we have not connected.
         *
         * Answered here rather than by the model for two reasons: the model
         * has nothing to answer it FROM, so anything it produced would be
         * invented; and the honest answer is a fact about our infrastructure
         * that we know for certain. When the SIS integration lands, the
         * capability flips to connected in config and this stops firing.
         */
        $capability = $this->capabilities->detect($query);
        if ($capability !== null && ! $this->capabilities->isConnected($capability)) {
            $out['warnings'][] = [
                'code' => $this->capabilities->warningCode($capability),
                'message' => 'Kişisel veri kaynağı bağlı değil: '.$capability,
                'capability' => $capability,
            ];
            $out['answer'] = $this->capabilities->unavailableAnswer($capability, $language);

            return $out;
        }

        // One phrase naming several rows of the same type ("idari bina" →
        // two buildings), with no confident winner: ask, deterministically,
        // before anything is generated. A prompt note was not enough — the
        // model still picked one. Works for any entity type; place cards are
        // returned so the client can offer the choices.
        if (($ambiguity = $this->ambiguity($context !== null && trim($context) !== '' ? $context : $query)) !== null) {
            [$names, $choices] = $ambiguity;
            $ids = array_column(array_filter($choices, fn ($c) => $c['type'] === 'place'), 'id');
            if ($ids !== []) {
                $out['places'] = array_values(array_filter($out['places'], fn ($card) => in_array($card['id'], $ids, true)));
            }
            $list = implode(', ', $names);
            $out['warnings'][] = ['code' => 'AMBIGUOUS', 'message' => $list, 'choices' => $choices];
            $out['answer'] = match ($language) {
                'en' => "I found more than one possible match: {$list}. Which one do you mean?",
                'ru' => "Я нашёл несколько подходящих вариантов: {$list}. Какой вы имеете в виду?",
                default => "Birden fazla olası eşleşme buldum: {$list}. Hangisini kastediyorsun?",
            };

            return $out;
        }

        // Opening a 360 view is an application command, not a prose
        // question for an LLM. Resolve it deterministically so the client
        // receives the canonical place (including its verified tourUrl) and
        // can expose a real "360° Aç" action instead of merely describing
        // the entrance.
        if ($this->isTourQuestion($query)) {
            if ($mentioned === []) {
                $out['warnings'][] = [
                    'code' => 'PLACE_NOT_FOUND',
                    'message' => '360 turu açılacak yer doğrulanamadı.',
                ];
                $out['answer'] = match ($language) {
                    'en' => 'Which building or campus place would you like to open in 360°?',
                    'ru' => 'Какое здание или место кампуса открыть в формате 360°?',
                    default => 'Hangi bina veya kampüs alanını 360° açmamı istersin?',
                };

                return $out;
            }

            $destination = $mentioned[0]['place'];
            if (trim((string) $destination->tour_url) === '') {
                $out['warnings'][] = [
                    'code' => 'TOUR_UNAVAILABLE',
                    'message' => 'Bu yer için doğrulanmış 360 turu bulunamadı.',
                ];
                $out['answer'] = match ($language) {
                    'en' => "I found {$destination->name}, but no verified 360° tour is available for it.",
                    'ru' => "Место {$destination->name} найдено, но для него нет подтверждённого 360°-тура.",
                    default => $destination->name.' konumunu buldum ancak bu yer için doğrulanmış bir 360° turu yok.',
                };

                return $out;
            }

            $out['answer'] = match ($language) {
                'en' => "The verified 360° view for {$destination->name} is ready. Use the 360° Open button below.",
                'ru' => "Проверенный 360°-вид для {$destination->name} готов. Нажмите кнопку «Открыть 360°» ниже.",
                default => $destination->name.' için doğrulanmış 360° görünüm hazır. Aşağıdaki “360° Aç” düğmesini kullanabilirsin.',
            };

            return $out;
        }

        if ($this->isEventQuestion($query)) {
            $today = $this->containsAny(TextFold::fold($query), ['bugun', 'today', 'сегодня']);
            if ($out['events'] === []) {
                $out['answer'] = match ($language) {
                    'en' => $today ? 'There are no publicly listed campus events today.' : 'There are no upcoming publicly listed campus events.',
                    'ru' => $today ? 'На сегодня нет опубликованных мероприятий кампуса.' : 'Нет опубликованных предстоящих мероприятий кампуса.',
                    default => $today ? 'Bugün için herkese açık planlanmış bir kampüs etkinliği yok.' : 'Yaklaşan herkese açık bir kampüs etkinliği yok.',
                };
            } else {
                $listed = collect($out['events'])->map(
                    fn (array $event) => $event['title'].' ('.$event['date'].($event['time'] ?? '' ? ' '.$event['time'] : '').')',
                )->implode('; ');
                $out['answer'] = match ($language) {
                    'en' => 'Verified campus events: '.$listed.'.',
                    'ru' => 'Подтверждённые мероприятия кампуса: '.$listed.'.',
                    default => 'Doğrulanmış kampüs etkinlikleri: '.$listed.'.',
                };
            }

            return $out;
        }

        if (! $this->isRouteQuestion($query) && ! $this->beyondCatalog($query)) {
            if ($answer = $this->serviceAnswer($query, $mentioned, $language)) {
                $out['answer'] = $answer;

                return $out;
            }
            if ($answer = $this->catalogAnswer($query, $language)) {
                $out['answer'] = $answer;

                return $out;
            }
        }

        if (! $this->isRouteQuestion($query)) {
            return $out;
        }

        $q = TextFold::fold($query);
        $mode = in_array($mode, ['walking', 'driving', 'transit'], true) ? $mode : 'walking';
        if (count($mentioned) > 2) {
            $out['warnings'][] = ['code' => 'AMBIGUOUS_PLACE', 'message' => 'Birden fazla olası yer bulundu; lütfen başlangıç ve varış yerini seçin.'];
            $out['answer'] = match ($language) {
                'en' => 'I found several possible places. Please clarify the origin and destination.',
                'ru' => 'Найдено несколько возможных мест. Уточните точку отправления и назначения.',
                default => 'Birden fazla olası yer buldum. Başlangıç ve varış yerini netleştirir misin?',
            };

            return $out;
        }

        $origin = null;
        $destination = null;
        if (count($mentioned) >= 2) {
            $origin = $mentioned[0]['place'];
            $destination = $mentioned[1]['place'];
        } elseif (count($mentioned) === 1) {
            $destination = $mentioned[0]['place'];
            if (is_array($currentLocation)
                && is_numeric($currentLocation['lat'] ?? null)
                && is_numeric($currentLocation['lng'] ?? null)) {
                $origin = (object) [
                    'id' => 'current-location', 'name' => 'Current location',
                    'lat' => (float) $currentLocation['lat'], 'lng' => (float) $currentLocation['lng'],
                ];
            }
        }

        if ($destination === null) {
            /*
             * "How do I get TO CAMPUS" is a route question whose destination is
             * the campus itself, not a building in it.
             *
             * Measured: "Как добраться до кампуса из аэропорта Эрджан?" was
             * refused with "could not verify the destination" — the route
             * branch fires on "как добраться", finds no campus PLACE matching
             * "Ercan airport", and gives up before the campus-arrival answer
             * below is ever reachable. A student arriving at the airport got
             * nothing, which is the worst moment to get nothing.
             */
            if ($answer = $this->campusArrivalAnswer($q, $language)) {
                $out['answer'] = $answer;

                return $out;
            }

            $out['warnings'][] = ['code' => 'PLACE_NOT_FOUND', 'message' => 'Varış yeri doğrulanamadı.'];
            $out['answer'] = match ($language) {
                'en' => 'I could not verify the destination. Please provide the campus place or service name.',
                'ru' => 'Не удалось подтвердить место назначения. Укажите название места или службы кампуса.',
                default => 'Varış yerini doğrulayamadım. Kampüs yerinin veya hizmetin adını yazar mısın?',
            };

            return $out;
        }
        if ($origin === null) {
            $out['warnings'][] = ['code' => 'ORIGIN_REQUIRED', 'message' => 'Başlangıç konumu gerekli.'];
            $out['answer'] = match ($language) {
                'en' => "I verified {$destination->name}; routing needs current-location permission or a named origin.",
                'ru' => "Место {$destination->name} подтверждено; для маршрута нужен доступ к текущей геопозиции или точка отправления.",
                default => $destination->name.' konumunu doğruladım; rota için mevcut konum izni veya bir başlangıç yeri gerekli.',
            };

            return $out;
        }
        if (! RoutingService::isConfiguredFor($mode)) {
            $out['warnings'][] = ['code' => 'ROUTING_NOT_CONFIGURED', 'message' => 'Rota hizmeti bu ulaşım türü için yapılandırılmamış.'];
            $out['answer'] = $this->routingUnavailable($destination->name, $language);

            return $out;
        }

        $route = RoutingService::route(
            (float) $origin->lat, (float) $origin->lng,
            (float) $destination->lat, (float) $destination->lng,
            $mode,
        );
        if ($route === null) {
            $out['warnings'][] = ['code' => 'ROUTING_UNAVAILABLE', 'message' => 'Rota hizmeti geçici olarak kullanılamıyor.'];
            $out['answer'] = $this->routingUnavailable($destination->name, $language);

            return $out;
        }

        $out['route'] = [
            'origin' => ['id' => (string) $origin->id, 'name' => (string) $origin->name, 'latitude' => (float) $origin->lat, 'longitude' => (float) $origin->lng],
            'destination' => ['id' => (string) $destination->id, 'name' => (string) $destination->name, 'latitude' => (float) $destination->lat, 'longitude' => (float) $destination->lng],
            'distanceMeters' => $route['distanceMeters'],
            'durationSeconds' => $route['durationSeconds'],
            'geometry' => $route['points'],
            'steps' => $route['steps'],
            'travelMode' => $mode,
            'provider' => 'osrm',
            'status' => 'available',
            'generatedAt' => now()->toIso8601String(),
        ];
        $minutes = (int) ceil($route['durationSeconds'] / 60);
        $out['answer'] = match ($language) {
            'en' => sprintf('The %s route from %s to %s is ready: %.0f metres, about %d minutes. You can open the provider geometry on the map.', $mode, $origin->name, $destination->name, $route['distanceMeters'], $minutes),
            'ru' => sprintf('Маршрут (%s) от %s до %s готов: %.0f м, около %d мин. На карте доступна геометрия маршрута провайдера.', $mode, $origin->name, $destination->name, $route['distanceMeters'], $minutes),
            default => sprintf('%s ile %s arasındaki %s rota hazır: %.0f metre, yaklaşık %d dakika. Haritada gerçek yol geometrisini açabilirsin.', $origin->name, $destination->name, $this->modeLabel($mode), $route['distanceMeters'], $minutes),
        };

        return $out;
    }

    /**
     * The ambiguous candidates EntityResolver reported, or null when there is
     * a confident answer: an unambiguous entity of the same type outranks
     * the ambiguous ones ("Titan'daki idari bina" names Titan outright).
     *
     * @return array{0: list<string>, 1: list<array{type: string, id: string, name: string}>}|null
     */
    private function ambiguity(string $query): ?array
    {
        $entities = app(EntityResolver::class)->resolve($query);
        $ambiguous = array_values(array_filter($entities, fn ($e) => $e['ambiguous'] ?? false));
        if ($ambiguous === []) {
            return null;
        }
        $types = array_unique(array_column($ambiguous, 'type'));
        foreach ($entities as $entity) {
            if (! ($entity['ambiguous'] ?? false) && in_array($entity['type'], $types, true)) {
                return null;
            }
        }
        $choices = array_map(fn ($e) => ['type' => $e['type'], 'id' => $e['id'], 'name' => $e['name']], $ambiguous);

        return [array_column($choices, 'name'), $choices];
    }

    private function isRouteQuestion(string $query): bool
    {
        $q = TextFold::fold($query);
        foreach (['nasil gider', 'rota', 'yol tarifi', 'navigate', 'directions', 'how do i get', 'route', 'как добраться', 'маршрут'] as $term) {
            if (str_contains($q, TextFold::fold($term))) {
                return true;
            }
        }

        return false;
    }

    private function isTourQuestion(string $query): bool
    {
        $q = TextFold::fold($query);

        return str_contains($q, '360')
            || $this->containsAny($q, [
                'sanal tur', 'virtual tour', 'panorama', 'panoramik',
                'виртуальный тур', 'панорама',
            ]);
    }

    private function language(string $query): string
    {
        if (preg_match('/\p{Cyrillic}/u', $query) === 1) {
            return 'ru';
        }

        $q = TextFold::fold($query);
        if ($this->containsAny($q, [
            'where', 'what', 'how', 'which', 'available', 'opening hours',
            'academic calendar', 'architecture programme', 'architecture program',
            'student affairs', 'library', 'scholarship', 'timetable', 'class schedule',
        ])) {
            return 'en';
        }

        return QueryLanguage::detect($query) ?? 'tr';
    }

    private function unavailableWarning(string $language): string
    {
        return match ($language) {
            'en' => 'No authorised live or official source can verify this request.',
            'ru' => 'Нет авторизованного актуального или официального источника для проверки запроса.',
            default => 'Bu isteği doğrulayacak yetkili canlı veya resmî kaynak yok.',
        };
    }

    /** @param list<array{place: Place, service: ?ServiceItem, matched: string}> $mentioned */
    private function serviceAnswer(string $query, array $mentioned, string $language): ?string
    {
        $match = collect($mentioned)->first(fn (array $item) => $item['service'] instanceof ServiceItem);
        if (! is_array($match)) {
            return null;
        }

        /** @var ServiceItem $service */
        $service = $match['service'];
        $title = $this->serviceTitle($service, $language);
        $hours = $this->serviceHours($service->hours, $language);
        $where = collect([$service->building, $service->floor, $service->room])->filter()->implode(', ');
        $q = TextFold::fold($query);
        $locationOnly = $this->containsAny($q, ['nerede', 'where', 'где', 'konum', 'location']);
        $hoursOnly = $this->containsAny($q, [
            'saat', 'hours', 'open', 'acik', 'работ',
            // "kaçta açılıyor" / "kaçta kapanıyor" are hours questions that
            // contain none of the words above — the stem is "acil"/"kapan",
            // not "acik" — so they used to fall through to the full card.
            'kacta', 'aciliyor', 'kapaniyor', 'kapanis', 'acilis',
            'what time', 'closing', 'closes', 'opens',
            'во сколько', 'закрыва', 'открыва',
        ]);

        /*
         * A question about a day our row does not cover.
         *
         * Checked BEFORE the branching, because it applies to every shape of
         * the question. Measured: "Kütüphane pazar günü kaçta açılıyor?" was
         * answered with the full service card ending "Saatler: Hafta içi
         * 09:00–17:00" — weekday hours offered as the answer to a Sunday
         * question. This is the most dangerous class of error in the whole
         * system: the figure is real and came from our own row, so no
         * grounding check can catch it, and the answer is still false.
         */
        if ($this->asksAboutWeekend($q) && $this->coversWeekdaysOnly($service->hours)) {
            $recorded = $hours ?? '-';

            return match ($language) {
                'en' => 'Only weekday opening hours are recorded for '.$title.' ('.$recorded
                    .'). I have no verified weekend hours, so please check with them '
                    .'directly rather than assume they are open.',
                'ru' => 'Для «'.$title.'» указаны только часы работы в будни ('.$recorded
                    .'). Подтверждённых часов на выходные у меня нет — уточните напрямую, '
                    .'а не предполагайте, что открыто.',
                default => $title.' için yalnızca hafta içi çalışma saati kayıtlı ('
                    .$recorded.'). Hafta sonu için doğrulanmış bir saat bilgim yok; açık '
                    .'olduğunu varsaymak yerine doğrudan teyit etmeni öneririm.',
            };
        }

        // "Öğrenci işlerinin telefonu ne?": the contact field, verbatim, and
        // said plainly when the phone asked for is not recorded.
        if (! $locationOnly && ! $hoursOnly && $this->containsAny($q, self::CONTACT_WORDS)) {
            $contact = $this->contactAnswer($service, $title, $q, $language);
            if ($contact !== null) {
                return $contact;
            }
        }

        if ($locationOnly) {
            return match ($language) {
                'en' => $title.' is in '.$where.'.'.($hours ? ' Hours: '.$hours.'.' : '').($service->contact ? ' Contact: '.$service->contact.'.' : ''),
                'ru' => $title.' находится: '.$where.'.'.($hours ? ' Часы: '.$hours.'.' : '').($service->contact ? ' Контакт: '.$service->contact.'.' : ''),
                default => $title.' konumu: '.$where.'.'.($hours ? ' Saatler: '.$hours.'.' : '').($service->contact ? ' İletişim: '.$service->contact.'.' : ''),
            };
        }
        if ($hoursOnly) {
            if (! $service->hours) {
                return match ($language) {
                    'en' => 'Current opening hours are not recorded for '.$title.'.',
                    'ru' => 'Актуальные часы работы для '.$title.' не указаны.',
                    default => $title.' için güncel çalışma saati kayıtlı değil.',
                };
            }

            return match ($language) {
                'en' => $title.' hours: '.$hours.'.',
                'ru' => $title.': '.$hours.'.',
                default => $title.' çalışma saatleri: '.$hours.'.',
            };
        }

        // The description is free text entered in Turkish. Interpolating it
        // into an English or Russian sentence produced answers like
        // "Library: Sanat, tasarım ve iletişim odaklı kaynaklar. Location:
        // Meditation." — English labels wrapped around Turkish prose, which
        // is not an answer in either language. The structured fields are
        // language-neutral (a building name, a time, an address), so they are
        // always safe; the prose is included only when it is already in the
        // language we are answering in.
        $blurb = $this->descriptionFor($service, $language);

        return match ($language) {
            'en' => $title.($blurb !== '' ? ': '.$blurb : '.').
                ($where !== '' ? ' Location: '.$where.'.' : '').
                ($hours ? ' Hours: '.$hours.'.' : '').
                ($service->contact ? ' Contact: '.$service->contact.'.' : ''),
            'ru' => $title.($blurb !== '' ? ': '.$blurb : '.').
                ($where !== '' ? ' Место: '.$where.'.' : '').
                ($hours ? ' Часы: '.$hours.'.' : '').
                ($service->contact ? ' Контакт: '.$service->contact.'.' : ''),
            default => $title.($blurb !== '' ? ': '.$blurb : '.').
                ($where !== '' ? ' Konum: '.$where.'.' : '').
                ($hours ? ' Saatler: '.$hours.'.' : '').
                ($service->contact ? ' İletişim: '.$service->contact.'.' : ''),
        };
    }

    /** Asking how to reach an office: phone, e-mail, contact details. Folded. */
    private const CONTACT_WORDS = ['telefon', 'numarasi', 'arasam', 'arayabil', 'ararim', 'mail', 'e-posta', 'eposta', 'iletisim bilgi',
        'phone', 'email', 'contact', 'call', 'телефон', 'почт', 'связат', 'позвон'];

    private const PHONE_WORDS = ['telefon', 'numarasi', 'arasam', 'arayabil', 'ararim', 'phone', 'call', 'телефон', 'позвон'];

    /**
     * The e-mail addresses and phone numbers in the service's contact field,
     * exactly as recorded — nothing normalised, nothing added. Null when the
     * field holds neither (the full card then answers).
     */
    private function contactAnswer(ServiceItem $service, string $title, string $foldedQuery, string $language): ?string
    {
        $text = (string) $service->contact;
        preg_match_all('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u', $text, $emails);
        preg_match_all('/\+?\d[\d\s().-]{6,}\d/u', $text, $phones);
        $emails = array_values(array_unique($emails[0]));
        $phones = array_map('trim', $phones[0]);
        if (trim((string) $service->phone) !== '') {
            array_unshift($phones, trim((string) $service->phone));
        }
        $phones = array_values(array_unique($phones));
        if ($emails === [] && $phones === []) {
            return null;
        }
        $parts = [];
        if ($phones !== []) {
            $parts[] = match ($language) {
                'en' => 'phone ', 'ru' => 'телефон ', default => 'telefon '
            }.implode(', ', $phones);
        }
        if ($emails !== []) {
            $parts[] = match ($language) {
                'en' => 'e-mail ', 'ru' => 'эл. почта ', default => 'e-posta '
            }.implode(', ', $emails);
        }
        $noPhone = $phones === [] && $this->containsAny($foldedQuery, self::PHONE_WORDS)
            ? match ($language) {
                'en' => ' No phone number is recorded for this office in our data.',
                'ru' => ' Номер телефона этого офиса в наших данных не указан.',
                default => ' Bu birim için kayıtlı bir telefon numarası yok.',
            } : '';

        // "Müdürü kim ve maili ne?": services record no responsible person,
        // so the "who" part is answered as a gap, never with a name.
        $noPerson = preg_match('/(?<![\p{L}])(?:kim|who|кто)(?![\p{L}])/u', $foldedQuery)
            ? match ($language) {
                'en' => ' No responsible person is recorded for this office.',
                'ru' => ' Ответственное лицо этого офиса в наших данных не указано.',
                default => ' Bu birim için kayıtlı bir sorumlu kişi adı yok.',
            } : '';

        return $title.' — '.implode('; ', $parts).'.'.$noPhone.$noPerson;
    }

    /** Is the question about a Saturday, a Sunday, or the weekend generally? */
    private function asksAboutWeekend(string $foldedQuery): bool
    {
        return $this->containsAny($foldedQuery, [
            'pazar', 'cumartesi', 'hafta sonu', 'haftasonu',
            'sunday', 'saturday', 'weekend',
            'воскресен', 'суббот', 'выходн',
        ]);
    }

    /** Do the recorded hours describe weekdays only? */
    private function coversWeekdaysOnly(?string $hours): bool
    {
        if ($hours === null || trim($hours) === '') {
            return true;   // nothing recorded covers nothing
        }
        $folded = TextFold::fold($hours);
        $saysWeekday = $this->containsAny($folded, ['hafta ici', 'weekday', 'по будням', 'budni']);
        $saysWeekend = $this->containsAny($folded, [
            'pazar', 'cumartesi', 'hafta sonu', 'sunday', 'saturday', 'weekend',
            'воскресен', 'суббот', 'выходн',
        ]);

        return $saysWeekday && ! $saysWeekend;
    }

    /**
     * The service blurb, but only when it is in the language we are answering
     * in.
     *
     * Translating it here is not an option — a machine translation of a
     * service description is exactly the kind of unverified text this whole
     * layer exists to avoid emitting. Omitting it costs a sentence of colour
     * and keeps the answer readable; the facts a student asked for (where,
     * when, who to contact) are all still there.
     */
    private function descriptionFor(ServiceItem $service, string $language): string
    {
        $description = trim((string) $service->description);
        if ($description === '') {
            return '';
        }

        $written = QueryLanguage::detect($description);

        return $written === null || $written === $language ? $description : '';
    }

    private function serviceTitle(ServiceItem $service, string $language): string
    {
        $titles = [
            'library' => ['en' => 'Library', 'ru' => 'Библиотека'],
            'student-affairs' => ['en' => 'Student Affairs', 'ru' => 'Отдел по работе со студентами'],
            'international' => ['en' => 'International Student Office', 'ru' => 'Международный отдел'],
            'it' => ['en' => 'IT Support', 'ru' => 'ИТ-поддержка'],
        ];

        return $titles[$service->id][$language] ?? $service->title;
    }

    private function serviceHours(?string $hours, string $language): ?string
    {
        if (! $hours) {
            return null;
        }
        if (TextFold::fold($hours) === 'hafta ici 09:00–17:00' || TextFold::fold($hours) === 'hafta ici 09:00-17:00') {
            return match ($language) {
                'en' => 'weekdays 09:00–17:00',
                'ru' => 'по будням 09:00–17:00',
                default => $hours,
            };
        }

        return $hours;
    }

    /**
     * Getting to the campus itself, rather than to a building inside it.
     *
     * Reached from two places: the ordinary catalog path, and the routing
     * branch when no campus place could be resolved as the destination.
     */
    private function campusArrivalAnswer(string $foldedQuery, string $language): ?string
    {
        if (! $this->containsAny($foldedQuery, [
            'kampuse nasil ulas', 'kampuse nasil gid', 'okula nasil gid',
            'havaalanindan', 'havalimanindan', 'ercan', 'otobusle kampuse',
            'how do i reach campus', 'how to get to campus', 'how do i get to campus',
            'from the airport', 'from ercan',
            'как добраться до кампуса', 'как доехать до кампуса', 'из аэропорта',
        ])) {
            return null;
        }

        return match ($language) {
            'en' => 'ARUCAD has campuses and buildings in Kyrenia and Nicosia. Open the '
                .'verified campus map at https://arucad.edu.tr/en/campus-map/ for the exact '
                .'location. Tell me where you are starting from — a campus building, or your '
                .'current location with location permission on — and I will give you a real '
                .'route; I will not invent one without an origin. ARUCAD also runs shuttle '
                .'routes, which you can ask me about.',
            'ru' => 'У ARUCAD есть корпуса в Кирении и Никосии. Точное расположение — на '
                .'официальной карте https://arucad.edu.tr/kampus-haritasi/. Скажите, откуда вы '
                .'едете — от корпуса кампуса или от вашей текущей геопозиции с разрешением на '
                .'доступ — и я построю настоящий маршрут; без точки отправления я не буду его '
                .'придумывать. У ARUCAD также есть маршруты шаттлов — спросите меня о них.',
            default => 'ARUCAD’ın Girne ve Lefkoşa’da yerleşkeleri bulunuyor. Kesin konum için '
                .'doğrulanmış kampüs haritası: https://arucad.edu.tr/kampus-haritasi/ '
                .'Nereden geleceğini yazarsan — bir kampüs binası ya da konum izniyle mevcut '
                .'konumun — sana gerçek bir rota çıkarırım; başlangıç olmadan yol tarifi '
                .'uydurmam. ARUCAD’ın servis hatları da var, onları da sorabilirsin.',
        };
    }

    /**
     * Is this question asking for something the structured catalog does not
     * hold, and therefore must not answer?""
     *
     * The deterministic layer answers TOPIC questions — "tell me about the
     * architecture programme", "where is the library" — from canonical rows.
     * It was also answering SPECIFIC-VALUE questions that merely mentioned the
     * same topic, and answering them with the topic blurb:
     *
     *     "2027 güz döneminde mimarlık bölümünün taban puanı tam olarak kaç
     *      olacak"  →  a general description of the architecture programme
     *
     * which is not the requested figure, is not a refusal, and quietly took
     * the question away from the model and the grounding check that would
     * have declined it properly. We store no admission scores, no quotas and
     * no fee amounts anywhere in these tables, and no row covers a future
     * academic year, so those two shapes are handed on rather than guessed at.
     *
     * Note what this does NOT catch, deliberately: "kütüphane kaçta kapanıyor"
     * is also a specific-value question, and opening hours ARE a column, so
     * the catalog should and does answer it.
     */
    private function beyondCatalog(string $query): bool
    {
        $q = TextFold::fold($query);

        // A figure we do not store, asked for as a figure.
        $unstoredFigure = $this->containsAny($q, [
            'taban puan', 'tavan puan', 'siralama', 'kontenjan', 'basari sirasi',
            'admission score', 'cut off', 'cutoff', 'ranking', 'quota',
            'проходной балл', 'рейтинг', 'квота',
        ]);
        $amountAsked = $this->containsAny($q, [
            'ne kadar', 'kac para', 'kac lira', 'kac euro', 'kac dolar',
            'ucreti ne', 'ucret ne', 'how much', 'what is the cost',
            'what is the price', 'сколько стоит', 'какая стоимость',
        ]) && $this->containsAny($q, [
            'ucret', 'harc', 'tuition', 'fee', 'price', 'cost', 'стоимость', 'оплата',
        ]);
        // "Is it free?" asks about cost without using any of the words above,
        // and a catalog listing answers the other half of the question while
        // silently ignoring this one.
        $freeAsked = $this->containsAny($q, [
            'ucretsiz mi', 'ucretsiz mi?', 'bedava mi', 'para odemek', 'parali mi',
            'is it free', 'are they free', 'is this free', 'free for students',
            'бесплатно ли', 'это бесплатно', 'надо платить',
        ]);

        if ($unstoredFigure || $amountAsked || $freeAsked) {
            return true;
        }

        /*
         * A process question, answered with a location card.
         *
         * Measured: "I am an international student. What documents do I need?"
         * matched the International Office and returned "Uluslararası Öğrenci
         * Ofisi. Location: Eternal Idol. Hours: weekdays 09:00–17:00." Every
         * word true, and not one of them about documents. The row knows where
         * the office is; it does not know what to bring to it, and that is
         * what was asked.
         *
         * These go to the model, which has the indexed pages. The place card
         * still travels with the response, so the student gets the office
         * location too — as a card beside the answer rather than instead of it.
         */
        if ($this->containsAny($q, [
            // tr
            'hangi belge', 'ne gerekiyor', 'neler gerekli', 'nasil basvur',
            'nasil yapilir', 'sartlari ne', 'kosullari ne', 'adimlar',
            'surec nasil', 'ne yapmaliyim', 'gerekli evrak',
            // en
            'what documents', 'which documents', 'what do i need', 'what is needed',
            'how do i apply', 'how to apply', 'requirements', 'what is the process',
            'what steps', 'how does it work', 'eligibility',
            // ru
            'какие документы', 'что нужно', 'как подать', 'какие требования',
            'какой процесс', 'какие шаги',
        ])) {
            return true;
        }

        /*
         * A recommendation is not a lookup.
         *
         * "Hangi kulüpler var?" is a listing and the catalog is exactly right
         * for it. "Ben mimarlık öğrencisiyim… hangi kulüplere katılmalıyım?"
         * is advice, and the catalog answered it by printing all nineteen
         * clubs in alphabetical order — ignoring the department the student
         * had just volunteered, which is the one thing that made the question
         * theirs. A list is not wrong there, it is simply not an answer.
         *
         * The model gets these instead. It still has the same club, sport and
         * venue rows in its context, so the recommendation is drawn from real
         * campus data; what it adds is the part a catalog cannot.
         */
        if ($this->containsAny($q, [
            // tr
            'onerir misin', 'onerisin', 'ne onerirsin', 'tavsiye',
            'katilmaliyim', 'secmeliyim', 'yapmaliyim', 'gitmeliyim',
            'bana uygun', 'benim icin', 'hangisi daha iyi', 'hangisini',
            // en
            'recommend', 'suggest', 'should i', 'best for me', 'suits me',
            'advice', 'which one should', 'help me choose',
            // ru
            'посоветуй', 'порекомендуй', 'что выбрать', 'какой лучше',
            'мне подойдет', 'мне подойдёт', 'что мне', 'мне выбрать',
            'стоит выбрать', 'мне записаться',
        ])) {
            return true;
        }

        // A period no row can cover. The catalog describes the present; a
        // question pinned to a year beyond the one we are in is asking for a
        // forecast, and a forecast is exactly what must not be improvised.
        if (preg_match_all('/\b(20\d{2})\b/', $q, $matches) > 0) {
            $currentStart = (int) now()->format('n') >= 9
                ? (int) now()->format('Y')
                : (int) now()->format('Y') - 1;
            foreach ($matches[1] as $year) {
                if ((int) $year > $currentStart + 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private function catalogAnswer(string $query, string $language): ?string
    {
        $q = TextFold::fold($query);
        if ($answer = $this->campusArrivalAnswer($q, $language)) {
            return $answer;
        }

        /*
         * "Is there an X?" is a lookup, not a judgement.
         *
         * Before the model, because the model gets this wrong in the one
         * direction that matters: measured, it answered "Evet, ARUCAD'de
         * satranç kulübü vardır" for a club that does not exist, and "Evet,
         * hukuk okuyabilirsin" from a course called "İletişim Hukuku". A
         * student acts on "evet".
         */
        if ($answer = app(EntityExistence::class)->answer($query, $language)) {
            return $answer;
        }

        /*
         * There used to be an `officialKnowledgeAnswer()` here: five branches
         * of hand-written prose about scholarships, the academic calendar, the
         * architecture programme and transfer rules, returned instead of
         * asking the model.
         *
         * It was removed rather than fixed, for three reasons that all point
         * the same way. It stated facts we do not verify — "50%, 75%, 90% and
         * 100% scholarships", "the 2026–2027 calendar" — as literals in PHP,
         * so the day ARUCAD changes a rate the assistant keeps quoting the old
         * one with total confidence and an official-looking link. Two of the
         * five branches ignored the language argument entirely and answered an
         * English or Russian question in Turkish. And all five intercepted
         * questions that retrieval now answers correctly: retrieval@3 on this
         * set is 100%, grounding is 100%, and a figure read off the indexed
         * page is checked by AnswerGrounding, while a figure typed into a
         * PHP string is checked by nobody.
         *
         * Deterministic answers still handle everything backed by a canonical
         * ROW — places, services, shuttle routes, events, clubs, capabilities.
         * The distinction is whether we hold the fact, not whether we can
         * phrase it.
         */
        if ($this->containsAny($q, ['servis', 'shuttle', 'автобус'])) {
            $routes = ShuttleRoute::query()->orderBy('sort_order')->get();
            if ($routes->isEmpty()) {
                return null;
            }
            $names = $routes->pluck('name')->implode('; ');

            return match ($language) {
                'en' => 'Verified shuttle routes: '.$names.'. Live departure times are not connected; route stops are available in Explore and the map.',
                'ru' => 'Подтверждённые маршруты: '.$names.'. Актуальное время отправления не подключено; остановки доступны в разделе «Обзор» и на карте.',
                default => 'Doğrulanmış servis hatları: '.$names.'. Canlı kalkış saatleri bağlı değil; durakları Keşfet ve haritada görebilirsin.',
            };
        }
        if ($this->containsAny($q, ['yemek', 'eat', 'food', 'столов', 'поесть'])) {
            $venues = FoodVenue::query()->pluck('name')->filter();
            $places = Place::query()->where('category', 'Food')->pluck('name')->filter();
            $names = $venues->merge($places)->unique()->implode(', ');
            if ($names === '') {
                return null;
            }

            return match ($language) {
                'en' => 'Food options recorded on campus: '.$names.'.',
                'ru' => 'Места питания, указанные для кампуса: '.$names.'.',
                default => 'Kampüste kayıtlı yemek seçenekleri: '.$names.'.',
            };
        }
        if ($this->containsAny($q, ['kulup', 'clubs', 'клуб'])) {
            $names = Club::query()->orderBy('name')->limit(20)->pluck('name')->implode(', ');
            if ($names !== '') {
                return match ($language) {
                    'en' => 'Student clubs: '.$names.'.',
                    'ru' => 'Студенческие клубы: '.$names.'.',
                    default => 'Öğrenci kulüpleri: '.$names.'.',
                };
            }
        }
        if ($this->containsAny($q, ['spor', 'sport', 'спорт', 'gym', 'salon'])) {
            $sports = Sport::query()->orderBy('name')->get();
            if ($sports->isNotEmpty()) {
                $listed = $sports->take(12)->map(fn (Sport $sport) => $sport->name.' — '.$sport->facility)->implode('; ');

                return match ($language) {
                    'en' => 'Yes. Verified sports and facilities: '.$listed.'.',
                    'ru' => 'Да. Подтверждённые спортивные секции и объекты: '.$listed.'.',
                    default => 'Evet. Doğrulanmış sporlar ve tesisler: '.$listed.'.',
                };
            }
        }

        return null;
    }

    /** @param list<string> $terms */
    private function containsAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if (str_contains($text, TextFold::fold($term))) {
                return true;
            }
        }

        return false;
    }

    private function modeLabel(string $mode): string
    {
        return match ($mode) {
            'driving' => 'araç',
            'transit' => 'otobüs',
            default => 'yürüyüş',
        };
    }

    private function routingUnavailable(string $destination, string $language): string
    {
        return match ($language) {
            'en' => "I verified {$destination}, but routing is temporarily unavailable.",
            'ru' => "Место {$destination} подтверждено, но построение маршрута временно недоступно.",
            default => $destination.' konumunu doğruladım, ancak rota hizmeti geçici olarak kullanılamıyor.',
        };
    }

    /** @return list<array<string,mixed>> */
    private function eventComponents(string $query): array
    {
        $q = TextFold::fold($query);
        if (! $this->isEventQuestion($query)) {
            return [];
        }

        $events = Event::query()->publiclyListed();
        if (str_contains($q, 'bugun') || str_contains($q, 'today') || str_contains($q, 'сегодня')) {
            $events->whereDate('event_date', now()->toDateString());
        } else {
            $events->whereDate('event_date', '>=', now()->toDateString());
        }

        return $events->orderBy('event_date')->limit(8)->get()->map(static fn (Event $event): array => array_filter([
            'id' => (string) $event->id,
            'title' => (string) $event->title,
            'date' => $event->event_date?->toDateString(),
            'time' => trim((string) $event->time),
            'placeId' => $event->place_id,
            'placeName' => trim((string) $event->place_name),
            'category' => trim((string) $event->category),
            'description' => trim((string) $event->description),
            'organizer' => trim((string) $event->organizer),
        ], static fn ($value): bool => $value !== null && $value !== ''))->all();
    }

    /**
     * An event word decides it; the generic "ne var" ("what is there") only
     * when the question names no other topic. "Bugün yemekte ne var?" asks
     * about the menu and was answered "no events today" — the operational
     * path replies with authority, so it must not guess the subject.
     */
    private function isEventQuestion(string $query): bool
    {
        $q = TextFold::fold($query);
        if ($this->containsAny($q, ['etkinlik', 'event', 'what is happening', 'мероприят'])) {
            return true;
        }
        if (! $this->containsAny($q, ['ne var'])) {
            return false;
        }
        $topics = array_diff(
            array_keys(app(QueryPlanner::class)->plan($query)['domains']),
            ['events', 'places', 'navigation', 'knowledge'],
        );

        return $topics === [];
    }
}
