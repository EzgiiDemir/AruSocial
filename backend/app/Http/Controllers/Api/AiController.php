<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\Event;
use App\Models\FoodVenue;
use App\Models\Place;
use App\Models\ServiceItem;
use App\Models\Sport;
use App\Services\AskConversationService;
use App\Services\CampusAskFallback;
use App\Support\SchemaColumnCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AiController extends Controller
{
    use ApiResponds;

    // Real proxy: GROQ_API_KEY on the server calls Groq with a campus
    // catalog system prompt. Without a key (or if Groq fails) the same
    // catalog is answered locally — Ask ARUCAD still works.
    //
    // Real bug fix: this used to relay the client's raw prompt straight to
    // Groq with zero system prompt and zero conversation history — Ask
    // ARUCAD could not actually answer a single real campus question (no
    // place/event/club/service data reached the model at all) despite the
    // UI's own claim of being a grounded, multi-turn assistant. Now it
    // builds the same kind of real-data system prompt the mock-mode
    // fallback (GroqAiService in the Flutter app) already used locally,
    // and forwards the full conversation the client sends.
    public function query(Request $request, AskConversationService $ask, CampusAskFallback $fallback): JsonResponse
    {
        $messages = $request->input('messages');
        if (! is_array($messages) || count($messages) === 0) {
            $prompt = (string) $request->input('prompt', '');
            $messages = [['role' => 'user', 'content' => $prompt]];
        }

        $lastUser = '';
        foreach (array_reverse($messages) as $m) {
            if (($m['role'] ?? 'user') !== 'assistant') {
                $lastUser = (string) ($m['content'] ?? '');
                break;
            }
        }
        $prompt = $lastUser !== '' ? $lastUser : (string) $request->input('prompt', '');

        $apiKey = (string) config('services.groq.key');
        $clean = null;
        if ($apiKey !== '') {
            $payloadMessages = [
                ['role' => 'system', 'content' => $this->systemPrompt()],
            ];
            foreach ($messages as $m) {
                $role = ($m['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
                $payloadMessages[] = ['role' => $role, 'content' => (string) ($m['content'] ?? '')];
            }

            try {
                $response = Http::withToken($apiKey)
                    ->timeout(25)
                    ->withOptions(['verify' => (bool) config('services.groq.verify_ssl')])
                    ->post('https://api.groq.com/openai/v1/chat/completions', [
                        'model' => (string) config('services.groq.chat_model', 'openai/gpt-oss-20b'),
                        'messages' => $payloadMessages,
                        'temperature' => 0.4,
                        'max_tokens' => 800,
                    ]);
                if ($response->successful()) {
                    $message = $response->json('choices.0.message') ?? [];
                    $answer = trim((string) ($message['content'] ?? ''));
                    if ($answer === '') {
                        $answer = trim((string) ($message['reasoning'] ?? ''));
                    }
                    $clean = $this->stripMarkdown($answer);
                }
            } catch (\Throwable $e) {
                $clean = null;
            }
        }

        if ($clean === null || $clean === '') {
            $clean = $fallback->answer($prompt);
        }

        $conversation = $ask->appendTurn(
            $this->currentUser(),
            $request->input('conversationId') ? (string) $request->input('conversationId') : null,
            $prompt,
            $clean,
        );

        return $this->ok([
            'answer' => $clean,
            'conversationId' => $conversation?->id,
        ]);
    }

    // Server-side backstop — a prompt instruction alone doesn't reliably
    // hold against every model response, so strip common markdown symbols
    // regardless of whether the model obeyed the instruction below.
    private function stripMarkdown(string $text): string
    {
        $text = preg_replace('/\*\*(.*?)\*\*/s', '$1', $text) ?? $text;
        $text = preg_replace('/\*(.*?)\*/s', '$1', $text) ?? $text;
        $text = preg_replace('/^#{1,6}\s*/m', '', $text) ?? $text;
        $text = preg_replace('/^[\-\*]\s+/m', '', $text) ?? $text;
        $text = preg_replace('/`{1,3}([^`]*)`{1,3}/s', '$1', $text) ?? $text;

        return trim($text);
    }

    private function systemPrompt(): string
    {
        $places = Place::all()->map(fn (Place $p) => "- {$p->name} ({$p->category}): {$p->distance} uzaklıkta, {$p->street}");

        // Real fix: Schema::hasColumn() used to re-query the database's own
        // schema metadata on every Ask ARUCAD prompt. SchemaColumnCache
        // answers it once per worker process instead.
        $events = Event::query();
        if (SchemaColumnCache::hasColumn('events', 'draft')) {
            $events->where('draft', false);
        }
        if (SchemaColumnCache::hasColumn('events', 'workflow_status')) {
            $events->where('workflow_status', 'published');
        }
        $events = $events->limit(30)->get()
            ->map(fn (Event $e) => "- {$e->title} @ {$e->place_name}, saat {$e->time}, {$e->attendees} katılımcı");

        $clubs = Club::all()->map(fn (Club $c) => "- {$c->name} ({$c->category}): {$c->description}");

        $sports = Sport::all()->map(fn (Sport $s) => "- {$s->name}: {$s->facility}");

        $services = ServiceItem::all()->map(function (ServiceItem $s) {
            $where = collect([$s->building, $s->floor, $s->room])->filter()->implode(', ');

            return "- {$s->title} ({$s->category}): {$s->description}".
                ($where !== '' ? " Konum: {$where}." : '')." İletişim: {$s->contact}";
        });

        $today = now()->toDateString();
        $food = FoodVenue::with(['dailyMenus' => fn ($q) => $q->whereDate('menu_date', $today)])->get()
            ->map(function (FoodVenue $v) {
                $menu = $v->dailyMenus->first();
                $menuPart = $menu === null
                    ? 'bugünün menüsü girilmemiş'
                    : (empty($menu->items) ? 'menü detayı girilmemiş' : implode(', ', $menu->items));

                return "- {$v->name}".($v->hours ? " ({$v->hours})" : '').": bugün → {$menuPart}";
            });

        return <<<PROMPT
Sen Ask ARUCAD'sın, ARUCAD (Girne/Kyrenia) kampüsünün yapay zekâ asistanısın.
Öğrencilere kampüs, akademik, idari, sosyal ve günlük ihtiyaç konularında kısa,
samimi ve doğru yanıt ver. Bilmediğin bir şeyi uydurma; emin değilsen bunu
söyle. Cevabın somut olabildiğince: bir yer, kişi, e-posta, saat ya da bağlantı
varsa mutlaka belirt — sadece genel konuşma, yönlendirici bilgi ver.

Soru Türkçeyse Türkçe, İngilizceyse İngilizce cevap ver — iki dili karıştırma.
En fazla 3-4 cümle kullan. Markdown biçimlendirmesi KULLANMA: yıldız (*),
kare işareti (#), tire madde işareti (-), ters tırnak (`) gibi hiçbir işaret
kullanma — düz, temiz cümleler yaz.

Güncel kampüs verisi:

Yerler:
{$places->join("\n")}

Etkinlikler:
{$events->join("\n")}

Kulüpler:
{$clubs->join("\n")}

Spor imkânları:
{$sports->join("\n")}

Kampüs Hizmetleri:
{$services->join("\n")}

Yemek noktaları:
{$food->join("\n")}
PROMPT;
    }
}
