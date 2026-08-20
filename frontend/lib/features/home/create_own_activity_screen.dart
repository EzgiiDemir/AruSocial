import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

/// Real "Kendi Aktiviteni Oluştur" submission form — a student proposes an
/// activity at one of the app's real, admin-defined places; it's saved as
/// `pending_review` and stays invisible to everyone else until an admin
/// approves it (`Admin\EventController::approveActivity`, surfaced in the
/// Admin Panel's "Bekleyen Aktiviteler" tab). No draft→publish faking:
/// nothing here goes live without a real admin action.
class CreateOwnActivityScreen extends StatefulWidget {
  final CampusRepository repository;
  const CreateOwnActivityScreen({super.key, required this.repository});

  @override
  State<CreateOwnActivityScreen> createState() => _CreateOwnActivityScreenState();
}

class _CreateOwnActivityScreenState extends State<CreateOwnActivityScreen> {
  late Future<List<CampusPlace>> _placesFuture;
  final _titleC = TextEditingController();
  final _timeC = TextEditingController();
  final _categoryC = TextEditingController(text: 'Öğrenci Etkinliği');
  final _descriptionC = TextEditingController();
  String? _placeId;
  DateTime? _eventDate;
  List<PlaceBooking> _booked = const [];
  bool _loadingAvailability = false;
  bool _submitting = false;
  String? _error;

  // Real "boş/dolu" mekân müsaitliği (docs/EKSIKLER.md §4): whenever the
  // place or date changes, ask the backend what's already booked there
  // that day so the student sees a real conflict before submitting, not
  // just after a rejected request.
  Future<void> _refreshAvailability() async {
    if (_placeId == null || _eventDate == null) {
      setState(() => _booked = const []);
      return;
    }
    setState(() => _loadingAvailability = true);
    try {
      final booked = await widget.repository.getPlaceAvailability(_placeId!, _eventDate!);
      if (!mounted) return;
      setState(() {
        _booked = booked;
        _loadingAvailability = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() => _loadingAvailability = false);
    }
  }

  Future<void> _pickDate() async {
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: _eventDate ?? now,
      firstDate: now,
      lastDate: now.add(const Duration(days: 365)),
    );
    if (picked == null) return;
    setState(() => _eventDate = picked);
    await _refreshAvailability();
  }

  @override
  void initState() {
    super.initState();
    _placesFuture = widget.repository.getPlaces();
  }

  @override
  void dispose() {
    _titleC.dispose();
    _timeC.dispose();
    _categoryC.dispose();
    _descriptionC.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_titleC.text.trim().isEmpty || _placeId == null) {
      setState(() => _error = 'Başlık ve mekân seçimi zorunlu.');
      return;
    }
    setState(() {
      _submitting = true;
      _error = null;
    });
    try {
      await widget.repository.createOwnActivity(
        title: _titleC.text.trim(),
        placeId: _placeId!,
        time: _timeC.text.trim(),
        eventDate: _eventDate,
        category: _categoryC.text.trim().isEmpty ? 'Öğrenci Etkinliği' : _categoryC.text.trim(),
        description: _descriptionC.text.trim(),
      );
      if (!mounted) return;
      Navigator.of(context).pop(true);
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Aktiviten gönderildi — bir admin onayladığında yayına girer.')));
    } on PlaceConflictException catch (e) {
      if (!mounted) return;
      setState(() {
        _submitting = false;
        _error = e.reason;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _submitting = false;
        _error = 'Gönderilemedi. Lütfen tekrar dene.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Kendi Aktiviteni Oluştur')),
      body: FutureBuilder<List<CampusPlace>>(
        future: _placesFuture,
        builder: (context, snap) {
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
          final places = snap.data!;
          return ListView(
            padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
            children: [
              Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                    color: ArucadColors.mist, borderRadius: BorderRadius.circular(14)),
                child: const Text(
                  'Aktiviten gönderildikten sonra bir admin inceleyip onaylayana kadar '
                  'sadece sen görebilirsin — hiçbir şey otomatik yayınlanmaz.',
                  style: TextStyle(color: ArucadColors.muted, fontSize: 12.5),
                ),
              ),
              const SizedBox(height: 20),
              TextField(
                controller: _titleC,
                decoration: const InputDecoration(labelText: 'Başlık'),
              ),
              const SizedBox(height: 14),
              DropdownButtonFormField<String>(
                initialValue: _placeId,
                decoration: const InputDecoration(labelText: 'Mekân (sadece kayıtlı yerler)'),
                items: places.map((p) => DropdownMenuItem(value: p.id, child: Text(p.name))).toList(),
                onChanged: (v) {
                  setState(() => _placeId = v);
                  _refreshAvailability();
                },
              ),
              const SizedBox(height: 14),
              InkWell(
                onTap: _pickDate,
                child: InputDecorator(
                  decoration: const InputDecoration(labelText: 'Tarih'),
                  child: Text(_eventDate == null
                      ? 'Tarih seç'
                      : '${_eventDate!.day.toString().padLeft(2, '0')}.${_eventDate!.month.toString().padLeft(2, '0')}.${_eventDate!.year}'),
                ),
              ),
              const SizedBox(height: 14),
              TextField(
                controller: _timeC,
                decoration: const InputDecoration(labelText: 'Saat (opsiyonel, örn. 18:00)'),
                onChanged: (_) => _refreshAvailability(),
              ),
              if (_loadingAvailability) ...[
                const SizedBox(height: 8),
                const LinearProgressIndicator(),
              ] else if (_booked.isNotEmpty) ...[
                const SizedBox(height: 10),
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                      color: ArucadColors.warning.withValues(alpha: .1),
                      borderRadius: BorderRadius.circular(12)),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    const Text('Bu mekân o gün şu saatlerde dolu:',
                        style: TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5)),
                    const SizedBox(height: 4),
                    for (final b in _booked)
                      Text('${b.time} — ${b.title}',
                          style: const TextStyle(color: ArucadColors.muted, fontSize: 12.5)),
                  ]),
                ),
              ],
              const SizedBox(height: 14),
              TextField(
                controller: _categoryC,
                decoration: const InputDecoration(labelText: 'Kategori'),
              ),
              const SizedBox(height: 14),
              TextField(
                controller: _descriptionC,
                decoration: const InputDecoration(labelText: 'Açıklama'),
                maxLines: 4,
              ),
              if (_error != null) ...[
                const SizedBox(height: 12),
                Text(_error!, style: const TextStyle(color: ArucadColors.danger)),
              ],
              const SizedBox(height: 24),
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  onPressed: _submitting ? null : _submit,
                  child: Text(_submitting ? 'Gönderiliyor…' : 'Onaya Gönder'),
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}
