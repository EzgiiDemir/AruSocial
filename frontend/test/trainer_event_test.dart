import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/mock_campus_repository.dart';
import 'package:arucad_campus_prototype/core/services/rest_campus_repository.dart';

Map<String, dynamic> _envelope(dynamic data) => {
      'data': data,
      'meta': {'request_id': 'req-test'},
      'error': null,
    };

Map<String, dynamic> _eventJson({String id = 'event-1', String title = 'Sergi'}) => {
      'id': id,
      'title': title,
      'time': '14:00',
      'placeName': 'Garden',
      'category': 'Art',
      'attendees': 0,
      'xp': 20,
      'workflowStatus': 'published',
      'responsibleStaffId': 'staff-arch-head',
    };

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  group('RestCampusRepository trainer events', () {
    test('getTrainerEvents parses /trainer/events', () async {
      final mock = MockClient((request) async {
        expect(request.url.path, '/api/v1/trainer/events');
        return http.Response(jsonEncode(_envelope([_eventJson()])), 200);
      });
      final repo = RestCampusRepository(
        client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
      );

      final events = await repo.getTrainerEvents();
      expect(events.single.title, 'Sergi');
      expect(events.single.workflowStatus, 'published');
    });

    test('upsertTrainerEvent(isNew: true) omits id from the request body', () async {
      http.Request? seen;
      final mock = MockClient((request) async {
        seen = request;
        return http.Response(jsonEncode(_envelope(_eventJson())), 201);
      });
      final repo = RestCampusRepository(
        client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
      );

      await repo.upsertTrainerEvent(
        const CampusEvent(
          id: '',
          title: 'Sergi',
          time: '14:00',
          placeName: 'Garden',
          placeId: 'p1',
          category: 'Art',
          attendees: 0,
          xp: 20,
        ),
        isNew: true,
      );

      expect(seen!.method, 'POST');
      expect(seen!.url.path, '/api/v1/trainer/events');
      final body = jsonDecode(seen!.body) as Map<String, dynamic>;
      expect(body.containsKey('id'), isFalse);
      expect(body['title'], 'Sergi');
    });

    test('upsertTrainerEvent(isNew: false) includes the existing id', () async {
      http.Request? seen;
      final mock = MockClient((request) async {
        seen = request;
        return http.Response(jsonEncode(_envelope(_eventJson())), 200);
      });
      final repo = RestCampusRepository(
        client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
      );

      await repo.upsertTrainerEvent(
        const CampusEvent(
          id: 'event-1',
          title: 'Sergi v2',
          time: '14:00',
          placeName: 'Garden',
          placeId: 'p1',
          category: 'Art',
          attendees: 0,
          xp: 20,
        ),
        isNew: false,
      );

      final body = jsonDecode(seen!.body) as Map<String, dynamic>;
      expect(body['id'], 'event-1');
    });

    test('a place conflict surfaces as PlaceConflictException', () async {
      final mock = MockClient((request) async => http.Response(
            jsonEncode({
              'data': null,
              'meta': {'request_id': 'req-test'},
              'error': {'code': 'PLACE_UNAVAILABLE', 'message': 'dolu'},
            }),
            409,
          ));
      final repo = RestCampusRepository(
        client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
      );

      expect(
        () => repo.upsertTrainerEvent(
          const CampusEvent(
            id: '',
            title: 'X',
            time: '14:00',
            placeName: 'Garden',
            placeId: 'p1',
            category: 'Art',
            attendees: 0,
            xp: 20,
          ),
          isNew: true,
        ),
        throwsA(isA<PlaceConflictException>()),
      );
    });

    test('deleteTrainerEvent posts to /trainer/events/{id}/delete', () async {
      http.Request? seen;
      final mock = MockClient((request) async {
        seen = request;
        return http.Response(jsonEncode(_envelope({'deleted': true})), 200);
      });
      final repo = RestCampusRepository(
        client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
      );

      await repo.deleteTrainerEvent('event-1');

      expect(seen!.url.path, '/api/v1/trainer/events/event-1/delete');
    });
  });

  group('MockCampusRepository trainer events', () {
    test('a newly created event is returned by getTrainerEvents', () async {
      final repo = MockCampusRepository();
      final before = await repo.getTrainerEvents();

      await repo.upsertTrainerEvent(
        const CampusEvent(
          id: '',
          title: 'Bölüm Sergisi',
          time: '14:00',
          placeName: 'Carpentry Studio',
          placeId: 'carpentry-studio',
          category: 'Art',
          attendees: 0,
          xp: 20,
        ),
        isNew: true,
      );

      final after = await repo.getTrainerEvents();
      expect(after.length, before.length + 1);
      expect(after.any((e) => e.title == 'Bölüm Sergisi' && e.workflowStatus == 'published'), isTrue);
    });

    test('deleteTrainerEvent removes it from getTrainerEvents', () async {
      final repo = MockCampusRepository();
      final created = await repo.upsertTrainerEvent(
        const CampusEvent(
          id: '',
          title: 'Silinecek',
          time: '14:00',
          placeName: 'Carpentry Studio',
          placeId: 'carpentry-studio',
          category: 'Art',
          attendees: 0,
          xp: 20,
        ),
        isNew: true,
      );

      await repo.deleteTrainerEvent(created.id);

      final after = await repo.getTrainerEvents();
      expect(after.any((e) => e.id == created.id), isFalse);
    });
  });

  group('RestCampusRepository trainer applications/roster', () {
    Map<String, dynamic> appJson({String id = 'app-1', String status = 'submitted'}) => {
          'id': id,
          'userId': '1',
          'studentName': 'Ege',
          'targetType': 'club',
          'targetId': 'club-1',
          'status': status,
          'responsibleStaffId': 'staff-arch-head',
          'formPayload': {},
        };

    test('getTrainerApplications parses /trainer/applications', () async {
      final mock = MockClient((request) async {
        expect(request.url.path, '/api/v1/trainer/applications');
        return http.Response(jsonEncode(_envelope([appJson()])), 200);
      });
      final repo = RestCampusRepository(
        client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
      );

      final apps = await repo.getTrainerApplications();
      expect(apps.single.status, 'submitted');
    });

    test('approveTrainerApplication posts to /trainer/applications/{id}/approve', () async {
      http.Request? seen;
      final mock = MockClient((request) async {
        seen = request;
        return http.Response(jsonEncode(_envelope(appJson(status: 'approved'))), 200);
      });
      final repo = RestCampusRepository(
        client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
      );

      final result = await repo.approveTrainerApplication('app-1');
      expect(seen!.url.path, '/api/v1/trainer/applications/app-1/approve');
      expect(result.status, 'approved');
    });

    test('rejectTrainerApplication sends reviewNote', () async {
      http.Request? seen;
      final mock = MockClient((request) async {
        seen = request;
        return http.Response(jsonEncode(_envelope(appJson(status: 'rejected'))), 200);
      });
      final repo = RestCampusRepository(
        client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
      );

      await repo.rejectTrainerApplication('app-1', reviewNote: 'Eksik bilgi');
      final body = jsonDecode(seen!.body) as Map<String, dynamic>;
      expect(body['reviewNote'], 'Eksik bilgi');
    });

    test('requestTrainerApplicationRevision posts to /trainer/applications/{id}/revise', () async {
      http.Request? seen;
      final mock = MockClient((request) async {
        seen = request;
        return http.Response(jsonEncode(_envelope(appJson(status: 'revision_required'))), 200);
      });
      final repo = RestCampusRepository(
        client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
      );

      final result = await repo.requestTrainerApplicationRevision('app-1', reviewNote: 'Tekrar dene');
      expect(seen!.url.path, '/api/v1/trainer/applications/app-1/revise');
      expect(result.status, 'revision_required');
    });

    test('getTrainerRoster parses /trainer/roster', () async {
      final mock = MockClient((request) async {
        expect(request.url.path, '/api/v1/trainer/roster');
        return http.Response(
            jsonEncode(_envelope([
              {
                'id': 'staff-arch-head',
                'name': 'Architecture Head',
                'department': 'Architecture',
                'isDepartmentHead': true,
                'active': true,
              }
            ])),
            200);
      });
      final repo = RestCampusRepository(
        client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
      );

      final roster = await repo.getTrainerRoster();
      expect(roster.single.name, 'Architecture Head');
    });
  });

  group('MockCampusRepository trainer applications/roster', () {
    test('only own-department applications are returned and can be approved', () async {
      final repo = MockCampusRepository();
      final own = await repo.submitApplication(
        targetType: 'club', targetId: 'club-1', responsibleStaffId: 'staff-arch-head');
      await repo.submitApplication(
        targetType: 'club', targetId: 'club-2', responsibleStaffId: 'staff-clubs');

      final mine = await repo.getTrainerApplications();
      expect(mine.any((a) => a.id == own.id), isTrue);
      expect(mine.every((a) => a.responsibleStaffId == 'staff-arch-head'), isTrue);

      final approved = await repo.approveTrainerApplication(own.id);
      expect(approved.status, 'approved');
    });

    test('reject and revise set the expected status and note', () async {
      final repo = MockCampusRepository();
      final app = await repo.submitApplication(
        targetType: 'club', targetId: 'club-1', responsibleStaffId: 'staff-arch-head');

      final revised = await repo.requestTrainerApplicationRevision(app.id, reviewNote: 'Detay ekle');
      expect(revised.status, 'revision_required');
      expect(revised.reviewNote, 'Detay ekle');
    });

    test('getTrainerRoster returns only the trainer\'s own department', () async {
      final repo = MockCampusRepository();
      final roster = await repo.getTrainerRoster();
      expect(roster.every((s) => s.department == 'Architecture'), isTrue);
      expect(roster.any((s) => s.id == 'staff-arch-head'), isTrue);
    });
  });

  group('RestCampusRepository trainer event participants', () {
    Map<String, dynamic> participantJson({String status = 'pending'}) => {
          'id': 'join-1',
          'userId': '1',
          'studentName': 'Ege',
          'participationTypeLabel': null,
          'joinedAt': DateTime.now().toIso8601String(),
          'formSubmittedAt': status != 'pending' ? DateTime.now().toIso8601String() : null,
          'approvedAt': status == 'approved' ? DateTime.now().toIso8601String() : null,
          'approvedBy': status == 'approved' ? 'Architecture Head' : null,
        };

    test('getTrainerEventParticipants parses /trainer/events/{id}/participants', () async {
      final mock = MockClient((request) async {
        expect(request.url.path, '/api/v1/trainer/events/event-1/participants');
        return http.Response(jsonEncode(_envelope([participantJson()])), 200);
      });
      final repo = RestCampusRepository(
        client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
      );

      final participants = await repo.getTrainerEventParticipants('event-1');
      expect(participants.single.studentName, 'Ege');
    });

    test('approveTrainerEventParticipant posts to the approve route', () async {
      http.Request? seen;
      final mock = MockClient((request) async {
        seen = request;
        return http.Response(jsonEncode(_envelope({'approved': true})), 200);
      });
      final repo = RestCampusRepository(
        client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
      );

      await repo.approveTrainerEventParticipant('event-1', 'join-1');
      expect(seen!.url.path, '/api/v1/trainer/events/event-1/participants/join-1/approve');
    });
  });

  group('MockCampusRepository trainer event participants', () {
    test('a joined + form-submitted participant can be approved', () async {
      final repo = MockCampusRepository();
      await repo.upsertTrainerEvent(
        const CampusEvent(
          id: 'te-1',
          title: 'Bölüm Etkinliği',
          time: '14:00',
          placeName: 'Carpentry Studio',
          placeId: 'carpentry-studio',
          category: 'Art',
          attendees: 0,
          xp: 20,
        ),
        isNew: true,
      );
      await repo.joinEvent('te-1');
      await repo.submitEventJoinForm('te-1');

      final before = await repo.getTrainerEventParticipants('te-1');
      expect(before.single.isApproved, isFalse);

      await repo.approveTrainerEventParticipant('te-1', before.single.id);
      final after = await repo.getTrainerEventParticipants('te-1');
      expect(after.single.isApproved, isTrue);
    });
  });
}
