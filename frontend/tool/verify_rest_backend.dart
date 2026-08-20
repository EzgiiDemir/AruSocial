// Smoke-tests RestCampusRepository against a *running* Laravel backend —
// exercises the exact same ApiClient + DTO parsing code the Flutter app
// uses, without needing a browser or emulator. Run the backend first
// (`cd backend && php artisan serve --port=4000`), then:
//
//   dart run tool/verify_rest_backend.dart [baseUrl]
//
// Default baseUrl is http://localhost:4000/api/v1.
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/models/admin_page.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/content_block.dart';
import 'package:arucad_campus_prototype/core/models/survey.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/rest_campus_repository.dart';
import 'package:http/http.dart' as http;

Future<void> main(List<String> args) async {
  final baseUrl = args.isNotEmpty ? args[0] : 'http://localhost:4000/api/v1';
  stdout.writeln('Verifying RestCampusRepository against $baseUrl ...\n');
  final repo = RestCampusRepository(client: ApiClient(baseUrl: baseUrl));
  final http.Client raw = http.Client();

  var failures = 0;
  Future<void> check(String label, Future<void> Function() body) async {
    try {
      await body();
      stdout.writeln('  OK   $label');
    } catch (e) {
      failures++;
      stdout.writeln('  FAIL $label -> $e');
    }
  }

  // Endpoints beyond CampusRepository's own interface (clubs, surveys,
  // RBAC, ...) don't have Dart model classes yet — these hit them
  // directly over HTTP and check the raw JSON shape instead.
  Future<dynamic> getJson(String path) async {
    final res = await raw.get(Uri.parse('$baseUrl$path'));
    if (res.statusCode < 200 || res.statusCode >= 300) {
      throw 'GET $path -> HTTP ${res.statusCode}: ${res.body}';
    }
    return jsonDecode(res.body)['data'];
  }

  CampusUser? me;
  List<CampusPlace> places = const [];
  List<CampusEvent> events = const [];

  await check('getMe()', () async {
    me = await repo.getMe();
    if (me!.name.isEmpty) throw 'empty name';
  });
  await check('getPlaces()', () async {
    places = await repo.getPlaces();
    if (places.isEmpty) throw 'no places returned';
  });
  await check('getEvents()', () async {
    events = await repo.getEvents();
    if (events.isEmpty) throw 'no events returned';
  });
  await check('getQuests()', () => repo.getQuests());
  await check('getFeed()', () => repo.getFeed());
  await check('getStories()', () => repo.getStories());
  await check('getLeaderboard()', () => repo.getLeaderboard());
  await check('getMyActivity()', () => repo.getMyActivity());

  if (places.isNotEmpty) {
    await check('getReviews(${places.first.id})', () => repo.getReviews(places.first.id));
  }
  if (events.isNotEmpty) {
    final before = events.first.attendees;
    await check('joinEvent(${events.first.id}) persists (idempotent per user)', () async {
      final typeId = events.first.participationTypes.isNotEmpty
          ? events.first.participationTypes.first.id
          : null;
      final first = await repo.joinEvent(events.first.id, participationTypeId: typeId);
      final again = await repo.joinEvent(events.first.id);
      // Joining twice as the same demo user must not double-count — so this
      // only asserts the count never *drops*, not that it always climbs.
      final after = await repo.getEvents();
      final updated = after.firstWhere((e) => e.id == events.first.id);
      if (updated.attendees < before) {
        throw 'attendees $before -> ${updated.attendees}, expected >= $before';
      }
      // The repeat join must honestly report it didn't rejoin/resend,
      // whichever of the two calls above happened to be the real first join.
      if (!first.alreadyJoined && !again.alreadyJoined) {
        throw 'expected the second joinEvent() call to report alreadyJoined=true';
      }
    });
  }
  await check('getMyActivity() after the actions above actually shows them',
      () async {
    final activity = await repo.getMyActivity();
    if (activity.isEmpty) throw 'expected at least one logged activity item';
  });

  String? newPostId;
  await check('createPost() + getFeed() round-trip', () async {
    await repo.createPost('Backend smoke test #verify');
    final feed = await repo.getFeed();
    final match = feed.where((p) => p.text.contains('Backend smoke test #verify'));
    if (match.isEmpty) throw 'created post not found in feed';
    newPostId = match.first.id;
  });
  if (newPostId != null) {
    await check('toggleLike($newPostId)', () async {
      await repo.toggleLike(newPostId!);
      final feed = await repo.getFeed();
      final post = feed.firstWhere((p) => p.id == newPostId);
      if (!post.likedByMe || post.likes != 1) {
        throw 'expected likedByMe=true, likes=1, got likedByMe=${post.likedByMe}, likes=${post.likes}';
      }
    });
    await check('addComment($newPostId)', () async {
      await repo.addComment(newPostId!, 'nice');
      final feed = await repo.getFeed();
      final post = feed.firstWhere((p) => p.id == newPostId);
      if (post.comments.isEmpty || post.comments.last.text != 'nice') {
        throw 'comment not persisted';
      }
    });
    await check('reportPost($newPostId)', () => repo.reportPost(newPostId!, 'test reason'));
  }

  await check('admin: upsertEvent() creates a real row', () async {
    await repo.upsertEvent(CampusEvent(
      id: 'event-verify-temp',
      title: 'Verify Temp Event',
      time: '10:00',
      placeName: 'Atelier',
      category: 'Test',
      attendees: 0,
      xp: 5,
    ));
    final all = await repo.getEvents(includeUnpublished: true);
    if (!all.any((e) => e.id == 'event-verify-temp')) throw 'upserted event not found';
  });
  await check('admin: deleteEvent() removes it again', () async {
    await repo.deleteEvent('event-verify-temp');
    final all = await repo.getEvents(includeUnpublished: true);
    if (all.any((e) => e.id == 'event-verify-temp')) throw 'event still present after delete';
  });
  await check('admin: getReports() sees the reports filed above', () async {
    final reports = await repo.getReports();
    if (reports.isEmpty) throw 'expected at least one moderation report';
  });

  // docs/EKSIKLER.md §26: the image-moderation API key lives only in the
  // backend's app_settings table now — never echoed back to the client,
  // just a real configured/not-configured round-trip.
  await check('admin: repo.getImageModerationConfigured()/setImageModerationApiKey() round-trip',
      () async {
    final before = await repo.getImageModerationConfigured();
    if (before) throw 'expected no moderation key configured on a fresh seed';
    final afterSet = await repo.setImageModerationApiKey('sk-verify-key');
    if (!afterSet) throw 'setImageModerationApiKey() did not report configured=true';
    if (!await repo.getImageModerationConfigured()) {
      throw 'getImageModerationConfigured() did not reflect the key just set';
    }
    final afterClear = await repo.setImageModerationApiKey('');
    if (afterClear) throw 'clearing the key did not report configured=false';
  });

  // A real image upload with no moderation key configured must be
  // accepted (skip policy) rather than blocked — checked without ever
  // configuring a real OpenAI key here, since a *flagged* image would be
  // a real strike against the persistent dev-database demo account.
  await check('repo.checkImageModeration() is a no-op when unconfigured', () async {
    await repo.checkImageModeration(Uint8List.fromList([0, 1, 2, 3]));
  });

  // --------------------------------------------------- Newer subsystems

  await check('repo.getClubs() returns real seed data', () async {
    final clubs = await repo.getClubs();
    if (clubs.isEmpty) throw 'no clubs returned';
  });
  await check('repo.getSports() returns real seed data', () async {
    final sports = await repo.getSports();
    if (sports.isEmpty) throw 'no sports returned';
  });
  await check('repo.getServices() returns real seed data', () async {
    final services = await repo.getServices();
    if (services.isEmpty) throw 'no services returned';
  });
  await check('GET /food-venues includes real daily menus', () async {
    final venues = await getJson('/food-venues') as List;
    if (venues.isEmpty) throw 'no food venues returned';
  });
  await check('repo.getDirectoryEntries()/upsert/delete round-trip', () async {
    await repo.upsertDirectoryEntry(const DirectoryEntry(
        id: 'dir-verify-temp', building: 'Verify Hall', occupantName: 'Verify Person'));
    var entries = await repo.getDirectoryEntries();
    if (!entries.any((e) => e.id == 'dir-verify-temp')) throw 'directory entry not created';
    await repo.deleteDirectoryEntry('dir-verify-temp');
    entries = await repo.getDirectoryEntries();
    if (entries.any((e) => e.id == 'dir-verify-temp')) throw 'entry still present after delete';
  });
  await check('repo.getPages()/upsert/delete round-trip', () async {
    await repo.upsertPage(AdminPage(
        id: 'page-verify-temp',
        title: 'Verify Page',
        slug: 'verify-page',
        updatedAt: DateTime.now(),
        updatedBy: 'verify-script'));
    var pages = await repo.getPages();
    if (!pages.any((p) => p.id == 'page-verify-temp')) throw 'page not created';
    await repo.deletePage('page-verify-temp');
    pages = await repo.getPages();
    if (pages.any((p) => p.id == 'page-verify-temp')) throw 'page still present after delete';
  });
  await check('repo.getAcademicYears() has an active year', () async {
    final years = await repo.getAcademicYears();
    if (!years.any((y) => y.isActive)) throw 'no active academic year';
  });
  await check('admin: repo.upsertAcademicYear() round-trip', () async {
    await repo.upsertAcademicYear(
        id: 'year-verify-temp',
        label: 'Verify Year',
        startsOn: DateTime(2099, 9, 1),
        endsOn: DateTime(2100, 6, 30));
    var years = await repo.getAcademicYears();
    if (!years.any((y) => y.id == 'year-verify-temp')) throw 'academic year not created';
    await repo.deleteAcademicYear('year-verify-temp');
    years = await repo.getAcademicYears();
    if (years.any((y) => y.id == 'year-verify-temp')) throw 'academic year still present after delete';
  });

  await check('admin: repo.upsertClub()/deleteClub() round-trip', () async {
    await repo.upsertClub(const CampusClub(
        id: 'club-verify-temp', name: 'Verify Club', category: 'Test', description: 'temp'));
    var clubs = await repo.getClubs();
    if (!clubs.any((c) => c.id == 'club-verify-temp')) throw 'club not created';
    await repo.deleteClub('club-verify-temp');
    clubs = await repo.getClubs();
    if (clubs.any((c) => c.id == 'club-verify-temp')) throw 'club still present after delete';
  });
  await check('admin: repo.upsertSport()/deleteSport() round-trip', () async {
    await repo.upsertSport(
        const CampusSport(id: 'sport-verify-temp', name: 'Verify Sport', facility: 'Test Hall'));
    var sports = await repo.getSports();
    if (!sports.any((s) => s.id == 'sport-verify-temp')) throw 'sport not created';
    await repo.deleteSport('sport-verify-temp');
    sports = await repo.getSports();
    if (sports.any((s) => s.id == 'sport-verify-temp')) throw 'sport still present after delete';
  });
  await check('admin: repo.upsertService()/deleteService() round-trip', () async {
    await repo.upsertService(const CampusService(
        id: 'service-verify-temp',
        title: 'Verify Service',
        category: 'Test',
        description: 'temp',
        contact: 'verify@arucad.edu.tr'));
    var services = await repo.getServices();
    if (!services.any((s) => s.id == 'service-verify-temp')) throw 'service not created';
    await repo.deleteService('service-verify-temp');
    services = await repo.getServices();
    if (services.any((s) => s.id == 'service-verify-temp')) throw 'service still present after delete';
  });

  await check('RBAC: repo.setRoleAssignment()/roleFor()/getRoleAssignments() round-trip', () async {
    await repo.setRoleAssignment('verify@arucad.edu.tr', UserRole.moderator, assignedBy: 'verify-script');
    final role = await repo.roleFor('verify@arucad.edu.tr');
    if (role != UserRole.moderator) throw 'unexpected role: $role';
    final all = await repo.getRoleAssignments();
    if (!all.any((a) => a.email == 'verify@arucad.edu.tr')) throw 'assignment missing from list';
  });

  await check('admin audit log recorded the role assignment above', () async {
    final rows = await repo.getAuditLog();
    if (!rows.any((r) => r.action == 'role_change')) throw 'no role_change entry found';
  });

  List<Survey> activeSurveys = const [];
  await check('repo.getActiveSurveys() returns the real seeded survey', () async {
    activeSurveys = await repo.getActiveSurveys();
    if (activeSurveys.isEmpty) throw 'no active surveys';
  });
  if (activeSurveys.isNotEmpty) {
    await check('repo.voteSurvey() actually increments a real option', () async {
      final survey = activeSurveys.first;
      final optionId = survey.options.first.id;
      final result = await repo.voteSurvey(survey.id, [optionId]);
      final votedOption = result.options.firstWhere((o) => o.id == optionId);
      if (votedOption.votes < 1) throw 'vote did not register';
      if (!result.myOptionIds.contains(optionId)) throw 'myOptionIds did not reflect the vote';
    });
  }
  await check('admin: repo.upsertSurvey()/getAllSurveys()/deleteSurvey() round-trip', () async {
    final created = await repo.upsertSurvey(
        id: 'survey-verify-temp', question: 'Verify?', options: ['Evet', 'Hayır']);
    if (created.options.length != 2) throw 'expected 2 options';
    var all = await repo.getAllSurveys();
    if (!all.any((s) => s.id == 'survey-verify-temp')) throw 'survey not in admin list';
    await repo.deleteSurvey('survey-verify-temp');
    all = await repo.getAllSurveys();
    if (all.any((s) => s.id == 'survey-verify-temp')) throw 'survey still present after delete';
  });

  await check('repo.toggleFollow()/getFollowing() round-trip', () async {
    final nowFollowing = await repo.toggleFollow('Verify Peer');
    if (!nowFollowing) throw 'follow did not register';
    final following = await repo.getFollowing();
    if (!following.contains('Verify Peer')) throw 'peer not in following list';
    final nowUnfollowed = await repo.toggleFollow('Verify Peer');
    if (nowUnfollowed) throw 'unfollow (toggle) did not register';
  });

  await check('repo.sendChatMessage()/getChatMessages() round-trip', () async {
    final sent = await repo.sendChatMessage('Verify Peer', 'merhaba');
    if (sent.text != 'merhaba' || !sent.fromMe) throw 'unexpected sent message shape';
    final messages = await repo.getChatMessages('Verify Peer');
    if (messages.isEmpty || messages.last.text != 'merhaba') throw 'message not persisted';
    final threads = await repo.getChatThreadPeers(const []);
    if (!threads.contains('Verify Peer')) throw 'Verify Peer missing from thread list';
  });

  await check('repo.getInboxNotifications() has real entries, markAllNotificationsRead() works', () async {
    final notifications = await repo.getInboxNotifications();
    if (notifications.isEmpty) throw 'expected at least one notification (from the follow above)';
    await repo.markAllNotificationsRead();
    final afterMark = await repo.getInboxNotifications();
    if (afterMark.any((n) => !n.read)) throw 'expected every notification to be read after markAllRead';
  });

  await check('repo.recordRevision()/getRevisions() round-trip', () async {
    await repo.recordRevision('verify:key', const [], 'verify-script');
    // recordRevision() is a real no-op on an empty snapshot server-side
    // (ContentRevisionController::record() guards against it) — so record
    // a real one-block snapshot instead to actually exercise the path.
    final block = ContentBlock(type: BlockType.heading, props: {'text': 'x', 'level': 2});
    await repo.recordRevision('verify:key', [block], 'verify-script');
    final revisions = await repo.getRevisions('verify:key');
    if (revisions.isEmpty) throw 'revision not recorded';
  });

  String? myActivityId;
  await check('student can propose an activity (pending_review, not live)', () async {
    final created = await repo.createOwnActivity(title: 'Verify Activity', placeId: places.first.id);
    if (created.workflowStatus != 'pending_review') throw 'expected pending_review status';
    myActivityId = created.id;
    final published = await repo.getEvents();
    if (published.any((e) => e.id == myActivityId)) {
      throw 'pending activity leaked into the published events list';
    }
    final mine = await repo.getMyActivities();
    if (!mine.any((e) => e.id == myActivityId)) throw 'not found in repo.getMyActivities()';
    final pending = await repo.getPendingActivities();
    if (!pending.any((e) => e.id == myActivityId)) throw 'not found in repo.getPendingActivities()';
  });

  // docs/EKSIKLER.md §4: a place can't be double-booked at the exact
  // same date+time slot, in either creation path.
  await check('repo.getPlaceAvailability()/PlaceConflictException on a double-booked slot',
      () async {
    final conflictDate = DateTime(2027, 5, 20);
    final first = await repo.createOwnActivity(
      title: 'Slot Holder',
      placeId: places.first.id,
      time: '19:00',
      eventDate: conflictDate,
    );
    final booked = await repo.getPlaceAvailability(places.first.id, conflictDate);
    if (!booked.any((b) => b.eventId == first.id)) {
      throw 'getPlaceAvailability() did not list the just-created booking';
    }
    var conflicted = false;
    try {
      await repo.createOwnActivity(
        title: 'Should Conflict',
        placeId: places.first.id,
        time: '19:00',
        eventDate: conflictDate,
      );
    } on PlaceConflictException {
      conflicted = true;
    }
    if (!conflicted) {
      throw 'double-booking the same place/date/time slot did not throw PlaceConflictException';
    }
  });
  if (myActivityId != null) {
    await check('admin approving a pending activity actually publishes it', () async {
      await repo.approveActivity(myActivityId!);
      final published = await repo.getEvents();
      if (!published.any((e) => e.id == myActivityId)) {
        throw 'approved activity did not appear in the public events list';
      }
    });
  }

  await check('admin: repo.upsertParticipationType()/deleteParticipationType() round-trip', () async {
    if (events.isEmpty) throw 'no events to attach a participation type to';
    final created = await repo.upsertParticipationType(events.first.id, label: 'Verify Type');
    var refreshed = await repo.getEvents(includeUnpublished: true);
    var match = refreshed.firstWhere((e) => e.id == events.first.id);
    if (!match.participationTypes.any((t) => t.id == created.id)) {
      throw 'participation type not attached to event';
    }
    await repo.deleteParticipationType(events.first.id, created.id);
    refreshed = await repo.getEvents(includeUnpublished: true);
    match = refreshed.firstWhere((e) => e.id == events.first.id);
    if (match.participationTypes.any((t) => t.id == created.id)) {
      throw 'participation type still present after delete';
    }
  });

  await check('admin: repo.getEventParticipants()/approveEventParticipant() round-trip', () async {
    if (events.isEmpty) throw 'no events to join for the attendance check';
    await repo.joinEvent(events.first.id);
    final roster = await repo.getEventParticipants(events.first.id);
    if (roster.isEmpty) throw 'expected at least one participant after joining';
    final mine = roster.first;
    if (mine.isApproved) throw 'expected a fresh join to be unapproved';
    if (mine.isFormSubmitted) throw 'expected a fresh join to have no form submitted yet';

    // The real gate (docs/EKSIKLER.md §5): approval before the student
    // completes the form must be rejected, not silently allowed.
    var rejectedBeforeForm = false;
    try {
      await repo.approveEventParticipant(events.first.id, mine.id);
    } catch (_) {
      rejectedBeforeForm = true;
    }
    if (!rejectedBeforeForm) {
      throw 'approveEventParticipant() succeeded before the form was submitted';
    }

    await repo.submitEventJoinForm(events.first.id);
    final afterForm = await repo.getEventParticipants(events.first.id);
    if (!afterForm.firstWhere((p) => p.id == mine.id).isFormSubmitted) {
      throw 'participant still shows no form submission after submitEventJoinForm()';
    }

    await repo.approveEventParticipant(events.first.id, mine.id);
    final after = await repo.getEventParticipants(events.first.id);
    if (!after.firstWhere((p) => p.id == mine.id).isApproved) {
      throw 'participant still unapproved after approveEventParticipant()';
    }
  });

  // Deliberately not exercising a real CONTENT_BLOCKED post — or
  // repo.reportImageModerationStrike() — here: each violation is a real
  // strike against the persistent dev-database demo account
  // (ModerationService), and this script gets re-run often — 3 runs
  // would actually ban the account this whole tool signs in as.
  // ModerationApiTest (PHPUnit, RefreshDatabase-isolated) is the real
  // test for both paths instead.

  await check('admin: repo.sendBulkEmail() sends and logs each recipient', () async {
    final sent = await repo.sendBulkEmail(
      recipients: const ['verify1@arucad.edu.tr', 'verify2@arucad.edu.tr'],
      subject: 'Verify bulk email',
      body: 'test',
    );
    if (sent != 2) throw 'expected 2 sends, got $sent';
    final logs = await repo.getEmailLogs();
    if (!logs.any((l) => l.subject == 'Verify bulk email')) throw 'bulk email not logged';
    final failed = logs.firstWhere((l) => l.status != 'sent', orElse: () => logs.first);
    await repo.retryEmail(failed.id);
  });

  raw.close();
  stdout.writeln('\n${me != null ? "Signed in as ${me!.name}. " : ""}'
      '${failures == 0 ? "All checks passed — the backend is real and reachable." : "$failures check(s) FAILED."}');
  if (failures > 0) exit(1);
}
