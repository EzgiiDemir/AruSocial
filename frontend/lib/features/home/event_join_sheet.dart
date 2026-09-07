import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/features/services/apply_bottom_sheet.dart';

/// Event "Katıl" uses the same two-stage application pipeline as clubs and
/// sports — preview in-app, detail by email, unit approve. Returns a join
/// result only when the application is already approved.
Future<EventJoinResult?> showEventJoinSheet(
  BuildContext context,
  CampusRepository repository,
  CampusEvent event,
) async {
  final joined = await showApplyBottomSheet(
    context,
    repository: repository,
    targetType: 'event',
    targetId: event.id,
    targetLabel: event.title,
    extraChoices: [
      for (final type in event.participationTypes)
        ApplyChoice(id: type.id, label: type.label),
    ],
  );
  if (!joined) return null;
  return const EventJoinResult(
    alreadyJoined: true,
    clubEmailSent: false,
    formEmailSent: false,
    emailSupported: true,
    formSubmitted: true,
  );
}
