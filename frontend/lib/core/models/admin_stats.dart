/// Real, live-aggregated usage statistics for the merged Dashboard/
/// İstatistik tab — mirrors `Admin\StatsController::index()` exactly, down
/// to the one field that's honestly *not* trackable from this backend
/// rather than faked (see [AdminStats.appUsage]).
class AdminStats {
  final AppUsageStats appUsage;
  final UserSummaryStats userSummary;
  final CheckinStats checkins;
  final EventStats events;
  final SocialStats social;
  final SurveyStats surveys;
  final List<KindCount> activityByKind;
  final EmailStats email;
  final AskArucadStats askArucad;
  final MapStats map;
  final CatalogStats catalog;

  const AdminStats({
    required this.appUsage,
    required this.userSummary,
    required this.checkins,
    required this.events,
    required this.social,
    required this.surveys,
    required this.activityByKind,
    required this.email,
    required this.askArucad,
    required this.map,
    required this.catalog,
  });

  factory AdminStats.fromJson(Map<String, dynamic> json) => AdminStats(
        appUsage: AppUsageStats.fromJson(json['appUsage'] as Map<String, dynamic>),
        userSummary: UserSummaryStats.fromJson(json['userSummary'] as Map<String, dynamic>),
        checkins: CheckinStats.fromJson(json['checkins'] as Map<String, dynamic>),
        events: EventStats.fromJson(json['events'] as Map<String, dynamic>),
        social: SocialStats.fromJson(json['social'] as Map<String, dynamic>),
        surveys: SurveyStats.fromJson(json['surveys'] as Map<String, dynamic>),
        activityByKind: (json['activityByKind'] as List<dynamic>)
            .map((e) => KindCount.fromJson(e as Map<String, dynamic>))
            .toList(),
        email: EmailStats.fromJson(json['email'] as Map<String, dynamic>),
        askArucad: AskArucadStats.fromJson(json['askArucad'] as Map<String, dynamic>),
        map: MapStats.fromJson(json['map'] as Map<String, dynamic>),
        catalog: CatalogStats.fromJson(json['catalog'] as Map<String, dynamic>),
      );
}

/// "Kaç kişi indirdi" genuinely can't be answered from this backend — that
/// lives in an app-store/analytics console this prototype has no access
/// to. [trackable] is always false; [note] explains why, for display
/// instead of a silently-omitted or faked number.
class AppUsageStats {
  final bool trackable;
  final String note;
  const AppUsageStats({required this.trackable, required this.note});
  factory AppUsageStats.fromJson(Map<String, dynamic> json) =>
      AppUsageStats(trackable: json['trackable'] as bool, note: json['note'] as String);
}

class UserSummaryStats {
  final int realAccountCount;
  final String note;
  final int totalXp;
  final int totalStrikes;
  final int bannedAccounts;
  final int deactivatedAccounts;
  const UserSummaryStats({
    required this.realAccountCount,
    required this.note,
    required this.totalXp,
    required this.totalStrikes,
    required this.bannedAccounts,
    required this.deactivatedAccounts,
  });
  factory UserSummaryStats.fromJson(Map<String, dynamic> json) => UserSummaryStats(
        realAccountCount: json['realAccountCount'] as int,
        note: json['note'] as String,
        totalXp: json['totalXp'] as int,
        totalStrikes: json['totalStrikes'] as int,
        bannedAccounts: json['bannedAccounts'] as int,
        deactivatedAccounts: json['deactivatedAccounts'] as int,
      );
}

class PlaceCount {
  final String placeId;
  final String placeName;
  final int total;
  const PlaceCount({required this.placeId, required this.placeName, required this.total});
  factory PlaceCount.fromJson(Map<String, dynamic> json) => PlaceCount(
        placeId: json['place_id'] as String,
        placeName: json['place_name'] as String,
        total: json['total'] as int,
      );
}

class DailyCount {
  final String day;
  final int total;
  const DailyCount({required this.day, required this.total});
  factory DailyCount.fromJson(Map<String, dynamic> json) =>
      DailyCount(day: json['day'] as String, total: json['total'] as int);
}

/// Generic "label → total" ranking, reused for hours, categories, names,
/// faculties, departments, and questions — every one of these is just a
/// grouped-count query with a different label column on the backend.
class LabelCount {
  final String label;
  final int total;
  const LabelCount({required this.label, required this.total});
  factory LabelCount.fromJson(Map<String, dynamic> json, String labelKey) => LabelCount(
        label: (json[labelKey] ?? '').toString(),
        total: json['total'] as int,
      );
}

class EventJoinCount {
  final String eventId;
  final String title;
  final int total;
  const EventJoinCount({required this.eventId, required this.title, required this.total});
  factory EventJoinCount.fromJson(Map<String, dynamic> json) => EventJoinCount(
        eventId: json['event_id'] as String,
        title: json['title'] as String,
        total: json['total'] as int,
      );
}

class CheckinStats {
  final int total;
  final int visibleToOthers;
  final int hiddenXpOnly;
  final int today;
  final int thisWeek;
  final int thisMonth;
  final double shareRate;
  final double hiddenXpRate;
  final List<PlaceCount> mostCheckedInPlaces;
  final List<DailyCount> byDay;
  final List<LabelCount> byHour;
  final List<LabelCount> topStudents;
  const CheckinStats({
    required this.total,
    required this.visibleToOthers,
    required this.hiddenXpOnly,
    this.today = 0,
    this.thisWeek = 0,
    this.thisMonth = 0,
    this.shareRate = 0,
    this.hiddenXpRate = 0,
    required this.mostCheckedInPlaces,
    required this.byDay,
    required this.byHour,
    required this.topStudents,
  });
  factory CheckinStats.fromJson(Map<String, dynamic> json) => CheckinStats(
        total: json['total'] as int,
        visibleToOthers: json['visibleToOthers'] as int,
        hiddenXpOnly: json['hiddenXpOnly'] as int,
        today: json['today'] as int? ?? 0,
        thisWeek: json['thisWeek'] as int? ?? 0,
        thisMonth: json['thisMonth'] as int? ?? 0,
        shareRate: (json['shareRate'] as num?)?.toDouble() ?? 0,
        hiddenXpRate: (json['hiddenXpRate'] as num?)?.toDouble() ?? 0,
        mostCheckedInPlaces: (json['mostCheckedInPlaces'] as List<dynamic>)
            .map((e) => PlaceCount.fromJson(e as Map<String, dynamic>))
            .toList(),
        byDay: (json['byDay'] as List<dynamic>)
            .map((e) => DailyCount.fromJson(e as Map<String, dynamic>))
            .toList(),
        byHour: (json['byHour'] as List<dynamic>)
            .map((e) => LabelCount.fromJson(e as Map<String, dynamic>, 'hour'))
            .toList(),
        topStudents: (json['topStudents'] as List<dynamic>)
            .map((e) => LabelCount.fromJson(e as Map<String, dynamic>, 'name'))
            .toList(),
      );
}

class FormFunnelStats {
  final int total;
  final int opened;
  final int submitted;
  final double? submitRate;
  const FormFunnelStats({
    required this.total,
    required this.opened,
    required this.submitted,
    required this.submitRate,
  });
  factory FormFunnelStats.fromJson(Map<String, dynamic> json) => FormFunnelStats(
        total: json['total'] as int,
        opened: json['opened'] as int,
        submitted: json['submitted'] as int,
        submitRate: (json['submitRate'] as num?)?.toDouble(),
      );
}

class EventStats {
  final int total;
  final int published;
  final int pendingReview;
  final int rejected;
  final int totalJoins;
  final int formsSubmitted;
  final int attendanceApproved;
  final List<EventJoinCount> mostJoinedEvents;
  final List<PlaceCount> mostUsedPlaces;
  final FormFunnelStats forms;
  final List<LabelCount> byFaculty;
  final List<LabelCount> byDepartment;
  final List<LabelCount> attendanceTakenBy;
  final int attendanceStudentCount;
  final List<LabelCount> mostActiveStudents;
  final List<LabelCount> byCategory;
  final List<LabelCount> joinsByHour;
  final List<DailyCount> joinsByDay;
  final double averageJoins;
  final double? avgApprovalHours;
  const EventStats({
    required this.total,
    required this.published,
    required this.pendingReview,
    required this.rejected,
    required this.totalJoins,
    required this.formsSubmitted,
    required this.attendanceApproved,
    required this.mostJoinedEvents,
    required this.mostUsedPlaces,
    required this.forms,
    required this.byFaculty,
    required this.byDepartment,
    required this.attendanceTakenBy,
    required this.attendanceStudentCount,
    required this.mostActiveStudents,
    this.byCategory = const [],
    this.joinsByHour = const [],
    this.joinsByDay = const [],
    this.averageJoins = 0,
    this.avgApprovalHours,
  });
  factory EventStats.fromJson(Map<String, dynamic> json) => EventStats(
        total: json['total'] as int,
        published: json['published'] as int,
        pendingReview: json['pendingReview'] as int,
        rejected: json['rejected'] as int,
        totalJoins: json['totalJoins'] as int,
        formsSubmitted: json['formsSubmitted'] as int,
        attendanceApproved: json['attendanceApproved'] as int,
        mostJoinedEvents: (json['mostJoinedEvents'] as List<dynamic>)
            .map((e) => EventJoinCount.fromJson(e as Map<String, dynamic>))
            .toList(),
        mostUsedPlaces: (json['mostUsedPlaces'] as List<dynamic>)
            .map((e) => PlaceCount.fromJson(e as Map<String, dynamic>))
            .toList(),
        forms: FormFunnelStats.fromJson(json['forms'] as Map<String, dynamic>),
        byFaculty: (json['byFaculty'] as List<dynamic>)
            .map((e) => LabelCount.fromJson(e as Map<String, dynamic>, 'faculty'))
            .toList(),
        byDepartment: (json['byDepartment'] as List<dynamic>)
            .map((e) => LabelCount.fromJson(e as Map<String, dynamic>, 'department'))
            .toList(),
        attendanceTakenBy: (json['attendanceTakenBy'] as List<dynamic>)
            .map((e) => LabelCount.fromJson(e as Map<String, dynamic>, 'name'))
            .toList(),
        attendanceStudentCount: json['attendanceStudentCount'] as int,
        mostActiveStudents: (json['mostActiveStudents'] as List<dynamic>)
            .map((e) => LabelCount.fromJson(e as Map<String, dynamic>, 'name'))
            .toList(),
        byCategory: (json['byCategory'] as List<dynamic>? ?? const [])
            .map((e) => LabelCount.fromJson(e as Map<String, dynamic>, 'category'))
            .toList(),
        joinsByHour: (json['joinsByHour'] as List<dynamic>? ?? const [])
            .map((e) => LabelCount.fromJson(e as Map<String, dynamic>, 'hour'))
            .toList(),
        joinsByDay: (json['joinsByDay'] as List<dynamic>? ?? const [])
            .map((e) => DailyCount.fromJson(e as Map<String, dynamic>))
            .toList(),
        averageJoins: (json['averageJoins'] as num?)?.toDouble() ?? 0,
        avgApprovalHours: (json['avgApprovalHours'] as num?)?.toDouble(),
      );
}

class LikedPost {
  final String id;
  final String name;
  final String text;
  final int likes;
  const LikedPost({required this.id, required this.name, required this.text, required this.likes});
  factory LikedPost.fromJson(Map<String, dynamic> json) => LikedPost(
        id: json['id'] as String,
        name: json['name'] as String,
        text: json['text'] as String,
        likes: json['likes'] as int,
      );
}

class CommentedPost {
  final String postId;
  final String author;
  final String text;
  final int total;
  const CommentedPost({required this.postId, required this.author, required this.text, required this.total});
  factory CommentedPost.fromJson(Map<String, dynamic> json) => CommentedPost(
        postId: json['post_id'] as String,
        author: json['author'] as String,
        text: json['text'] as String,
        total: json['total'] as int,
      );
}

class ReportedContent {
  final String kind;
  final String targetId;
  final String targetLabel;
  final int total;
  const ReportedContent({required this.kind, required this.targetId, required this.targetLabel, required this.total});
  factory ReportedContent.fromJson(Map<String, dynamic> json) => ReportedContent(
        kind: json['kind'] as String,
        targetId: json['target_id'] as String,
        targetLabel: json['target_label'] as String,
        total: json['total'] as int,
      );
}

class SocialStats {
  final int feedPosts;
  final int comments;
  final int stories;
  final int reviews;
  final double averageRating;
  final int moderationReportsFiled;
  final int moderationReportsUnresolved;
  final List<LabelCount> topPosters;
  final List<LikedPost> mostLiked;
  final List<CommentedPost> mostCommented;
  final List<LabelCount> mostFollowed;
  final List<ReportedContent> mostReportedContent;
  final List<LabelCount> mostActiveStudents;
  final List<LabelCount> mostLikedAuthors;
  final double engagementRate;
  final List<DailyCount> byDay;
  const SocialStats({
    required this.feedPosts,
    required this.comments,
    required this.stories,
    required this.reviews,
    required this.averageRating,
    required this.moderationReportsFiled,
    required this.moderationReportsUnresolved,
    required this.topPosters,
    required this.mostLiked,
    required this.mostCommented,
    required this.mostFollowed,
    required this.mostReportedContent,
    required this.mostActiveStudents,
    this.mostLikedAuthors = const [],
    this.engagementRate = 0,
    this.byDay = const [],
  });
  factory SocialStats.fromJson(Map<String, dynamic> json) => SocialStats(
        feedPosts: json['feedPosts'] as int,
        comments: json['comments'] as int,
        stories: json['stories'] as int,
        reviews: json['reviews'] as int,
        averageRating: (json['averageRating'] as num).toDouble(),
        moderationReportsFiled: json['moderationReportsFiled'] as int,
        moderationReportsUnresolved: json['moderationReportsUnresolved'] as int,
        topPosters: (json['topPosters'] as List<dynamic>)
            .map((e) => LabelCount.fromJson(e as Map<String, dynamic>, 'name'))
            .toList(),
        mostLiked: (json['mostLiked'] as List<dynamic>)
            .map((e) => LikedPost.fromJson(e as Map<String, dynamic>))
            .toList(),
        mostCommented: (json['mostCommented'] as List<dynamic>)
            .map((e) => CommentedPost.fromJson(e as Map<String, dynamic>))
            .toList(),
        mostFollowed: (json['mostFollowed'] as List<dynamic>)
            .map((e) => LabelCount.fromJson(e as Map<String, dynamic>, 'name'))
            .toList(),
        mostReportedContent: (json['mostReportedContent'] as List<dynamic>)
            .map((e) => ReportedContent.fromJson(e as Map<String, dynamic>))
            .toList(),
        mostActiveStudents: (json['mostActiveStudents'] as List<dynamic>)
            .map((e) => LabelCount.fromJson(e as Map<String, dynamic>, 'name'))
            .toList(),
        mostLikedAuthors: (json['mostLikedAuthors'] as List<dynamic>? ?? const [])
            .map((e) => LabelCount.fromJson(e as Map<String, dynamic>, 'name'))
            .toList(),
        engagementRate: (json['engagementRate'] as num?)?.toDouble() ?? 0,
        byDay: (json['byDay'] as List<dynamic>? ?? const [])
            .map((e) => DailyCount.fromJson(e as Map<String, dynamic>))
            .toList(),
      );
}

class SurveyStats {
  final int total;
  final int totalResponses;
  const SurveyStats({required this.total, required this.totalResponses});
  factory SurveyStats.fromJson(Map<String, dynamic> json) =>
      SurveyStats(total: json['total'] as int, totalResponses: json['totalResponses'] as int);
}

class KindCount {
  final String kind;
  final int total;
  const KindCount({required this.kind, required this.total});
  factory KindCount.fromJson(Map<String, dynamic> json) =>
      KindCount(kind: json['kind'] as String, total: json['total'] as int);
}

class EmailStats {
  final int sent;
  final int failed;
  const EmailStats({required this.sent, required this.failed});
  factory EmailStats.fromJson(Map<String, dynamic> json) =>
      EmailStats(sent: json['sent'] as int, failed: json['failed'] as int);
}

/// Real question-category analytics (docs/EKSIKLER.md admin §5) — backed
/// by `AskArucadLog`, which didn't exist before this phase: every real
/// question sent through Ask ARUCAD is now logged and categorized.
class AskArucadStats {
  final int totalQuestions;
  final List<LabelCount> byCategory;
  final List<LabelCount> topQuestions;
  const AskArucadStats({required this.totalQuestions, required this.byCategory, required this.topQuestions});
  factory AskArucadStats.fromJson(Map<String, dynamic> json) => AskArucadStats(
        totalQuestions: json['totalQuestions'] as int,
        byCategory: (json['byCategory'] as List<dynamic>)
            .map((e) => LabelCount.fromJson(e as Map<String, dynamic>, 'category'))
            .toList(),
        topQuestions: (json['topQuestions'] as List<dynamic>)
            .map((e) => LabelCount.fromJson(e as Map<String, dynamic>, 'question'))
            .toList(),
      );
}

class MapPlaceDensity {
  final String placeId;
  final String placeName;
  final int checkinsToday;
  final String level;
  const MapPlaceDensity({
    required this.placeId,
    required this.placeName,
    required this.checkinsToday,
    required this.level,
  });
  factory MapPlaceDensity.fromJson(Map<String, dynamic> json) => MapPlaceDensity(
        placeId: json['placeId'] as String,
        placeName: json['placeName'] as String,
        checkinsToday: json['checkinsToday'] as int,
        level: json['level'] as String,
      );
}

/// Real-time heatmap snapshot embedded directly in the dashboard
/// (docs/EKSIKLER.md admin §6) — same thresholds/data source as the
/// student-facing live map's density endpoint, not a second engine.
class MapStats {
  final List<MapPlaceDensity> busiestPlaces;
  final List<MapPlaceDensity> quietestPlaces;
  final List<LabelCount> byHour;
  const MapStats({required this.busiestPlaces, required this.quietestPlaces, required this.byHour});
  factory MapStats.fromJson(Map<String, dynamic> json) => MapStats(
        busiestPlaces: (json['busiestPlaces'] as List<dynamic>)
            .map((e) => MapPlaceDensity.fromJson(e as Map<String, dynamic>))
            .toList(),
        quietestPlaces: (json['quietestPlaces'] as List<dynamic>)
            .map((e) => MapPlaceDensity.fromJson(e as Map<String, dynamic>))
            .toList(),
        byHour: (json['byHour'] as List<dynamic>)
            .map((e) => LabelCount.fromJson(e as Map<String, dynamic>, 'hour'))
            .toList(),
      );
}

class CatalogStats {
  final int places;
  final int clubs;
  final int sports;
  final int services;
  final int foodVenues;
  final int directoryEntries;
  final int mediaItems;
  const CatalogStats({
    required this.places,
    required this.clubs,
    required this.sports,
    required this.services,
    required this.foodVenues,
    required this.directoryEntries,
    required this.mediaItems,
  });
  factory CatalogStats.fromJson(Map<String, dynamic> json) => CatalogStats(
        places: json['places'] as int,
        clubs: json['clubs'] as int,
        sports: json['sports'] as int,
        services: json['services'] as int,
        foodVenues: json['foodVenues'] as int,
        directoryEntries: json['directoryEntries'] as int,
        mediaItems: json['mediaItems'] as int,
      );
}
