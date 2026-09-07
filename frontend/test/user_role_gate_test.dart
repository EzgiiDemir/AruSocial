import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/core/models/campus_models.dart';

void main() {
  group('UserRole.parse fails closed', () {
    test('null empty and garbage become student', () {
      expect(UserRole.parse(null), UserRole.student);
      expect(UserRole.parse(''), UserRole.student);
      expect(UserRole.parse('admin'), UserRole.student);
      expect(UserRole.parse('Student'), UserRole.student);
      expect(UserRole.parse('superadmin'), UserRole.student);
      expect(UserRole.tryParse('nope'), isNull);
      expect(UserRole.tryParse('superAdmin'), UserRole.superAdmin);
    });
  });

  group('role matrix (mirrors GranularPermissions ROLE_BUCKETS)', () {
    test('student cannot open admin or trainer panels', () {
      const role = UserRole.student;
      expect(role.canManageContent, isFalse);
      expect(role.canModerate, isFalse);
      expect(role.canManageSiteSettings, isFalse);
      expect(role.canManageOwnDepartment, isFalse);
      expect(role.canOpenAdminPanel, isFalse);
      expect(role.canOpenTrainerPanel, isFalse);
    });

    test('trainer opens trainer panel only', () {
      const role = UserRole.trainer;
      expect(role.canOpenTrainerPanel, isTrue);
      expect(role.canManageOwnDepartment, isTrue);
      expect(role.canOpenAdminPanel, isFalse);
      expect(role.canManageContent, isFalse);
      expect(role.canManageSiteSettings, isFalse);
    });

    test('superAdmin opens both panels and every bucket', () {
      const role = UserRole.superAdmin;
      expect(role.canOpenAdminPanel, isTrue);
      expect(role.canOpenTrainerPanel, isTrue);
      expect(role.canManageContent, isTrue);
      expect(role.canModerate, isTrue);
      expect(role.canManageSiteSettings, isTrue);
    });

    test('clubManager studentAffairs careerStaff share content-editor admin access', () {
      for (final role in [
        UserRole.clubManager,
        UserRole.studentAffairs,
        UserRole.careerStaff,
      ]) {
        expect(role.canManageContent, isTrue, reason: role.name);
        expect(role.canOpenAdminPanel, isTrue, reason: role.name);
        expect(role.canOpenTrainerPanel, isFalse, reason: role.name);
        expect(role.canManageSiteSettings, isFalse, reason: role.name);
      }
    });

    test('contentEditor opens admin but not trainer or site secrets', () {
      const role = UserRole.contentEditor;
      expect(role.canManageContent, isTrue);
      expect(role.canOpenAdminPanel, isTrue);
      expect(role.canOpenTrainerPanel, isFalse);
      expect(role.canManageSiteSettings, isFalse);
      expect(role.canModerate, isFalse);
    });

    test('moderator opens admin moderation, not content CRUD or trainer', () {
      const role = UserRole.moderator;
      expect(role.canModerate, isTrue);
      expect(role.canOpenAdminPanel, isTrue);
      expect(role.canManageContent, isFalse);
      expect(role.canOpenTrainerPanel, isFalse);
      expect(role.canManageSiteSettings, isFalse);
    });

    test('CampusUser.parsedRole never upgrades unknown strings', () {
      const user = CampusUser(
        id: '1',
        name: 'X',
        role: 'godMode',
        level: 1,
        xp: 0,
        places: 0,
        events: 0,
        memories: 0,
        interests: [],
      );
      expect(user.parsedRole, UserRole.student);
      expect(user.canManagePlaces, isFalse);
    });
  });
}
