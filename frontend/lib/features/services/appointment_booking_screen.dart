import 'dart:async';

import 'package:collection/collection.dart';
import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/staff_application.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/chat_realtime_service.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_back_button.dart';

/// Full appointment lifecycle: pick staff → date → slot → confirm → book,
/// plus list + cancel for existing bookings. Backend is authoritative for
/// availability / double-book / past-slot.
class AppointmentBookingScreen extends StatefulWidget {
  final CampusRepository repository;
  final String? initialStaffId;
  const AppointmentBookingScreen({
    super.key,
    required this.repository,
    this.initialStaffId,
  });

  @override
  State<AppointmentBookingScreen> createState() =>
      _AppointmentBookingScreenState();
}

class _AppointmentBookingScreenState extends State<AppointmentBookingScreen>
    with WidgetsBindingObserver {
  bool _loading = true;
  String? _error;
  List<StaffProfile> _staff = const [];
  List<AppointmentBooking> _mine = const [];
  String? _staffId;
  DateTime _day = DateTime.now();
  List<StaffSlot> _slots = const [];
  StaffSlot? _selected;
  bool _booking = false;
  final _subjectC = TextEditingController();
  final _notesC = TextEditingController();
  Timer? _poll;
  ChatRealtimeService? _realtime;
  StreamSubscription<void>? _rtSub;
  StreamSubscription<void>? _resyncSub;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _subjectC.addListener(_onFormChanged);
    _load();
    _poll = Timer.periodic(const Duration(seconds: 15), (_) => _refreshMine());
    unawaited(_bindRealtime());
  }

  void _onFormChanged() {
    if (mounted) setState(() {});
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _poll?.cancel();
    unawaited(_rtSub?.cancel() ?? Future.value());
    unawaited(_resyncSub?.cancel() ?? Future.value());
    unawaited(_realtime?.dispose() ?? Future.value());
    _subjectC.removeListener(_onFormChanged);
    _subjectC.dispose();
    _notesC.dispose();
    super.dispose();
  }

  Future<void> _bindRealtime() async {
    try {
      final me = await widget.repository.getMe();
      if (!mounted) return;
      final rt = ChatRealtimeService.forRepository(widget.repository);
      _realtime = rt;
      _rtSub = rt.appointmentChanged.listen((_) {
        if (mounted) unawaited(_refreshMine());
      });
      _resyncSub = rt.resynced.listen((_) {
        if (mounted) unawaited(_refreshMine());
      });
      await rt.start(userId: me.id, userName: me.name);
    } catch (_) {}
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) unawaited(_refreshMine());
  }

  Future<void> _refreshMine() async {
    try {
      final mine = await widget.repository.getMyAppointments();
      if (!mounted) return;
      setState(() => _mine = mine);
    } catch (_) {}
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final results = await Future.wait([
        widget.repository.getStaff(),
        widget.repository.getMyAppointments(),
      ]);
      if (!mounted) return;
      final staff = results[0] as List<StaffProfile>;
      setState(() {
        _staff = staff.where((s) => s.active).toList();
        _mine = results[1] as List<AppointmentBooking>;
        _staffId = widget.initialStaffId ??
            _staffId ??
            _staff.firstOrNull?.id;
        _loading = false;
      });
      await _loadSlots();
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.toString();
        _loading = false;
      });
    }
  }

  String get _dateKey =>
      '${_day.year.toString().padLeft(4, '0')}-'
      '${_day.month.toString().padLeft(2, '0')}-'
      '${_day.day.toString().padLeft(2, '0')}';

  Future<void> _loadSlots() async {
    final staffId = _staffId;
    if (staffId == null) return;
    var slots =
        await widget.repository.getStaffSlots(staffId, date: _dateKey);
    if (!mounted) return;
    if (slots.isEmpty) {
      slots = _officeHours(staffId, _dateKey);
    }
    final firstOpen = slots.where((s) => s.available).firstOrNull;
    setState(() {
      _slots = slots;
      _selected = firstOpen;
    });
  }

  List<StaffSlot> _officeHours(String staffId, String date) {
    final now = DateTime.now();
    final out = <StaffSlot>[];
    for (var hour = 9; hour < 17; hour++) {
      for (final minute in [0, 30]) {
        final start =
            '${hour.toString().padLeft(2, '0')}:${minute.toString().padLeft(2, '0')}';
        final endMinute = minute + 30;
        final endHour = endMinute >= 60 ? hour + 1 : hour;
        final end =
            '${endHour.toString().padLeft(2, '0')}:${(endMinute % 60).toString().padLeft(2, '0')}';
        DateTime? startAt;
        try {
          startAt = DateTime(
            int.parse(date.substring(0, 4)),
            int.parse(date.substring(5, 7)),
            int.parse(date.substring(8, 10)),
            hour,
            minute,
          );
        } catch (_) {}
        final past = startAt != null && startAt.isBefore(now);
        out.add(StaffSlot(
          id: 'slot-$staffId-$date-$start',
          staffProfileId: staffId,
          date: date,
          startTime: start,
          endTime: end,
          available: !past,
          status: past ? 'past' : 'available',
        ));
      }
    }
    return out;
  }

  String _hhmm(String raw) => raw.length >= 5 ? raw.substring(0, 5) : raw;

  String _slotOptionLabel(StaffSlot slot) =>
      '${_hhmm(slot.startTime)} – ${_hhmm(slot.endTime)}';

  void _snack(String message) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
  }

  AppStrings get _s => AppLocale.of(context);

  List<StaffSlot> get _openSlots =>
      _slots.where((s) => s.available).toList();

  Future<void> _submitBook() async {
    if (_booking) return;
    if (_staffId == null) {
      _snack(_s.t('appt_pick_staff'));
      return;
    }
    final subject = _subjectC.text.trim();
    if (subject.isEmpty) {
      _snack(_s.t('appt_need_subject'));
      return;
    }
    var slot = _selected;
    if (slot == null || !slot.available) {
      slot = _openSlots.firstOrNull;
    }
    if (slot == null) {
      _snack(_s.t('appt_need_slot'));
      return;
    }
    await _confirmBook(slot);
  }

  Future<void> _confirmBook(StaffSlot slot) async {
    final subject = _subjectC.text.trim();
    if (subject.isEmpty) {
      _snack(_s.t('appt_need_subject'));
      return;
    }
    final s = _s;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(s.t('appt_confirm_title')),
        content: Text(
            '${slot.date} · ${slot.startTime}-${slot.endTime}\n$subject'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: Text(s.t('appt_dismiss'))),
          FilledButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: Text(s.t('appt_create'))),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    setState(() => _booking = true);
    try {
      await widget.repository.bookAppointment(
        staffProfileId: slot.staffProfileId,
        date: slot.date,
        startTime: slot.startTime,
        endTime: slot.endTime,
        subject: subject,
        notes: _notesC.text.trim().isEmpty ? null : _notesC.text.trim(),
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(_s.t('appt_created'))),
      );
      await _load();
    } on ApiClientException catch (e) {
      if (!mounted) return;
      final code = e.code;
      final msg = switch (code) {
        'SLOT_UNAVAILABLE' => _s.t('appt_slot_taken'),
        'APPOINTMENT_ALREADY_EXISTS' => _s.t('appt_already'),
        'PAST_SLOT' => _s.t('appt_past'),
        'NOT_AUTHORIZED' => _s.t('appt_forbidden'),
        _ => e.message,
      };
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));
    } finally {
      if (mounted) setState(() => _booking = false);
    }
  }

  Future<void> _cancel(AppointmentBooking appt) async {
    final s = _s;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(s.t('appt_cancel_title')),
        content: Text('${appt.date} · ${appt.startTime}-${appt.endTime}'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: Text(s.t('appt_dismiss'))),
          FilledButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: Text(s.t('appt_cancel_action'))),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    try {
      await widget.repository.cancelAppointment(appt.id);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(_s.t('appt_cancelled'))),
      );
      await _load();
    } on ApiClientException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = _s;
    final open = _openSlots;
    final selectedId =
        open.any((slot) => slot.id == _selected?.id) ? _selected?.id : null;
    return Scaffold(
      appBar: AppBar(
        title: Text(s.t('appt_title')),
        leading: const CampusBackButton(),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(
                  child: Padding(
                    padding: const EdgeInsets.all(24),
                    child: Column(mainAxisSize: MainAxisSize.min, children: [
                      Text(_error!, textAlign: TextAlign.center),
                      const SizedBox(height: 12),
                      FilledButton(onPressed: _load, child: Text(s.t('appt_retry'))),
                    ]),
                  ),
                )
              : ListView(
                  padding: const EdgeInsets.fromLTRB(20, 20, 20, 12),
                  children: [
                    Text(s.t('appt_mine'),
                        style: const TextStyle(fontWeight: FontWeight.w900)),
                    const SizedBox(height: 8),
                    if (_mine.isEmpty)
                      Text(s.t('appt_mine_empty'),
                          style: const TextStyle(color: ArucadColors.muted))
                    else
                      Card(
                        child: Column(children: [
                          for (final a in _mine) ...[
                            ListTile(
                              title: Text(a.staffName ?? a.staffProfileId),
                              subtitle: Text([
                                '${a.date} · ${a.startTime}-${a.endTime}',
                                _statusLabel(a.status, s),
                                if (a.subject != null && a.subject!.isNotEmpty)
                                  a.subject!,
                              ].join(' · ')),
                              trailing: a.canCancel
                                  ? TextButton(
                                      onPressed: () => _cancel(a),
                                      child: Text(s.t('appt_cancel')),
                                    )
                                  : null,
                            ),
                            if (a != _mine.last) const Divider(height: 1),
                          ]
                        ]),
                      ),
                    const SizedBox(height: 20),
                    Text(s.t('appt_new'),
                        style: const TextStyle(fontWeight: FontWeight.w900)),
                    const SizedBox(height: 8),
                    if (_staff.isEmpty)
                      Text(
                        s.t('appt_no_staff'),
                        style: const TextStyle(color: ArucadColors.muted),
                      )
                    else
                      DropdownButtonFormField<String>(
                        key: ValueKey(_staffId),
                        isExpanded: true,
                        initialValue: _staff.any((st) => st.id == _staffId)
                            ? _staffId
                            : null,
                        items: _staff
                            .map((st) => DropdownMenuItem(
                                  value: st.id,
                                  child: Text(
                                    st.name,
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                  ),
                                ))
                            .toList(),
                        onChanged: (v) async {
                          setState(() {
                            _staffId = v;
                            _selected = null;
                          });
                          await _loadSlots();
                        },
                        decoration: InputDecoration(
                            labelText: s.t('appt_staff'),
                            border: const OutlineInputBorder()),
                      ),
                    const SizedBox(height: 10),
                    OutlinedButton.icon(
                      onPressed: () async {
                        final picked = await showDatePicker(
                          context: context,
                          initialDate: _day,
                          firstDate: DateTime.now(),
                          lastDate: DateTime.now().add(const Duration(days: 60)),
                        );
                        if (picked == null) return;
                        setState(() => _day = picked);
                        await _loadSlots();
                      },
                      icon: const Icon(Icons.calendar_month_outlined),
                      label: Text(_dateKey),
                    ),
                    const SizedBox(height: 10),
                    if (open.isEmpty)
                      Padding(
                        padding: const EdgeInsets.only(top: 4),
                        child: Text(
                            _slots.isEmpty
                                ? s.t('appt_no_slots')
                                : s.t('appt_no_open'),
                            style: const TextStyle(color: ArucadColors.muted)),
                      )
                    else
                      DropdownButtonFormField<String>(
                        key: ValueKey(
                            'slot-$_staffId-$_dateKey-$selectedId-${open.length}'),
                        isExpanded: true,
                        initialValue: selectedId,
                        items: open
                            .map((slot) => DropdownMenuItem(
                                  value: slot.id,
                                  child: Text(
                                    _slotOptionLabel(slot),
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                  ),
                                ))
                            .toList(),
                        onChanged: _booking
                            ? null
                            : (id) {
                                setState(() => _selected = open
                                    .firstWhereOrNull((slot) => slot.id == id));
                              },
                        decoration: InputDecoration(
                            labelText: s.t('appt_time'),
                            border: const OutlineInputBorder()),
                      ),
                    const SizedBox(height: 16),
                    TextField(
                      controller: _subjectC,
                      decoration: InputDecoration(
                        labelText: s.t('appt_subject'),
                        hintText: s.t('appt_subject_hint'),
                        border: const OutlineInputBorder(),
                      ),
                    ),
                    const SizedBox(height: 10),
                    TextField(
                      controller: _notesC,
                      maxLines: 3,
                      decoration: InputDecoration(
                        labelText: s.t('appt_notes'),
                        border: const OutlineInputBorder(),
                      ),
                    ),
                  ],
                ),
      bottomNavigationBar: (_loading || _error != null)
          ? null
          : SafeArea(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(20, 8, 20, 16),
                child: FilledButton(
                  onPressed: _booking ? null : _submitBook,
                  child: Text(_booking ? s.t('appt_creating') : s.t('appt_create')),
                ),
              ),
            ),
    );
  }

  String _statusLabel(String status, AppStrings s) => switch (status) {
        'pending' => s.t('appt_status_pending'),
        'approved' || 'booked' => s.t('appt_status_approved'),
        'rejected' => s.t('appt_status_rejected'),
        'cancelled' => s.t('appt_status_cancelled'),
        'completed' => s.t('appt_status_completed'),
        _ => status,
      };
}
