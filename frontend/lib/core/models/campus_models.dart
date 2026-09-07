import 'dart:typed_data';

import '../network/media_url.dart';
import 'content_block.dart';

/// A single real check-in shown in a place's "recently here" list —
/// `initial` is the first letter of the checked-in student's real name,
/// never a fabricated one.
class CampusCheckinEntry {
  final String initial;
  final DateTime? checkedInAt;

  const CampusCheckinEntry({required this.initial, this.checkedInAt});

  factory CampusCheckinEntry.fromJson(Map<String, dynamic> json) {
    final raw = json['checkedInAt'] as String?;
    return CampusCheckinEntry(
      initial: json['initial'] as String? ?? '?',
      checkedInAt: raw == null ? null : DateTime.tryParse(raw),
    );
  }
}

class CampusPlace {
  final String id;
  final String name;
  final String category;
  final double lat;
  final double lng;
  final String description;
  final String distance;
  final String density;
  final String street;
  final String? tourUrl;
  final String? tourTarget;
  final bool accessible;
  final int photos;
  final double rating;
  final String? coverUrl;
  final int recentCheckins;
  final List<CampusCheckinEntry> recentCheckinEntries;

  const CampusPlace({
    required this.id,
    required this.name,
    required this.category,
    required this.lat,
    required this.lng,
    required this.description,
    required this.distance,
    required this.density,
    required this.street,
    required this.tourUrl,
    this.tourTarget,
    required this.accessible,
    required this.photos,
    required this.rating,
    this.coverUrl,
    this.recentCheckins = 0,
    this.recentCheckinEntries = const [],
  });

  factory CampusPlace.fromJson(Map<String, dynamic> json) {
    return CampusPlace(
      id: json['id'] as String,
      name: json['name'] as String,
      category: json['category'] as String,
      lat: (json['lat'] as num).toDouble(),
      lng: (json['lng'] as num).toDouble(),
      description: json['description'] as String,
      distance: json['distance'] as String,
      density: json['density'] as String,
      street: json['street'] as String,
      tourUrl: json['tourUrl'] as String?,
      tourTarget: json['tourTarget'] as String?,
      accessible: json['accessible'] as bool,
      photos: json['photos'] as int,
      rating: (json['rating'] as num).toDouble(),
      coverUrl: json['coverUrl'] as String?,
      recentCheckins: (json['recentCheckins'] as num?)?.toInt() ?? 0,
      recentCheckinEntries: (json['recentCheckinEntries'] as List<dynamic>?)
              ?.map((e) => CampusCheckinEntry.fromJson(e as Map<String, dynamic>))
              .toList() ??
          const [],
    );
  }

  CampusPlace copyWith({
    String? density,
    double? rating,
    String? coverUrl,
    String? tourUrl,
    String? tourTarget,
    int? recentCheckins,
  }) =>
      CampusPlace(
        id: id,
        name: name,
        category: category,
        lat: lat,
        lng: lng,
        description: description,
        distance: distance,
        density: density ?? this.density,
        street: street,
        tourUrl: tourUrl ?? this.tourUrl,
        tourTarget: tourTarget ?? this.tourTarget,
        accessible: accessible,
        photos: photos,
        rating: rating ?? this.rating,
        coverUrl: coverUrl ?? this.coverUrl,
        recentCheckins: recentCheckins ?? this.recentCheckins,
        recentCheckinEntries: recentCheckinEntries,
      );
}

/// Privacy / personalization prefs that used to live only in
/// `AppSettingsStore` SharedPreferences. Rest mode stores them on `users`.
class UserSettings {
  final String locationVisibility;
  final bool nearbyDiscoverable;
  final bool checkInVisible;
  final bool personalization;
  final bool isPrivateProfile;
  /// Account-scoped UI language code: `TR` | `EN` | `RU`.
  final String preferredLanguage;

  const UserSettings({
    this.locationVisibility = 'ghost',
    this.nearbyDiscoverable = false,
    this.checkInVisible = true,
    this.personalization = true,
    this.isPrivateProfile = false,
    this.preferredLanguage = 'TR',
  });

  factory UserSettings.fromJson(Map<String, dynamic> json) {
    final raw = (json['preferredLanguage'] as String? ?? 'TR').toUpperCase();
    final lang = switch (raw) {
      'EN' || 'RU' || 'TR' => raw,
      _ => 'TR',
    };
    return UserSettings(
      locationVisibility: json['locationVisibility'] as String? ?? 'ghost',
      nearbyDiscoverable: json['nearbyDiscoverable'] as bool? ?? false,
      checkInVisible: json['checkInVisible'] as bool? ?? true,
      personalization: json['personalization'] as bool? ?? true,
      isPrivateProfile: json['isPrivateProfile'] as bool? ?? false,
      preferredLanguage: lang,
    );
  }
}

/// One real, admin-defined way to join an event (e.g. "Katılımcı",
/// "Gönüllü") — mirrors the backend's `event_participation_types` row.
class EventParticipationOption {
  final String id;
  final String label;

  const EventParticipationOption({required this.id, required this.label});

  factory EventParticipationOption.fromJson(Map<String, dynamic> json) {
    return EventParticipationOption(
      id: json['id'] as String,
      label: json['label'] as String,
    );
  }
}

class CampusEvent {
  final String id;
  final String title;
  final String time;
  final String placeName;
  final String category;
  final int attendees;
  final int xp;

  /// Real structured date behind place-availability/date-conflict checking
  /// (docs/EKSIKLER.md §4) — [time] stays the free-text display string
  /// ("14:00"); this is what the backend actually compares to detect a
  /// double-booked place. Null for older/free-text events with no date.
  final DateTime? eventDate;

  /// Held back from every normal read (`getEvents()`) until an admin
  /// flips it off — lets an event be prepared without going live early.
  final bool draft;

  /// If set and in the future, the event stays hidden from normal reads
  /// until that moment — a real "scheduled publish", not just a draft flag.
  final DateTime? publishAt;

  /// If set and in the past, the event stops appearing in normal reads —
  /// old/finished events don't need to be deleted to disappear.
  final DateTime? expiresAt;

  /// Free-text target audience (e.g. "Tümü", "Mimarlık Fakültesi") — shown
  /// to admins and students as a label. Not enforced as a filter: doing
  /// that for real needs each student's faculty/department on file, which
  /// this prototype's mock identity doesn't carry.
  final String audience;

  /// Who's running the event — real for admin-entered events, empty for
  /// the original 3 seed events (which were never claimed to be more than
  /// demo content — see the README's "Mock campus note").
  final String organizer;

  /// Real destination for the "kulübe bilgilendirme e-postası" sent on
  /// join (see [EventJoinResult]) — null means no club inbox is on file,
  /// so that email is honestly skipped rather than sent nowhere.
  final String? organizerEmail;

  /// A longer description shown on Event Detail — empty means genuinely
  /// not entered yet, not omitted from the model.
  final String description;

  /// FK into `places` — set when the event's venue was actually chosen
  /// from the real place list (admin dropdown) rather than typed as free
  /// text into [placeName]. Null for older/free-text events.
  final String? placeId;

  /// Real workflow state: `published` (admin-created, live), `draft`,
  /// `pending_review`/`rejected` (student-submitted via "Kendi Aktiviteni
  /// Oluştur", not yet — or never — approved). Only `published` events
  /// (and non-draft) show up in a normal listing — see [isVisibleNow].
  final String workflowStatus;

  /// Admin's reason for rejecting a student-submitted activity — null
  /// unless [workflowStatus] is `rejected`.
  final String? reviewNote;

  /// Set only for student-submitted activities (via "Kendi Aktiviteni
  /// Oluştur") — null for admin-created events.
  final String? createdByUserId;

  /// Department head chosen by the student for review routing.
  final String? responsibleStaffId;
  final String? responsibleStaffName;

  /// FK into `academic_years` — null means not tied to a specific year.
  final String? academicYearId;

  /// Optional rich, block-editor-authored content — shown on Event Detail
  /// under "Hakkında" when non-empty, in addition to [description]. Kept
  /// separate from [description] since that plain string is still what
  /// feeds short contexts (Ask ARUCAD's prompt, list previews) — a block
  /// list doesn't belong there.
  final List<ContentBlock> body;

  /// Real, admin-defined ways to join this specific event (e.g. Katılımcı
  /// / Gönüllü / Organizasyon) — empty means the event hasn't defined any,
  /// in which case the join popup just skips straight to a plain confirm.
  final List<EventParticipationOption> participationTypes;

  /// True when created via poster → AI draft (still workflow draft).
  final bool aiDraft;

  const CampusEvent({
    required this.id,
    required this.title,
    required this.time,
    required this.placeName,
    required this.category,
    required this.attendees,
    required this.xp,
    this.eventDate,
    this.draft = false,
    this.publishAt,
    this.expiresAt,
    this.audience = 'Tümü',
    this.organizer = '',
    this.organizerEmail,
    this.description = '',
    this.placeId,
    this.workflowStatus = 'published',
    this.reviewNote,
    this.createdByUserId,
    this.responsibleStaffId,
    this.responsibleStaffName,
    this.academicYearId,
    this.body = const [],
    this.participationTypes = const [],
    this.aiDraft = false,
  });

  /// Whether this event should appear in a normal (non-admin) listing right
  /// now: not a draft, its scheduled publish time (if any) has passed, and
  /// its expiry (if any) hasn't.
  bool get isVisibleNow {
    if (draft) return false;
    if (workflowStatus != 'published') return false;
    final now = DateTime.now();
    if (publishAt != null && publishAt!.isAfter(now)) return false;
    if (expiresAt != null && expiresAt!.isBefore(now)) return false;
    return true;
  }

  factory CampusEvent.fromJson(Map<String, dynamic> json) {
    return CampusEvent(
      id: json['id'] as String,
      title: json['title'] as String,
      time: json['time'] as String,
      placeName: json['placeName'] as String,
      category: json['category'] as String,
      attendees: json['attendees'] as int,
      xp: json['xp'] as int,
      eventDate: json['eventDate'] == null
          ? null
          : DateTime.tryParse(json['eventDate'] as String),
      draft: json['draft'] as bool? ?? false,
      publishAt: json['publishAt'] == null
          ? null
          : DateTime.tryParse(json['publishAt'] as String),
      expiresAt: json['expiresAt'] == null
          ? null
          : DateTime.tryParse(json['expiresAt'] as String),
      audience: json['audience'] as String? ?? 'Tümü',
      organizer: json['organizer'] as String? ?? '',
      organizerEmail: json['organizerEmail'] as String?,
      description: json['description'] as String? ?? '',
      placeId: json['placeId'] as String?,
      workflowStatus: json['workflowStatus'] as String? ?? 'published',
      reviewNote: json['reviewNote'] as String?,
      // The backend's User model uses an auto-increment integer id (unlike
      // this app's other UUID-style string ids), so this arrives as a
      // JSON number — normalized to a string here to keep CampusEvent's
      // own id fields consistently typed regardless of source.
      createdByUserId: json['createdByUserId']?.toString(),
      responsibleStaffId: json['responsibleStaffId'] as String?,
      responsibleStaffName: json['responsibleStaffName'] as String?,
      academicYearId: json['academicYearId'] as String?,
      body: blocksFromJson(json['body']),
      participationTypes: (json['participationTypes'] as List<dynamic>?)
              ?.map((e) =>
                  EventParticipationOption.fromJson(e as Map<String, dynamic>))
              .toList() ??
          const [],
      aiDraft: json['aiDraft'] as bool? ?? false,
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'title': title,
        'time': time,
        'placeName': placeName,
        'category': category,
        'attendees': attendees,
        'xp': xp,
        if (eventDate != null)
          'eventDate': eventDate!.toIso8601String().split('T').first,
        'draft': draft,
        if (publishAt != null) 'publishAt': publishAt!.toIso8601String(),
        if (expiresAt != null) 'expiresAt': expiresAt!.toIso8601String(),
        'audience': audience,
        if (organizer.isNotEmpty) 'organizer': organizer,
        if (organizerEmail != null) 'organizerEmail': organizerEmail,
        if (description.isNotEmpty) 'description': description,
        if (placeId != null) 'placeId': placeId,
        if (academicYearId != null) 'academicYearId': academicYearId,
        if (body.isNotEmpty) 'body': blocksToJson(body),
      };

  /// Convenience for building an edited copy in the admin form — the
  /// server-managed fields ([workflowStatus], [reviewNote],
  /// [createdByUserId], [participationTypes]) are deliberately not
  /// overridable here since the admin event form never sets those
  /// directly.
  CampusEvent copyWith({
    String? title,
    String? time,
    DateTime? eventDate,
    String? placeName,
    String? placeId,
    String? category,
    int? attendees,
    int? xp,
    bool? draft,
    DateTime? publishAt,
    DateTime? expiresAt,
    String? audience,
    String? organizer,
    String? organizerEmail,
    String? description,
    String? academicYearId,
    List<ContentBlock>? body,
  }) =>
      CampusEvent(
        id: id,
        title: title ?? this.title,
        time: time ?? this.time,
        eventDate: eventDate ?? this.eventDate,
        placeName: placeName ?? this.placeName,
        placeId: placeId ?? this.placeId,
        category: category ?? this.category,
        attendees: attendees ?? this.attendees,
        xp: xp ?? this.xp,
        draft: draft ?? this.draft,
        publishAt: publishAt ?? this.publishAt,
        expiresAt: expiresAt ?? this.expiresAt,
        audience: audience ?? this.audience,
        organizer: organizer ?? this.organizer,
        organizerEmail: organizerEmail ?? this.organizerEmail,
        description: description ?? this.description,
        academicYearId: academicYearId ?? this.academicYearId,
        workflowStatus: workflowStatus,
        reviewNote: reviewNote,
        createdByUserId: createdByUserId,
        responsibleStaffId: responsibleStaffId,
        responsibleStaffName: responsibleStaffName,
        body: body ?? this.body,
        participationTypes: participationTypes,
        aiDraft: aiDraft,
      );
}

class Quest {
  final String id;
  final String title;
  final String subtitle;
  final int progress;
  final int target;
  final int reward;

  const Quest({
    required this.id,
    required this.title,
    required this.subtitle,
    required this.progress,
    required this.target,
    required this.reward,
  });

  factory Quest.fromJson(Map<String, dynamic> json) {
    return Quest(
      id: json['id'] as String,
      title: json['title'] as String,
      subtitle: json['subtitle'] as String,
      progress: json['progress'] as int,
      target: json['target'] as int,
      reward: json['reward'] as int,
    );
  }
}

class PostComment {
  final String id;
  final String author;
  final String text;
  final String meta;

  const PostComment({
    required this.id,
    required this.author,
    required this.text,
    required this.meta,
  });

  factory PostComment.fromJson(Map<String, dynamic> json) {
    return PostComment(
      id: json['id'] as String,
      author: json['author'] as String,
      text: json['text'] as String,
      meta: json['meta'] as String,
    );
  }
}

/// Who a post/story is visible to. There's no real multi-account backend in
/// this prototype (single signed-in session), so "onlyMe" can't be verified
/// by logging in as a second student — but the flag is real and every read
/// path respects it, ready for a real multi-user backend.
enum PostVisibility { everyone, friends, onlyMe }

extension PostVisibilityApi on PostVisibility {
  String get apiValue => switch (this) {
        PostVisibility.onlyMe => 'onlyMe',
        PostVisibility.friends => 'friends',
        PostVisibility.everyone => 'everyone',
      };

  static PostVisibility fromApi(String? raw) => switch (raw) {
        'onlyMe' => PostVisibility.onlyMe,
        'friends' => PostVisibility.friends,
        _ => PostVisibility.everyone,
      };
}

/// What kind of real event produced a feed post — drives the Social feed's
/// Kampüs filter (official announcements). Deliberately minimal: these are
/// the only kinds this app can actually tell apart from real data.
enum FeedKind { post, checkIn, announcement }

/// A real, student-chosen content type for a post — drives the icon/badge
/// shown on the card and the Dersler/Etkinlikler feed filters. Deliberately
/// student-declared (a compose-time choice), not AI-classified — there's no
/// real classification model in this project, and guessing would be worse
/// than asking.
enum PostCategory { normal, ders, proje, basari, etkinlik }

extension PostCategoryInfo on PostCategory {
  String get emoji => switch (this) {
        PostCategory.normal => '📸',
        PostCategory.ders => '📚',
        PostCategory.proje => '💻',
        PostCategory.basari => '🏆',
        PostCategory.etkinlik => '📢',
      };
}

class FeedPost {
  final String id;
  final String authorId;
  final String? authorAvatarUrl;
  final String name;
  final String text;
  final String meta;
  final int likes;
  final bool likedByMe;
  final String? imageUrl;

  /// Server-confirmed MIME type for an approved social attachment.  Do not
  /// infer this from the protected `/media/{id}/file` URL, which has no file
  /// extension.
  final String? mediaMimeType;
  // Photos picked from the device camera/gallery have no real upload
  // backend to host them at a URL, so they're kept as in-memory bytes and
  // rendered with Image.memory — real, but session-only (lost on reload).
  final Uint8List? imageBytes;
  final List<PostComment> comments;
  final PostVisibility visibility;
  final FeedKind kind;
  final PostCategory postType;

  /// Real, student-entered metadata — "📚 Ders ekle" / "📍 Konum ekle" from
  /// the compose sheet. Null means genuinely not set, not omitted.
  final String? courseTag;
  final String? locationTag;

  /// True for admin/ARUCAD-published content (e.g. event announcements),
  /// false for anything a student posted — drives the visual distinction
  /// between official and student content in the feed.
  final bool official;
  final bool isPinned;
  final String workflowStatus;
  final DateTime? createdAt;

  const FeedPost({
    required this.id,
    this.authorId = '',
    this.authorAvatarUrl,
    required this.name,
    required this.text,
    required this.meta,
    required this.likes,
    this.likedByMe = false,
    this.imageUrl,
    this.mediaMimeType,
    this.imageBytes,
    this.comments = const [],
    this.visibility = PostVisibility.everyone,
    this.kind = FeedKind.post,
    this.postType = PostCategory.normal,
    this.courseTag,
    this.locationTag,
    this.official = false,
    this.isPinned = false,
    this.workflowStatus = 'published',
    this.createdAt,
  });

  /// Real hashtags parsed straight out of the post text (e.g. "#flutter") —
  /// not a separate field to fill in twice, same as how every real social
  /// app derives them.
  List<String> get hashtags => RegExp(r'#(\w+)')
      .allMatches(text)
      .map((m) => m.group(1)!.toLowerCase())
      .toList();

  /// Preserve the original post for storage/editing but omit emoji artwork
  /// from rendered feed text. Android emoji sets vary substantially by OS.
  String get displayText => String.fromCharCodes(
        text.runes.where((rune) =>
            !((rune >= 0x1F000 && rune <= 0x1FAFF) ||
                (rune >= 0x2600 && rune <= 0x27BF) ||
                rune == 0xFE0F ||
                rune == 0x200D ||
                rune == 0x20E3)),
      ).replaceAll(RegExp(r'\s+'), ' ').trim();

  FeedPost copyWith({
    int? likes,
    bool? likedByMe,
    List<PostComment>? comments,
    String? text,
    PostVisibility? visibility,
    bool? isPinned,
  }) {
    return FeedPost(
      id: id,
      authorId: authorId,
      authorAvatarUrl: authorAvatarUrl,
      name: name,
      text: text ?? this.text,
      meta: meta,
      likes: likes ?? this.likes,
      likedByMe: likedByMe ?? this.likedByMe,
      imageUrl: imageUrl,
      mediaMimeType: mediaMimeType,
      imageBytes: imageBytes,
      comments: comments ?? this.comments,
      visibility: visibility ?? this.visibility,
      kind: kind,
      postType: postType,
      courseTag: courseTag,
      locationTag: locationTag,
      official: official,
      isPinned: isPinned ?? this.isPinned,
      workflowStatus: workflowStatus,
      createdAt: createdAt,
    );
  }

  factory FeedPost.fromJson(Map<String, dynamic> json) {
    return FeedPost(
      id: json['id'] as String,
      authorId: '${json['authorId'] ?? ''}',
      authorAvatarUrl: MediaUrl.resolve(json['authorAvatarUrl'] as String?),
      name: json['name'] as String,
      text: json['text'] as String,
      meta: json['meta'] as String,
      likes: json['likes'] as int? ?? 0,
      likedByMe: json['likedByMe'] as bool? ?? false,
      imageUrl: MediaUrl.resolve(json['imageUrl'] as String?),
      mediaMimeType: json['mediaMimeType'] as String?,
      comments: (json['comments'] as List<dynamic>? ?? const [])
          .map((c) => PostComment.fromJson(c as Map<String, dynamic>))
          .toList(),
      visibility: PostVisibilityApi.fromApi(json['visibility'] as String?),
      // Falls back to `normal` for any value that isn't one of the app's
      // known categories (stale/hand-edited rows, future backend values)
      // instead of throwing and taking down the whole feed's decode.
      postType: PostCategory.values.firstWhere(
          (v) => v.name == json['postType'],
          orElse: () => PostCategory.normal),
      courseTag: json['courseTag'] as String?,
      locationTag: json['locationTag'] as String?,
      official: json['official'] as bool? ?? false,
      isPinned: json['isPinned'] as bool? ?? false,
      workflowStatus: json['workflowStatus'] as String? ?? 'published',
      createdAt: DateTime.tryParse(json['createdAt'] as String? ?? ''),
    );
  }
}

/// A 24h-expiring story — image or text-over-color, same "everyone / only
/// me" visibility rule as posts. [backgroundColorValue] is a raw ARGB int
/// (not a Flutter Color) so this model file stays UI-framework-free.
class CampusStory {
  final String id;
  final String authorId;
  final String authorName;
  final String? text;
  final Uint8List? imageBytes;
  final String? imageUrl;
  final int? backgroundColorValue;
  final Map<String, dynamic>? style;
  final PostVisibility visibility;
  final DateTime createdAt;
  // Real per-account seen state from `story_views` on the server — not a
  // device-local SharedPreferences guess, which never syncs across
  // installs/devices.
  final bool viewedByMe;

  CampusStory({
    required this.id,
    required this.authorId,
    required this.authorName,
    this.text,
    this.imageBytes,
    this.imageUrl,
    this.backgroundColorValue,
    this.style,
    this.visibility = PostVisibility.everyone,
    DateTime? createdAt,
    this.viewedByMe = false,
  }) : createdAt = createdAt ?? DateTime.now();

  bool get isExpired =>
      DateTime.now().difference(createdAt) > const Duration(hours: 24);

  CampusStory copyWith({bool? viewedByMe}) => CampusStory(
        id: id,
        authorId: authorId,
        authorName: authorName,
        text: text,
        imageBytes: imageBytes,
        imageUrl: imageUrl,
        backgroundColorValue: backgroundColorValue,
        style: style,
        visibility: visibility,
        createdAt: createdAt,
        viewedByMe: viewedByMe ?? this.viewedByMe,
      );
}

/// A real, admin/trainer-managed workshop equipment row — replaces the
/// previously hardcoded, identical-on-every-place `_workshopEquipment` const.
class WorkshopEquipmentItem {
  final String id;
  final String name;
  final bool available;

  const WorkshopEquipmentItem({
    required this.id,
    required this.name,
    required this.available,
  });

  factory WorkshopEquipmentItem.fromJson(Map<String, dynamic> json) {
    return WorkshopEquipmentItem(
      id: json['id'] as String,
      name: json['name'] as String,
      available: json['available'] as bool? ?? true,
    );
  }
}

/// A real, student-created collaboration-board post (auto-expires
/// server-side) — replaces the previously hardcoded `_collaborationBoard`.
class CampusCollaborationPost {
  final String id;
  final String authorId;
  final String? authorName;
  final String text;
  final DateTime? createdAt;

  const CampusCollaborationPost({
    required this.id,
    required this.authorId,
    this.authorName,
    required this.text,
    this.createdAt,
  });

  factory CampusCollaborationPost.fromJson(Map<String, dynamic> json) {
    final raw = json['createdAt'] as String?;
    return CampusCollaborationPost(
      id: json['id'] as String,
      authorId: '${json['authorId'] ?? ''}',
      authorName: json['authorName'] as String?,
      text: json['text'] as String,
      createdAt: raw == null ? null : DateTime.tryParse(raw),
    );
  }
}

/// The real per-place answer to "what's here" for a workshop: equipment
/// availability plus the live collaboration-board posts.
class WorkshopInfo {
  final List<WorkshopEquipmentItem> equipment;
  final List<CampusCollaborationPost> posts;

  const WorkshopInfo({this.equipment = const [], this.posts = const []});

  factory WorkshopInfo.fromJson(Map<String, dynamic> json) {
    return WorkshopInfo(
      equipment: (json['equipment'] as List<dynamic>? ?? const [])
          .map((e) => WorkshopEquipmentItem.fromJson(e as Map<String, dynamic>))
          .toList(),
      posts: (json['posts'] as List<dynamic>? ?? const [])
          .map((e) => CampusCollaborationPost.fromJson(e as Map<String, dynamic>))
          .toList(),
    );
  }
}

class Review {
  final String id;
  final String placeId;
  final String author;
  final int rating;
  final String comment;
  final String meta;

  const Review({
    required this.id,
    required this.placeId,
    required this.author,
    required this.rating,
    required this.comment,
    required this.meta,
  });

  factory Review.fromJson(Map<String, dynamic> json) {
    return Review(
      id: json['id'] as String,
      placeId: json['placeId'] as String,
      author: json['author'] as String,
      rating: json['rating'] as int,
      comment: json['comment'] as String,
      meta: json['meta'] as String,
    );
  }
}

class CampusUser {
  final String id;
  final String name;
  final String role;
  final int level;
  final int xp;
  final int places;
  final int events;
  final int memories;
  final List<String> interests;
  final String? avatarUrl;

  /// Real student-identity fields shown on the Social profile — what makes
  /// this a student platform rather than a generic photo feed. Left
  /// nullable/empty rather than invented when genuinely unset.
  final String? department;
  final String? year;
  final String? university;
  final List<String> clubs;
  final List<String> achievements;
  final List<String> projects;
  final bool isPrivateProfile;
  final bool isLocked;
  final int? followerCount;
  final int? followingCount;

  const CampusUser({
    required this.id,
    required this.name,
    required this.role,
    required this.level,
    required this.xp,
    required this.places,
    required this.events,
    required this.memories,
    required this.interests,
    this.avatarUrl,
    this.department,
    this.year,
    this.university,
    this.clubs = const [],
    this.achievements = const [],
    this.projects = const [],
    this.isPrivateProfile = false,
    this.isLocked = false,
    this.followerCount,
    this.followingCount,
  });

  factory CampusUser.fromJson(Map<String, dynamic> json) {
    return CampusUser(
      id: json['id'] as String,
      name: json['name'] as String,
      role: json['role'] as String,
      level: json['level'] as int,
      xp: json['xp'] as int,
      places: json['places'] as int,
      events: json['events'] as int,
      memories: json['memories'] as int,
      interests: (json['interests'] as List<dynamic>).cast<String>(),
      avatarUrl: MediaUrl.resolve(json['avatarUrl'] as String?),
      department: json['department'] as String?,
      year: json['year'] as String?,
      university: json['university'] as String?,
      clubs: (json['clubs'] as List<dynamic>?)?.cast<String>() ?? const [],
      achievements:
          (json['achievements'] as List<dynamic>?)?.cast<String>() ?? const [],
      projects:
          (json['projects'] as List<dynamic>?)?.cast<String>() ?? const [],
      isPrivateProfile: json['isPrivateProfile'] as bool? ?? false,
      isLocked: json['isLocked'] as bool? ?? false,
      followerCount: (json['followerCount'] as num?)?.toInt(),
      followingCount: (json['followingCount'] as num?)?.toInt(),
    );
  }

  CampusUser copyWith({
    int? xp,
    int? level,
    String? avatarUrl,
    String? department,
    String? year,
    String? university,
    List<String>? clubs,
    List<String>? achievements,
    List<String>? projects,
    bool? isPrivateProfile,
    bool? isLocked,
    int? followerCount,
    int? followingCount,
  }) =>
      CampusUser(
        id: id,
        name: name,
        role: role,
        level: level ?? this.level,
        xp: xp ?? this.xp,
        places: places,
        events: events,
        memories: memories,
        interests: interests,
        avatarUrl: avatarUrl ?? this.avatarUrl,
        department: department ?? this.department,
        year: year ?? this.year,
        university: university ?? this.university,
        clubs: clubs ?? this.clubs,
        achievements: achievements ?? this.achievements,
        projects: projects ?? this.projects,
        isPrivateProfile: isPrivateProfile ?? this.isPrivateProfile,
        isLocked: isLocked ?? this.isLocked,
        followerCount: followerCount ?? this.followerCount,
        followingCount: followingCount ?? this.followingCount,
      );

  UserRole get parsedRole => UserRole.parse(role);

  bool get canManagePlaces => parsedRole.canManageContent;
}

/// One row of the campus leaderboard — [isMe] marks the signed-in student's
/// own row so the UI can highlight it. Also doubles as the Keşfet/Social
/// "people" directory, so [department] is real, demo-labeled seed data
/// (same honesty pattern as every other seeded roster in this app), shown
/// under the peer's name — the "Bilgisayar Müh." line under an author.
class LeaderboardEntry {
  final String name;
  final int xp;
  final bool isMe;
  final String? department;
  final String? avatarUrl;

  const LeaderboardEntry(
      {required this.name,
      required this.xp,
      this.isMe = false,
      this.department,
      this.avatarUrl});
}

/// One real action the signed-in student has taken in the app — the source
/// of truth for the profile's activity history (no invented stats).
enum ActivityKind { checkIn, eventJoin, review, comment, like, report }

class ActivityItem {
  final String id;
  final ActivityKind kind;
  final String title;
  final String subtitle;
  final String meta;
  final DateTime timestamp;
  final int xp;

  ActivityItem({
    required this.id,
    required this.kind,
    required this.title,
    required this.subtitle,
    required this.meta,
    DateTime? timestamp,
    this.xp = 0,
  }) : timestamp = timestamp ?? DateTime.now();
}

/// Client-side UX gates. Must stay in exact parity with
/// `GranularPermissions::ROLE_BUCKETS` on the backend. The API remains the
/// authority — unknown/unparsed values fail closed to [student].
enum UserRole {
  student,
  clubManager,
  contentEditor,
  moderator,
  careerStaff,
  studentAffairs,
  superAdmin,
  // A department head/teacher/staff member publishing events/activities
  // scoped to their own department only — see Trainer Panel. Kept as its
  // own role (not folded into e.g. studentAffairs) because it's
  // department-scoped rather than a full admin capability — see
  // GranularPermissions::ROLE_BUCKETS on the backend, which this must stay
  // in exact parity with.
  trainer;

  /// Lowest privilege when [raw] is missing, empty, or not a known role name.
  static UserRole parse(String? raw) => tryParse(raw) ?? UserRole.student;

  /// `null` when [raw] is missing or not an exact enum name — never invents
  /// admin/trainer from garbage input.
  static UserRole? tryParse(String? raw) {
    if (raw == null || raw.isEmpty) return null;
    for (final value in values) {
      if (value.name == raw) return value;
    }
    return null;
  }
}

extension UserRoleLabel on UserRole {
  String get label => switch (this) {
        UserRole.student => 'Öğrenci',
        UserRole.clubManager => 'Kulüp Yöneticisi',
        UserRole.contentEditor => 'İçerik Editörü',
        UserRole.moderator => 'Moderatör',
        UserRole.careerStaff => 'Kariyer Ofisi',
        UserRole.studentAffairs => 'Öğrenci İşleri',
        UserRole.superAdmin => 'Yönetici',
        UserRole.trainer => 'Eğitmen (Bölüm Başkanı)',
      };

  bool get _isSuper => this == UserRole.superAdmin;

  /// Content CRUD: events, clubs, sports, services, food, directory, pages.
  /// Mirrors `GranularPermissions` `manageContent` + super-admin bypass.
  bool get canManageContent =>
      _isSuper ||
      this == UserRole.contentEditor ||
      this == UserRole.clubManager ||
      this == UserRole.studentAffairs ||
      this == UserRole.careerStaff;

  bool get canModerate => _isSuper || this == UserRole.moderator;

  /// Roles and secrets — super admin only (`manageSiteSettings` bucket is empty).
  bool get canManageSiteSettings => _isSuper;

  /// Trainer Panel — own department only (`manageOwnDepartment` + super-admin).
  bool get canManageOwnDepartment => _isSuper || this == UserRole.trainer;

  /// Read-mostly admin surfaces + who may open the Admin Panel at all.
  bool get canViewAdmin =>
      _isSuper ||
      this == UserRole.contentEditor ||
      this == UserRole.clubManager ||
      this == UserRole.studentAffairs ||
      this == UserRole.careerStaff ||
      this == UserRole.moderator;

  bool get canOpenAdminPanel => canViewAdmin;

  bool get canOpenTrainerPanel => canManageOwnDepartment;

  /// Mirrors backend `appointments.manage` → `campusOps` bucket (+ superAdmin).
  bool get canManageAppointments =>
      _isSuper ||
      this == UserRole.contentEditor ||
      this == UserRole.clubManager ||
      this == UserRole.studentAffairs ||
      this == UserRole.careerStaff ||
      this == UserRole.trainer;
}

/// What happened to a piece of reported content — a real, persisted
/// decision with an audit trail, even though there's no multi-admin
/// backend yet to enforce it across accounts.
enum ModerationAction { dismissed, warned, removed }

enum ReportedKind { post, place }

class ModerationReport {
  final String id;
  final ReportedKind kind;
  final String targetId;
  final String targetLabel;
  final String reason;
  final DateTime reportedAt;
  final ModerationAction? action;
  final DateTime? resolvedAt;

  const ModerationReport({
    required this.id,
    required this.kind,
    required this.targetId,
    required this.targetLabel,
    required this.reason,
    required this.reportedAt,
    this.action,
    this.resolvedAt,
  });

  ModerationReport copyWith({ModerationAction? action, DateTime? resolvedAt}) =>
      ModerationReport(
        id: id,
        kind: kind,
        targetId: targetId,
        targetLabel: targetLabel,
        reason: reason,
        reportedAt: reportedAt,
        action: action ?? this.action,
        resolvedAt: resolvedAt ?? this.resolvedAt,
      );
}

/// One real, admin-entered "who/what is in this room" record — the
/// Building → Floor → Room → Person layer of the product brief. Starts
/// empty in every fresh install: there is no seed data here, because we
/// don't actually know ARUCAD's real room numbers or staff assignments —
/// fabricating them would be worse than leaving this blank until an admin
/// fills it in for real.
class DirectoryEntry {
  final String id;
  final String building;
  final String? floor;
  final String? room;
  final String occupantName;
  final String? occupantRole;

  /// Optional link to the `CampusService` this room/person handles, so
  /// Service Detail can show "who's actually in charge of this" without
  /// duplicating the same building/floor/room data twice.
  final String? relatedServiceId;
  final String? tourUrl;
  final String? tourTarget;

  const DirectoryEntry({
    required this.id,
    required this.building,
    this.floor,
    this.room,
    required this.occupantName,
    this.occupantRole,
    this.relatedServiceId,
    this.tourUrl,
    this.tourTarget,
  });

  factory DirectoryEntry.fromJson(Map<String, dynamic> json) => DirectoryEntry(
        id: json['id'] as String,
        building: json['building'] as String,
        floor: json['floor'] as String?,
        room: json['room'] as String?,
        occupantName: json['occupantName'] as String,
        occupantRole: json['occupantRole'] as String?,
        relatedServiceId: json['relatedServiceId'] as String?,
        tourUrl: json['tourUrl'] as String?,
        tourTarget: json['tourTarget'] as String?,
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'building': building,
        if (floor != null) 'floor': floor,
        if (room != null) 'room': room,
        'occupantName': occupantName,
        if (occupantRole != null) 'occupantRole': occupantRole,
        if (relatedServiceId != null) 'relatedServiceId': relatedServiceId,
        if (tourUrl != null) 'tourUrl': tourUrl,
        if (tourTarget != null) 'tourTarget': tourTarget,
      };
}
