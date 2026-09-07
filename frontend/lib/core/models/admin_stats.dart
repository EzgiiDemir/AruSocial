/// Real, live-aggregated usage statistics for the Admin Panel dashboard.
/// Mirrors `Admin\StatsController::index()` — no client-side summing of
/// raw lists for these totals.
class AdminStats {
  final AppUsageStats appUsage;
  final UserSummaryStats userSummary;
  final CheckinStats checkins;
  final EventStats events;
  final SocialStats social;
  final ApplicationStats applications;
  final NotificationStats notifications;
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
    required this.applications,
    required this.notifications,
    required this.surveys,
    required this.activityByKind,
    required this.email,
    required this.catalog,
  });

  factory AdminStats.fromJson(Map<String, dynamic> json) => AdminStats(
        appUsage: AppUsageStats.fromJson(json['appUsage'] as Map<String, dynamic>),
        userSummary:
            UserSummaryStats.fromJson(json['userSummary'] as Map<String, dynamic>),
        checkins: CheckinStats.fromJson(json['checkins'] as Map<String, dynamic>),
        events: EventStats.fromJson(json['events'] as Map<String, dynamic>),
        social: SocialStats.fromJson(json['social'] as Map<String, dynamic>),
        applications: ApplicationStats.fromJson(
            json['applications'] as Map<String, dynamic>? ?? const {}),
        notifications: NotificationStats.fromJson(
            json['notifications'] as Map<String, dynamic>? ?? const {}),
        surveys: SurveyStats.fromJson(json['surveys'] as Map<String, dynamic>),
        activityByKind: (json['activityByKind'] as List<dynamic>)
            .map((e) => KindCount.fromJson(e as Map<String, dynamic>))
            .toList(),
        email: EmailStats.fromJson(json['email'] as Map<String, dynamic>),
        catalog: CatalogStats.fromJson(json['catalog'] as Map<String, dynamic>),
      );
}

class AppUsageStats {
  final bool trackable;
  final String note;
  const AppUsageStats({required this.trackable, required this.note});
  factory AppUsageStats.fromJson(Map<String, dynamic> json) => AppUsageStats(
      trackable: json['trackable'] as bool? ?? false,
      note: json['note'] as String? ?? '');
}

class UserSummaryStats {
  final int realAccountCount;
  final int activeAccounts;
  final int newAccounts;
  final String note;
  final int totalXp;
  final int totalStrikes;
  final int bannedAccounts;
  const UserSummaryStats({
    required this.realAccountCount,
    required this.activeAccounts,
    required this.newAccounts,
    required this.note,
    required this.totalXp,
    required this.totalStrikes,
    required this.bannedAccounts,
  });
  factory UserSummaryStats.fromJson(Map<String, dynamic> json) =>
      UserSummaryStats(
        realAccountCount: (json['realAccountCount'] as num?)?.toInt() ?? 0,
        activeAccounts: (json['activeAccounts'] as num?)?.toInt() ??
            (json['realAccountCount'] as num?)?.toInt() ??
            0,
        newAccounts: (json['newAccounts'] as num?)?.toInt() ?? 0,
        note: json['note'] as String? ?? '',
        totalXp: (json['totalXp'] as num?)?.toInt() ?? 0,
        totalStrikes: (json['totalStrikes'] as num?)?.toInt() ?? 0,
        bannedAccounts: (json['bannedAccounts'] as num?)?.toInt() ?? 0,
      );
}

class PlaceCount {
  final String placeId;
  final String placeName;
  final int total;
  const PlaceCount(
      {required this.placeId, required this.placeName, required this.total});
  factory PlaceCount.fromJson(Map<String, dynamic> json) => PlaceCount(
        placeId: '${json['place_id'] ?? json['placeId'] ?? ''}',
        placeName: '${json['place_name'] ?? json['placeName'] ?? ''}',
        total: (json['total'] as num?)?.toInt() ?? 0,
      );
}

class DailyCount {
  final String day;
  final int total;
  const DailyCount({required this.day, required this.total});
  factory DailyCount.fromJson(Map<String, dynamic> json) => DailyCount(
      day: '${json['day']}', total: (json['total'] as num?)?.toInt() ?? 0);
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
        total: (json['total'] as num?)?.toInt() ?? 0,
        visibleToOthers: (json['visibleToOthers'] as num?)?.toInt() ?? 0,
        mostCheckedInPlaces: (json['mostCheckedInPlaces'] as List<dynamic>? ?? [])
            .map((e) => PlaceCount.fromJson(e as Map<String, dynamic>))
            .toList(),
        byDay: (json['byDay'] as List<dynamic>? ?? [])
            .map((e) => DailyCount.fromJson(e as Map<String, dynamic>))
            .toList(),
      );
}

class EventJoinCount {
  final String eventId;
  final String title;
  final int total;
  const EventJoinCount(
      {required this.eventId, required this.title, required this.total});
  factory EventJoinCount.fromJson(Map<String, dynamic> json) => EventJoinCount(
        eventId: '${json['event_id'] ?? json['eventId'] ?? ''}',
        title: '${json['title'] ?? ''}',
        total: (json['total'] as num?)?.toInt() ?? 0,
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
        total: (json['total'] as num?)?.toInt() ?? 0,
        published: (json['published'] as num?)?.toInt() ?? 0,
        pendingReview: (json['pendingReview'] as num?)?.toInt() ?? 0,
        totalJoins: (json['totalJoins'] as num?)?.toInt() ?? 0,
        formsSubmitted: (json['formsSubmitted'] as num?)?.toInt() ?? 0,
        attendanceApproved: (json['attendanceApproved'] as num?)?.toInt() ?? 0,
        mostJoinedEvents: (json['mostJoinedEvents'] as List<dynamic>? ?? [])
            .map((e) => EventJoinCount.fromJson(e as Map<String, dynamic>))
            .toList(),
      );
}

class SocialStats {
  final int feedPosts;
  final int comments;
  final int likes;
  final int stories;
  final int reviews;
  final double averageRating;
  final int moderationReportsFiled;
  final int moderationReportsUnresolved;
  const SocialStats({
    required this.feedPosts,
    required this.comments,
    required this.likes,
    required this.stories,
    required this.reviews,
    required this.averageRating,
    required this.moderationReportsFiled,
    required this.moderationReportsUnresolved,
  });
  factory SocialStats.fromJson(Map<String, dynamic> json) => SocialStats(
        feedPosts: (json['feedPosts'] as num?)?.toInt() ?? 0,
        comments: (json['comments'] as num?)?.toInt() ?? 0,
        likes: (json['likes'] as num?)?.toInt() ?? 0,
        stories: (json['stories'] as num?)?.toInt() ?? 0,
        reviews: (json['reviews'] as num?)?.toInt() ?? 0,
        averageRating: (json['averageRating'] as num?)?.toDouble() ?? 0,
        moderationReportsFiled:
            (json['moderationReportsFiled'] as num?)?.toInt() ?? 0,
        moderationReportsUnresolved:
            (json['moderationReportsUnresolved'] as num?)?.toInt() ?? 0,
      );
}

class ApplicationStats {
  final int pending;
  final int approved;
  final int rejected;
  final List<KindCount> byType;
  const ApplicationStats({
    required this.pending,
    required this.approved,
    required this.rejected,
    required this.byType,
  });
  factory ApplicationStats.fromJson(Map<String, dynamic> json) =>
      ApplicationStats(
        pending: (json['pending'] as num?)?.toInt() ?? 0,
        approved: (json['approved'] as num?)?.toInt() ?? 0,
        rejected: (json['rejected'] as num?)?.toInt() ?? 0,
        byType: (json['byType'] as List<dynamic>? ?? [])
            .map((e) {
              final m = e as Map<String, dynamic>;
              return KindCount(
                kind: '${m['target_type'] ?? m['kind'] ?? ''}',
                total: (m['total'] as num?)?.toInt() ?? 0,
              );
            })
            .toList(),
      );
}

class NotificationStats {
  final int total;
  final int unread;
  const NotificationStats({required this.total, required this.unread});
  factory NotificationStats.fromJson(Map<String, dynamic> json) =>
      NotificationStats(
        total: (json['total'] as num?)?.toInt() ?? 0,
        unread: (json['unread'] as num?)?.toInt() ?? 0,
      );
}

class SurveyStats {
  final int total;
  final int totalResponses;
  const SurveyStats({required this.total, required this.totalResponses});
  factory SurveyStats.fromJson(Map<String, dynamic> json) => SurveyStats(
      total: (json['total'] as num?)?.toInt() ?? 0,
      totalResponses: (json['totalResponses'] as num?)?.toInt() ?? 0);
}

class KindCount {
  final String kind;
  final int total;
  const KindCount({required this.kind, required this.total});
  factory KindCount.fromJson(Map<String, dynamic> json) => KindCount(
      kind: '${json['kind']}', total: (json['total'] as num?)?.toInt() ?? 0);
}

class EmailStats {
  final int sent;
  final int failed;
  const EmailStats({required this.sent, required this.failed});
  factory EmailStats.fromJson(Map<String, dynamic> json) => EmailStats(
      sent: (json['sent'] as num?)?.toInt() ?? 0,
      failed: (json['failed'] as num?)?.toInt() ?? 0);
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
        places: (json['places'] as num?)?.toInt() ?? 0,
        clubs: (json['clubs'] as num?)?.toInt() ?? 0,
        sports: (json['sports'] as num?)?.toInt() ?? 0,
        services: (json['services'] as num?)?.toInt() ?? 0,
        foodVenues: (json['foodVenues'] as num?)?.toInt() ?? 0,
        directoryEntries: (json['directoryEntries'] as num?)?.toInt() ?? 0,
        mediaItems: (json['mediaItems'] as num?)?.toInt() ?? 0,
      );
}
