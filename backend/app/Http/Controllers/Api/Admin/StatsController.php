<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
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
use App\Models\Notification;
use App\Models\ParticipationApplication;
use App\Models\Place;
use App\Models\PostComment;
use App\Models\PostLike;
use App\Models\Review;
use App\Models\ServiceItem;
use App\Models\Sport;
use App\Models\Story;
use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Real usage statistics (docs/EKSIKLER.md, admin panel istatistik modülü)
// — every number here is a live aggregate query over real tables, nothing
// hand-typed or simulated. Two honest exceptions, both explicit in the
// response instead of silently omitted:
// - "kaç kişi indirdi" (app installs/downloads) is genuinely not
//   answerable from this backend — that's an app-store/analytics-console
//   metric this prototype has no access to (see `appUsage.trackable`).
// - This prototype has exactly one real signed-in account
//   (`ApiResponds::currentUser()` — no multi-user auth), so "kaç kişi"
//   anywhere else in this payload means "kaç gerçek kayıt", not "kaç
//   farklı kullanıcı" — see `userSummary`.
class StatsController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $days = max(1, min(90, (int) $request->query('days', 14)));
        $since = now()->subDays($days - 1)->startOfDay();

        return $this->ok([
            'appUsage' => [
                'trackable' => false,
                'note' => 'Uygulama indirme/kurulum sayısı bu backend\'den ölçülemez — bu, App Store/Play Console '.
                    'veya bir mobil analytics SDK\'sının (Firebase Analytics vb.) alanı. Bunlar dış hesap gerektirir '.
                    '(bkz. docs/EXTERNAL_ACCOUNTS.md); burada olmayan bir sayı göstermek yerine dürüstçe belirtiliyor.',
            ],
            'userSummary' => [
                'realAccountCount' => User::count(),
                'activeAccounts' => User::whereNull('banned_at')->count(),
                'newAccounts' => User::where('created_at', '>=', $since)->count(),
                'bannedAccounts' => User::whereNotNull('banned_at')->count(),
                'note' => 'Aşağıdaki sayılar gerçek veritabanı satırlarıdır ve her hesap kendi kimliğiyle '.
                    'giriş yaptığı için gerçekten kullanıcı bazında ayrışır.',
                'totalXp' => (int) User::sum('xp'),
                'totalStrikes' => (int) User::sum('strikes'),
            ],

            // ------------------------------------------------ Check-ins
            'checkins' => [
                'total' => Checkin::count(),
                'visibleToOthers' => Checkin::where('visible_to_others', true)->count(),
                'mostCheckedInPlaces' => Checkin::query()
                    ->join('places', 'places.id', '=', 'checkins.place_id')
                    ->selectRaw('places.id as place_id, places.name as place_name, count(*) as total')
                    ->groupBy('places.id', 'places.name')
                    ->orderByDesc('total')
                    ->limit(10)
                    ->get(),
                // date() is valid on SQLite and PostgreSQL. GROUP BY must
                // repeat the expression — PostgreSQL rejects GROUP BY of
                // the SELECT alias (`day`) that SQLite allows.
                'byDay' => Checkin::where('created_at', '>=', $since)
                    ->selectRaw('date(created_at) as day, count(*) as total')
                    ->groupByRaw('date(created_at)')
                    ->orderByRaw('date(created_at)')
                    ->get(),
            ],

            // ------------------------------------------------- Events
            'events' => [
                'total' => Event::count(),
                'published' => Event::where('workflow_status', 'published')->where('draft', false)->count(),
                'pendingReview' => Event::where('workflow_status', 'pending_review')->count(),
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
            ],

            // -------------------------------------------- Social/content
            'social' => [
                'feedPosts' => FeedPost::count(),
                'comments' => PostComment::count(),
                'likes' => PostLike::count(),
                'stories' => Story::count(),
                'reviews' => Review::count(),
                'averageRating' => round((float) (Review::avg('rating') ?? 0), 2),
                'moderationReportsFiled' => ModerationReport::count(),
                'moderationReportsUnresolved' => ModerationReport::whereNull('action')->count(),
            ],

            'applications' => [
                'pending' => ParticipationApplication::whereIn('status', ['submitted', 'under_review'])->count(),
                'approved' => ParticipationApplication::where('status', 'approved')->count(),
                'rejected' => ParticipationApplication::where('status', 'rejected')->count(),
                'byType' => ParticipationApplication::selectRaw('target_type, count(*) as total')
                    ->groupBy('target_type')->orderByDesc('total')->get(),
            ],

            'notifications' => [
                'total' => Notification::count(),
                'unread' => Notification::whereNull('read_at')->count(),
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
