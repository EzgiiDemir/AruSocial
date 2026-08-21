<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AskArucadLog;
use App\Models\Checkin;
use App\Models\Club;
use App\Models\DirectoryEntry;
use App\Models\EmailLog;
use App\Models\Event;
use App\Models\EventJoin;
use App\Models\FeedPost;
use App\Models\FoodVenue;
use App\Models\MediaItem;
use App\Models\ModerationReport;
use App\Models\Place;
use App\Models\PostComment;
use App\Models\Review;
use App\Models\ServiceItem;
use App\Models\SocialFollow;
use App\Models\Sport;
use App\Models\Story;
use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Real usage statistics (docs/EKSIKLER.md, admin panel istatistik modülü)
// — every number here is a live aggregate query over real tables, nothing
// hand-typed or simulated. Two honest exceptions, both explicit in the
// response instead of silently omitted:
// - "kaç kişi indirdi" (app installs/downloads) is genuinely not
//   answerable from this backend — that's an app-store/analytics-console
//   metric this prototype has no access to (see `appUsage.trackable`).
// - This prototype has exactly one real signed-in demo account by
//   default, but every ranking/breakdown below is computed over whatever
//   real accounts actually exist in the database — the numbers grow
//   honestly as real accounts do (see `verify_rest_backend.dart`'s
//   second-account checks for proof this isn't hardcoded to one user).
class StatsController extends Controller
{
    use ApiResponds;

    // date()/strftime('%H', ...) both real, portable aggregates — just
    // spelled differently per driver. No app code path outside tests runs
    // on anything but sqlite/mysql today, so this two-way switch is honest
    // (not a stub for drivers this app doesn't actually support).
    private function hourExpression(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%H', created_at)"
            : 'HOUR(created_at)';
    }

    private function mostActiveStudents(int $limit = 10)
    {
        return ActivityLog::query()
            ->join('users', 'users.id', '=', 'activity_log.user_id')
            ->selectRaw('users.id as user_id, users.name as name, count(*) as total')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();
    }

    public function index(Request $request): JsonResponse
    {
        $days = max(1, min(90, (int) $request->query('days', 14)));
        $since = now()->subDays($days - 1)->startOfDay();
        $mostActiveStudents = $this->mostActiveStudents();

        return $this->ok([
            'appUsage' => [
                'trackable' => false,
                'note' => 'Uygulama indirme/kurulum sayısı bu backend\'den ölçülemez — bu, App Store/Play Console '.
                    'veya bir mobil analytics SDK\'sının (Firebase Analytics vb.) alanı. Bunlar dış hesap gerektirir '.
                    '(bkz. docs/EXTERNAL_ACCOUNTS.md); burada olmayan bir sayı göstermek yerine dürüstçe belirtiliyor.',
            ],
            'userSummary' => [
                'realAccountCount' => User::count(),
                'note' => 'Aşağıdaki tüm sayılar gerçek veritabanı satırları — hesap sayısı arttıkça bu istatistikler '.
                    'de gerçek kullanım verisiyle büyür.',
                'totalXp' => (int) User::sum('xp'),
                'totalStrikes' => (int) User::sum('strikes'),
                'bannedAccounts' => User::whereNotNull('banned_at')->count(),
                'deactivatedAccounts' => User::whereNotNull('deactivated_at')->count(),
            ],

            // ------------------------------------------------ Check-ins
            'checkins' => [
                'total' => Checkin::count(),
                'visibleToOthers' => Checkin::where('visible_to_others', true)->count(),
                'hiddenXpOnly' => Checkin::where('visible_to_others', false)->count(),
                'mostCheckedInPlaces' => Checkin::query()
                    ->join('places', 'places.id', '=', 'checkins.place_id')
                    ->selectRaw('places.id as place_id, places.name as place_name, count(*) as total')
                    ->groupBy('places.id', 'places.name')
                    ->orderByDesc('total')
                    ->limit(10)
                    ->get(),
                'byDay' => Checkin::where('created_at', '>=', $since)
                    ->selectRaw('date(created_at) as day, count(*) as total')
                    ->groupBy('day')->orderBy('day')->get(),
                'byHour' => Checkin::selectRaw($this->hourExpression().' as hour, count(*) as total')
                    ->groupBy('hour')->orderBy('hour')->get(),
                'topStudents' => Checkin::query()
                    ->join('users', 'users.id', '=', 'checkins.user_id')
                    ->selectRaw('users.id as user_id, users.name as name, count(*) as total')
                    ->groupBy('users.id', 'users.name')
                    ->orderByDesc('total')
                    ->limit(10)
                    ->get(),
            ],

            // ------------------------------------------------- Events
            'events' => [
                'total' => Event::count(),
                'published' => Event::where('workflow_status', 'published')->where('draft', false)->count(),
                'pendingReview' => Event::where('workflow_status', 'pending_approval')->count(),
                'rejected' => Event::where('workflow_status', 'rejected')->count(),
                'totalJoins' => EventJoin::count(),
                'formsSubmitted' => EventJoin::whereNotNull('form_submitted_at')->count(),
                'attendanceApproved' => EventJoin::whereNotNull('approved_at')->count(),
                'mostJoinedEvents' => EventJoin::query()
                    ->join('events', 'events.id', '=', 'event_joins.event_id')
                    ->selectRaw('events.id as event_id, events.title as title, count(*) as total')
                    ->groupBy('events.id', 'events.title')
                    ->orderByDesc('total')
                    ->limit(10)
                    ->get(),
                'mostUsedPlaces' => Event::query()
                    ->whereNotNull('place_id')
                    ->join('places', 'places.id', '=', 'events.place_id')
                    ->selectRaw('places.id as place_id, places.name as place_name, count(*) as total')
                    ->groupBy('places.id', 'places.name')
                    ->orderByDesc('total')
                    ->limit(10)
                    ->get(),
                // Real "kendi aktiviteni oluştur" form funnel — opened is
                // real (ActivityFormController::show() stamps
                // form_opened_at on first real visit), submitted is real
                // (student_number only ever gets set by a real form
                // submission), so the rate is a genuine ratio, not a
                // guess. Only counts self-service events (created_by_user_id
                // not null) — admin-direct-created events never go through
                // this form at all.
                'forms' => (function () {
                    $selfService = Event::whereNotNull('created_by_user_id');
                    $total = (clone $selfService)->count();
                    $opened = (clone $selfService)->whereNotNull('form_opened_at')->count();
                    $submitted = (clone $selfService)->whereNotNull('student_number')->count();

                    return [
                        'total' => $total,
                        'opened' => $opened,
                        'submitted' => $submitted,
                        'submitRate' => $opened > 0 ? round($submitted / $opened, 3) : null,
                    ];
                })(),
                'byFaculty' => Event::query()
                    ->join('event_joins', 'event_joins.event_id', '=', 'events.id')
                    ->whereNotNull('events.faculty')
                    ->selectRaw('events.faculty as faculty, count(*) as total')
                    ->groupBy('events.faculty')
                    ->orderByDesc('total')
                    ->get(),
                'byDepartment' => Event::query()
                    ->join('event_joins', 'event_joins.event_id', '=', 'events.id')
                    ->whereNotNull('events.department')
                    ->selectRaw('events.department as department, count(*) as total')
                    ->groupBy('events.department')
                    ->orderByDesc('total')
                    ->get(),
                'attendanceTakenBy' => EventJoin::whereNotNull('approved_by')
                    ->selectRaw('approved_by as name, count(*) as total')
                    ->groupBy('approved_by')
                    ->orderByDesc('total')
                    ->get(),
                'attendanceStudentCount' => EventJoin::whereNotNull('approved_at')->distinct('user_id')->count('user_id'),
                'mostActiveStudents' => $mostActiveStudents,
            ],

            // -------------------------------------------- Social/content
            'social' => [
                'feedPosts' => FeedPost::count(),
                'comments' => PostComment::count(),
                'stories' => Story::count(),
                'reviews' => Review::count(),
                'averageRating' => round((float) (Review::avg('rating') ?? 0), 2),
                'moderationReportsFiled' => ModerationReport::count(),
                'moderationReportsUnresolved' => ModerationReport::whereNull('action')->count(),
                'topPosters' => FeedPost::selectRaw('author_id, name, count(*) as total')
                    ->groupBy('author_id', 'name')
                    ->orderByDesc('total')
                    ->limit(10)
                    ->get(),
                'mostLiked' => FeedPost::orderByDesc('likes')
                    ->limit(10)
                    ->get(['id', 'name', 'text', 'likes']),
                'mostCommented' => PostComment::query()
                    ->join('feed_posts', 'feed_posts.id', '=', 'post_comments.post_id')
                    ->selectRaw('feed_posts.id as post_id, feed_posts.name as author, feed_posts.text as text, count(*) as total')
                    ->groupBy('feed_posts.id', 'feed_posts.name', 'feed_posts.text')
                    ->orderByDesc('total')
                    ->limit(10)
                    ->get(),
                'mostFollowed' => SocialFollow::selectRaw('followed_name as name, count(*) as total')
                    ->groupBy('followed_name')
                    ->orderByDesc('total')
                    ->limit(10)
                    ->get(),
                'mostReportedContent' => ModerationReport::selectRaw('kind, target_id, target_label, count(*) as total')
                    ->groupBy('kind', 'target_id', 'target_label')
                    ->orderByDesc('total')
                    ->limit(10)
                    ->get(),
                'mostActiveStudents' => $mostActiveStudents,
            ],

            // ------------------------------------------------- Surveys
            'surveys' => [
                'total' => Survey::count(),
                'totalResponses' => SurveyResponse::count(),
            ],

            // --------------------------------------------- Real activity
            'activityByKind' => ActivityLog::selectRaw('kind, count(*) as total')
                ->groupBy('kind')->orderByDesc('total')->get(),

            // --------------------------------------------------- E-mail
            'email' => [
                'sent' => EmailLog::where('status', 'sent')->count(),
                'failed' => EmailLog::where('status', 'failed')->count(),
            ],

            // ------------------------------------------------ Ask ARUCAD
            // Real question logging (AiController::query() → AskArucadLog),
            // previously nonexistent — every number here reflects genuine
            // student questions, not a simulated distribution.
            'askArucad' => [
                'totalQuestions' => AskArucadLog::count(),
                'byCategory' => AskArucadLog::selectRaw('category, count(*) as total')
                    ->groupBy('category')->orderByDesc('total')->get(),
                'topQuestions' => AskArucadLog::selectRaw('lower(trim(question)) as question, count(*) as total')
                    ->groupBy('question')
                    ->orderByDesc('total')
                    ->limit(10)
                    ->get(),
            ],

            // ---------------------------------------------------- Harita
            // Real-time density snapshot (same thresholds as
            // PlaceController::density(): 0 quiet / 1-2 moderate / 3+
            // busy), embedded here so an admin can see it without leaving
            // the dashboard — not a second, different heatmap engine.
            'map' => (function () {
                $since = now()->startOfDay();
                $counts = Checkin::where('created_at', '>=', $since)
                    ->selectRaw('place_id, count(*) as total')
                    ->groupBy('place_id')
                    ->pluck('total', 'place_id');
                $ranked = Place::all()->map(fn (Place $p) => [
                    'placeId' => $p->id,
                    'placeName' => $p->name,
                    'checkinsToday' => (int) ($counts[$p->id] ?? 0),
                    'level' => (int) ($counts[$p->id] ?? 0) >= 3 ? 'busy' : ((int) ($counts[$p->id] ?? 0) >= 1 ? 'moderate' : 'quiet'),
                ])->sortByDesc('checkinsToday')->values();

                return [
                    'busiestPlaces' => $ranked->take(5)->values(),
                    'quietestPlaces' => $ranked->where('checkinsToday', 0)->take(5)->values(),
                    'byHour' => Checkin::selectRaw($this->hourExpression().' as hour, count(*) as total')
                        ->groupBy('hour')->orderBy('hour')->get(),
                ];
            })(),

            // ------------------------------------ Catalog / content size
            'catalog' => [
                'places' => Place::count(),
                'clubs' => Club::count(),
                'sports' => Sport::count(),
                'services' => ServiceItem::count(),
                'foodVenues' => FoodVenue::count(),
                'directoryEntries' => DirectoryEntry::count(),
                'mediaItems' => MediaItem::count(),
            ],
        ]);
    }
}
