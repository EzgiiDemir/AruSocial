/// Real, live-aggregated usage statistics for the Admin Panel's
/// İstatistikler tab — mirrors `Admin\StatsController::index()` exactly,
/// down to the two fields that are honestly *not* trackable from this
/// backend rather than faked (see [AdminStats.appUsage]).
class AdminStats {
  final AppUsageStats appUsage;
  final UserSummaryStats userSummary;
  final CheckinStats checkins;
  final EventStats events;
  final SocialStats social;
  final SurveyStats surveys;
  final List<KindCount> activityByKind;
  final EmailStats email;
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
  const UserSummaryStats({
    required this.realAccountCount,
    required this.note,
    required this.totalXp,
    required this.totalStrikes,
    required this.bannedAccounts,
  });
  factory UserSummaryStats.fromJson(Map<String, dynamic> json) => UserSummaryStats(
        realAccountCount: json['realAccountCount'] as int,
        note: json['note'] as String,
        totalXp: json['totalXp'] as int,
        totalStrikes: json['totalStrikes'] as int,
        bannedAccounts: json['bannedAccounts'] as int,
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

class CheckinStats {
  final int total;
  final int visibleToOthers;
  final List<PlaceCount> mostCheckedInPlaces;
  final List<DailyCount> byDay;
  const CheckinStats({
    required this.total,
    required this.visibleToOthers,
    required this.mostCheckedInPlaces,
    required this.byDay,
  });
  factory CheckinStats.fromJson(Map<String, dynamic> json) => CheckinStats(
        total: json['total'] as int,
        visibleToOthers: json['visibleToOthers'] as int,
        mostCheckedInPlaces: (json['mostCheckedInPlaces'] as List<dynamic>)
            .map((e) => PlaceCount.fromJson(e as Map<String, dynamic>))
            .toList(),
        byDay: (json['byDay'] as List<dynamic>)
            .map((e) => DailyCount.fromJson(e as Map<String, dynamic>))
            .toList(),
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

class EventStats {
  final int total;
  final int published;
  final int pendingReview;
  final int totalJoins;
  final int formsSubmitted;
  final int attendanceApproved;
  final List<EventJoinCount> mostJoinedEvents;
  const EventStats({
    required this.total,
    required this.published,
    required this.pendingReview,
    required this.totalJoins,
    required this.formsSubmitted,
    required this.attendanceApproved,
    required this.mostJoinedEvents,
  });
  factory EventStats.fromJson(Map<String, dynamic> json) => EventStats(
        total: json['total'] as int,
        published: json['published'] as int,
        pendingReview: json['pendingReview'] as int,
        totalJoins: json['totalJoins'] as int,
        formsSubmitted: json['formsSubmitted'] as int,
        attendanceApproved: json['attendanceApproved'] as int,
        mostJoinedEvents: (json['mostJoinedEvents'] as List<dynamic>)
            .map((e) => EventJoinCount.fromJson(e as Map<String, dynamic>))
            .toList(),
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
  const SocialStats({
    required this.feedPosts,
    required this.comments,
    required this.stories,
    required this.reviews,
    required this.averageRating,
    required this.moderationReportsFiled,
    required this.moderationReportsUnresolved,
  });
  factory SocialStats.fromJson(Map<String, dynamic> json) => SocialStats(
        feedPosts: json['feedPosts'] as int,
        comments: json['comments'] as int,
        stories: json['stories'] as int,
        reviews: json['reviews'] as int,
        averageRating: (json['averageRating'] as num).toDouble(),
        moderationReportsFiled: json['moderationReportsFiled'] as int,
        moderationReportsUnresolved: json['moderationReportsUnresolved'] as int,
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
