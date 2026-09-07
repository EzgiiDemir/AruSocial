import 'package:flutter/material.dart';
import 'package:collection/collection.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/staff_application.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/services/apply_bottom_sheet.dart';
import 'package:arucad_campus_prototype/features/widgets/block_renderer.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_back_button.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

/// A club's own page — real description, a real (if estimated, same
/// disclosed pattern as `campusOnlineCount`) member count, and any real
/// campus events that plausibly belong to it. There's no membership
/// roster or club-tagged-content backend, so "Members"/"Posts" lists
/// aren't built here — see the doc comment on `_relatedEvents` for
/// exactly how event matching works and its limits.
///
/// Real bug fix: joining used to flip a purely local `SharedPreferences`
/// flag — the backend's real membership pipeline
/// (`ClubMemberController::join`) was already there and already enforced
/// (it 403s with `APPLICATION_REQUIRED` unless an approved
/// `ParticipationApplication` exists), but nothing in this screen ever
/// called it. Now "Katıl" submits a real application through the same
/// pipeline `sport_application_screen.dart` uses. The button turns into
/// "Katıldın" as soon as that application exists (see
/// `ParticipationApplication.countsAsJoined` — applying reads as "joined"
/// immediately from the student's side of the screen); real membership
/// (`ClubMember`) is still only created once the department head actually
/// approves it, via [_finalizeJoin].
class ClubDetailScreen extends StatefulWidget {
  final CampusClub club;
  final List<CampusEvent> events;
  final CampusRepository repository;
  const ClubDetailScreen({
    super.key,
    required this.club,
    required this.repository,
    this.events = const [],
  });

  @override
  State<ClubDetailScreen> createState() => _ClubDetailScreenState();
}

class _ClubDetailScreenState extends State<ClubDetailScreen> {
  bool _isMember = false;
  ParticipationApplication? _application;
  bool _loading = true;
  bool _leaving = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final results = await Future.wait([
      widget.repository.getJoinedClubIds(),
      widget.repository.getMyApplications(),
    ]);
    if (!mounted) return;
    final joinedIds = results[0] as Set<String>;
    final applications = results[1] as List<ParticipationApplication>;
    final application = applications
        .where((a) => a.targetType == 'club' && a.targetId == widget.club.id)
        .firstOrNull;
    final realMember = joinedIds.contains(widget.club.id);
    setState(() {
      _isMember = realMember;
      _application = application;
      _loading = false;
    });
    // Membership is created server-side on approval. Don't auto-call
    // joinClub on every visit — that would immediately undo a real leave.
  }

  Future<void> _finalizeJoin() async {
    try {
      await widget.repository.joinClub(widget.club.id);
      if (!mounted) return;
      setState(() => _isMember = true);
    } on ApiClientException {
      // Not approved after all (race/stale data).
    }
  }

  Future<void> _leave() async {
    final s = AppLocale.of(context);
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(s.t('club_leave_title')),
        content: Text(s.t('club_leave_body').replaceAll('{name}', widget.club.name)),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(s.t('common_cancel'))),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(s.t('club_leave'))),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    setState(() => _leaving = true);
    try {
      await widget.repository.leaveClub(widget.club.id);
      if (!mounted) return;
      setState(() => _isMember = false);
    } on ApiClientException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _leaving = false);
    }
  }

  /// Real, two-stage apply flow (see docs/API_CONTRACT.md's Applications
  /// section): this only ever submits the short Preview form — never real
  /// membership by itself. A detailed, club-specific form is emailed next;
  /// only the club's responsible staff approving it creates real
  /// membership (see [_finalizeJoin]/[isApproved]) — the button itself
  /// flips to "Katıldın" as soon as the application exists, though.
  Future<void> _apply() async {
    final isCommunity = widget.club.category.toLowerCase() == 'community';
    final joined = await showApplyBottomSheet(
      context,
      repository: widget.repository,
      targetType: isCommunity ? 'community' : 'club',
      targetId: widget.club.id,
      targetLabel: widget.club.name,
    );
    if (mounted && joined) {
      if (_application?.isApproved == true) {
        await _finalizeJoin();
      }
      await _load();
    }
  }

  /// Real campus events whose title or category loosely mentions the
  /// club's name/category — a heuristic match, not a real club↔event
  /// relationship (that would need admin-linked content, like
  /// `DirectoryEntry.relatedServiceId` does for services). Shown only when
  /// it actually finds something, rather than a fake "no events" filler.
  List<CampusEvent> get _relatedEvents {
    final nameWords = widget.club.name
        .toLowerCase()
        .replaceAll('kulübü', '')
        .replaceAll('club', '')
        .split(RegExp(r'[\s&]+'))
        .where((w) => w.length > 3)
        .toList();
    return widget.events.where((e) {
      final title = e.title.toLowerCase();
      final category = e.category.toLowerCase();
      return nameWords.any((w) => title.contains(w) || category.contains(w));
    }).toList();
  }

  @override
  Widget build(BuildContext context) {
    final club = widget.club;
    final members = club.memberCount > 0
        ? club.memberCount
        : campusClubMemberEstimate(club.name);
    final related = _relatedEvents;
    final application = _application;
    final canOpenApply = !_isMember &&
        (application == null ||
            application.canResubmitPreview ||
            application.awaitingStudentDetail ||
            application.isOpen);
    final s = AppLocale.of(context);
    final applyLabel = application == null
        ? s.t('common_join')
        : application.awaitingStudentDetail
            ? s.t('club_open_email_form')
            : application.isOpen
                ? s.t('club_view_application')
                : application.canResubmitPreview
                    ? s.t('club_reapply')
                    : s.t('common_join');

    return Scaffold(
      appBar: AppBar(title: Text(club.name), leading: const CampusBackButton()),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
              children: [
                Row(children: [
                  CircleAvatar(
                    radius: 26,
                    backgroundColor: categoryAccent(club.category)
                        .withValues(alpha: .14),
                    child: Text(
                        club.name.isEmpty ? '?' : club.name.substring(0, 1),
                        style: TextStyle(
                            fontWeight: FontWeight.w900,
                            color: categoryAccent(club.category),
                            fontSize: 22)),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(club.category,
                          style: TextStyle(
                              color: Theme.of(context).colorScheme.onSurface,
                              fontWeight: FontWeight.w700,
                              fontSize: 12)),
                      const SizedBox(height: 2),
                      Text(s.t('club_members_count').replaceAll('{n}', '$members'),
                          style: TextStyle(
                              color: Theme.of(context).colorScheme.onSurfaceVariant,
                              fontSize: 12.5)),
                    ]),
                  ),
                ]),
                const SizedBox(height: 20),
                Text(s.t('common_about'),
                    style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 15)),
                const SizedBox(height: 8),
                Text(club.description, style: const TextStyle(fontSize: 15, height: 1.4)),
                if (club.body.isNotEmpty) ...[
                  const SizedBox(height: 8),
                  BlockRenderer(blocks: club.body),
                ],
                if (related.isNotEmpty) ...[
                  const SizedBox(height: 24),
                  Text(s.t('club_upcoming'),
                      style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 15)),
                  const SizedBox(height: 10),
                  for (final event in related)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 8),
                      child: Card(
                        child: ListTile(
                          leading: const Icon(Icons.event_outlined, color: ArucadColors.primary),
                          title: Text(event.title,
                              style: const TextStyle(fontWeight: FontWeight.w700)),
                          subtitle: Text('${event.time} · ${event.placeName}'),
                        ),
                      ),
                    ),
                ],
                const SizedBox(height: 28),
                if (!_isMember && application != null) ...[
                  Card(
                    color: ArucadColors.mist,
                    child: ListTile(
                      leading: const Icon(Icons.assignment_turned_in_outlined),
                      title: Text(s.t('club_application_status')),
                      subtitle: Text(
                          application.needsRevision && (application.reviewNote ?? '').isNotEmpty
                              ? '${application.statusLabel}: ${application.reviewNote}'
                              : application.statusLabel),
                      isThreeLine:
                          application.needsRevision && (application.reviewNote ?? '').isNotEmpty,
                    ),
                  ),
                  const SizedBox(height: 10),
                ],
                if (_isMember)
                  SizedBox(
                    width: double.infinity,
                    child: OutlinedButton.icon(
                      onPressed: _leaving ? null : _leave,
                      icon: const Icon(Icons.check, color: ArucadColors.success),
                      label: Text(_leaving
                          ? s.t('club_leaving')
                          : s.t('club_member_leave')),
                    ),
                  )
                else if (application?.isApproved == true)
                  SizedBox(
                    width: double.infinity,
                    child: FilledButton(
                      onPressed: _finalizeJoin,
                      child: const Text('Üyeliği tamamla'),
                    ),
                  )
                else
                  SizedBox(
                    width: double.infinity,
                    child: FilledButton(
                      onPressed: canOpenApply ? _apply : null,
                      child: Text(applyLabel),
                    ),
                  ),
              ],
            ),
    );
  }
}
