import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/core/models/moderation_case.dart';

/// Parsing pinned against the shape the API actually returns.
///
/// Captured from a live `GET /admin/moderation/cases/{id}` rather than
/// written from the controller, because the two drift: a field renamed
/// server-side turns into a silently empty moderator queue, and an empty
/// queue looks exactly like "nothing to review".
void main() {
  group('ModerationCase', () {
    test('parses a case summary from the live shape', () {
      final c = ModerationCase.fromJson(const {
        'id': '371400e9-79c3-4b2b-9dee-74e4c3986b41',
        'contentType': 'post',
        'contentId': 'post-b8137b21',
        'authorId': 1,
        'source': 'user_report',
        'priority': 60,
        'status': 'open',
        'decision': null,
        'recommendation': null,
        'reportCount': 1,
        'createdAt': '2026-09-10T18:31:35+00:00',
      });

      expect(c.contentType, 'post');
      expect(c.priority, 60);
      expect(c.reportCount, 1);
      expect(c.isOpen, isTrue);
      expect(c.createdAt, isNotNull);
      expect(c.decision, isNull);
    });

    /// Priority decides queue order, so a missing or unparseable value
    /// must sort last rather than jumping to the top of the queue.
    test('an absent priority sorts last instead of first', () {
      final c = ModerationCase.fromJson(const {'id': 'x', 'status': 'open'});

      expect(c.priority, 100);
    });
  });

  group('ModerationCaseDetail', () {
    test('parses reports, signals and history together', () {
      final d = ModerationCaseDetail.fromJson(const {
        'id': 'case-1',
        'contentType': 'post',
        'contentId': 'post-1',
        'source': 'user_report',
        'priority': 20,
        'status': 'open',
        'reportCount': 2,
        'preview': 'Bir gönderi metni',
        'reports': [
          {
            'id': 'report-1',
            'reasonCode': 'harassment',
            'description': 'Hedef alıyor',
            'reportedAt': '2026-09-10T18:31:35+00:00',
          },
        ],
        'signals': [
          {
            'action': 'rejected',
            'categories': ['nsfw'],
            'scores': {'nsfw': 0.9829, 'normal': 0.0171},
            'model': 'Falconsai/nsfw_image_detection',
            'modelVersion': '96cb0d0',
            'policyVersion': 'image-v2-calibrated',
            'createdAt': '2026-09-10T18:31:35+00:00',
          },
        ],
        'authorHistory': {'violations': 1, 'points': 3},
      });

      expect(d.preview, 'Bir gönderi metni');
      expect(d.reports.single.reasonCode, 'harassment');
      expect(d.signals.single.categories, ['nsfw']);
      expect(d.signals.single.scores['nsfw'], closeTo(0.9829, 0.0001));
      // The model and policy version are what make a score arguable
      // months later, so they must survive parsing.
      expect(d.signals.single.policyVersion, 'image-v2-calibrated');
      expect(d.authorHistory['violations'], 1);
    });

    /// An automatic case has no reports, and a text case has no scores.
    /// Neither is an error, and neither may throw.
    test('empty reports and signals parse to empty lists', () {
      final d = ModerationCaseDetail.fromJson(const {
        'id': 'case-2',
        'contentType': 'image',
        'contentId': 'media-1',
        'source': 'classifier',
        'priority': 50,
        'status': 'open',
        'reportCount': 0,
      });

      expect(d.reports, isEmpty);
      expect(d.signals, isEmpty);
      expect(d.authorHistory, isEmpty);
      expect(d.preview, isNull);
    });
  });

  group('ModerationAppealReview', () {
    test('parses the live appeal shape', () {
      final a = ModerationAppealReview.fromJson(const {
        'id': '39855492',
        'caseId': '371400e9',
        'userId': 1,
        'originalDecision': 'remove',
        'reason': 'Bu gönderi spam değildi.',
        'status': 'open',
        'submittedAt': '2026-09-10T18:40:00+00:00',
      });

      expect(a.originalDecision, 'remove');
      expect(a.reason, contains('spam'));
      expect(a.status, 'open');
      expect(a.submittedAt, isNotNull);
    });
  });
}
