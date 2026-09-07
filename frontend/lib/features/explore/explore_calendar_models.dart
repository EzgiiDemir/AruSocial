import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/staff_application.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:flutter/material.dart';

enum ExploreCalendarKind { appointment, application, event }

enum ExploreCalendarFilter { all, appointment, application, event }

class ExploreCalendarItem {
  final String id;
  final ExploreCalendarKind kind;
  final String title;
  final String subtitle;
  final DateTime at;
  final AppointmentBooking? appointment;
  final ParticipationApplication? application;
  final CampusEvent? event;

  const ExploreCalendarItem({
    required this.id,
    required this.kind,
    required this.title,
    required this.subtitle,
    required this.at,
    this.appointment,
    this.application,
    this.event,
  });

  DateTime get day => DateTime(at.year, at.month, at.day);

  /// Randevu = mavi, Başvuru = sarı, Etkinlik = yeşil.
  Color get color => switch (kind) {
        ExploreCalendarKind.appointment => ArucadColors.blue,
        ExploreCalendarKind.application => ArucadColors.yellow,
        ExploreCalendarKind.event => ArucadColors.campusGreen,
      };

  IconData get icon => switch (kind) {
        ExploreCalendarKind.appointment => Icons.event_available_rounded,
        ExploreCalendarKind.application => Icons.assignment_outlined,
        ExploreCalendarKind.event => Icons.celebration_outlined,
      };

  /// Tek harflik ay hücresi ipucu.
  String get dayHint => switch (kind) {
        ExploreCalendarKind.appointment => 'R',
        ExploreCalendarKind.application => 'B',
        ExploreCalendarKind.event => 'E',
      };

  Color get onColor =>
      kind == ExploreCalendarKind.application ? ArucadColors.ink : Colors.white;

  static Color filterColor(ExploreCalendarFilter filter) => switch (filter) {
        ExploreCalendarFilter.all => ArucadColors.red,
        ExploreCalendarFilter.appointment => ArucadColors.blue,
        ExploreCalendarFilter.application => ArucadColors.yellow,
        ExploreCalendarFilter.event => ArucadColors.campusGreen,
      };

  static Color filterOnColor(ExploreCalendarFilter filter) =>
      filter == ExploreCalendarFilter.application
          ? ArucadColors.ink
          : Colors.white;

  bool matches(ExploreCalendarFilter filter) {
    if (filter == ExploreCalendarFilter.all) return true;
    return switch (filter) {
      ExploreCalendarFilter.appointment =>
        kind == ExploreCalendarKind.appointment,
      ExploreCalendarFilter.application =>
        kind == ExploreCalendarKind.application,
      ExploreCalendarFilter.event => kind == ExploreCalendarKind.event,
      ExploreCalendarFilter.all => true,
    };
  }
}

/// Builds personal calendar rows from existing repository endpoints.
Future<List<ExploreCalendarItem>> loadExploreCalendarItems(
  CampusRepository repository,
) async {
  Future<T> one<T>(Future<T> future, T fallback) async {
    try {
      return await future;
    } catch (_) {
      return fallback;
    }
  }

  final appointments =
      await one(repository.getMyAppointments(), const <AppointmentBooking>[]);
  final applications = await one(
      repository.getMyApplications(), const <ParticipationApplication>[]);
  final events = await one(repository.getEvents(), const <CampusEvent>[]);
  final activity =
      await one(repository.getMyActivity(), const <ActivityItem>[]);

  final items = <ExploreCalendarItem>[];

  for (final a in appointments) {
    if (a.status == 'cancelled' || a.status == 'rejected') continue;
    final day = DateTime.tryParse(a.date);
    if (day == null) continue;
    final parts = a.startTime.split(':');
    final hour = parts.isNotEmpty ? int.tryParse(parts[0]) ?? 0 : 0;
    final minute = parts.length > 1 ? int.tryParse(parts[1]) ?? 0 : 0;
    final at = DateTime(day.year, day.month, day.day, hour, minute);
    items.add(ExploreCalendarItem(
      id: 'appt-${a.id}',
      kind: ExploreCalendarKind.appointment,
      title: a.subject?.trim().isNotEmpty == true
          ? a.subject!.trim()
          : (a.staffName ?? 'Randevu'),
      subtitle:
          '${a.startTime}${a.endTime.isNotEmpty ? '–${a.endTime}' : ''}'
          '${a.staffName != null ? ' · ${a.staffName}' : ''}',
      at: at,
      appointment: a,
    ));
  }

  for (final app in applications) {
    if (app.status == ParticipationApplication.statusCancelled ||
        app.status == ParticipationApplication.statusRejected) {
      continue;
    }
    final at = app.detailFormSubmittedAt ?? app.submittedAt;
    if (at == null) continue;
    items.add(ExploreCalendarItem(
      id: 'app-${app.id}',
      kind: ExploreCalendarKind.application,
      title: app.targetLabel?.trim().isNotEmpty == true
          ? app.targetLabel!.trim()
          : 'Başvuru · ${app.targetType}',
      subtitle: app.statusLabel,
      at: at,
      application: app,
    ));
  }

  final joinActivities =
      activity.where((a) => a.kind == ActivityKind.eventJoin).toList();
  final joinedTitles = joinActivities
      .map((a) => a.title.trim().toLowerCase())
      .where((t) => t.isNotEmpty)
      .toSet();
  final hasJoins = joinActivities.isNotEmpty;

  final now = DateTime.now();
  final startOfToday = DateTime(now.year, now.month, now.day);

  for (final event in events) {
    final date = event.eventDate;
    if (date == null) continue;
    if (date.isBefore(startOfToday.subtract(const Duration(days: 1)))) {
      continue;
    }

    final titleKey = event.title.trim().toLowerCase();
    final isJoined = hasJoins &&
        (joinedTitles.contains(titleKey) ||
            joinedTitles.any(
                (t) => titleKey.contains(t) || t.contains(titleKey)));

    // Personal calendar: joined events only. If the student has never
    // joined an event, show upcoming dated campus events so first-run /
    // demos still have calendar content for the Events filter.
    if (hasJoins && !isJoined) continue;

    final timeParts = event.time.split(RegExp(r'[:\s]'));
    final hour = timeParts.isNotEmpty ? int.tryParse(timeParts[0]) ?? 12 : 12;
    final minute = timeParts.length > 1 ? int.tryParse(timeParts[1]) ?? 0 : 0;
    final at = DateTime(date.year, date.month, date.day, hour.clamp(0, 23),
        minute.clamp(0, 59));
    items.add(ExploreCalendarItem(
      id: 'evt-${event.id}',
      kind: ExploreCalendarKind.event,
      title: event.title,
      subtitle: '${event.placeName} · ${event.time}',
      at: at,
      event: event,
    ));
  }

  items.sort((a, b) => a.at.compareTo(b.at));
  return items;
}

List<ExploreCalendarItem> upcomingExploreItems(
  List<ExploreCalendarItem> items, {
  int withinDays = 7,
  int limit = 3,
  ExploreCalendarFilter filter = ExploreCalendarFilter.all,
}) {
  final now = DateTime.now();
  final start = DateTime(now.year, now.month, now.day);
  final end = start.add(Duration(days: withinDays + 1));
  return items
      .where((i) => i.matches(filter))
      .where((i) => !i.at.isBefore(start) && i.at.isBefore(end))
      .take(limit)
      .toList();
}
