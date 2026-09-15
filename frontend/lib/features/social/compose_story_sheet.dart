import 'dart:async';
import 'dart:typed_data';

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/models/story_framing.dart';
import 'package:arucad_campus_prototype/core/services/photo_picker_service.dart';
import 'package:arucad_campus_prototype/features/social/story_framing_editor.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';
import 'package:arucad_campus_prototype/features/widgets/media_frame.dart';

/// Result of the story composer — caller moderates and posts.
class ComposeStoryResult {
  final String? text;
  final Uint8List? imageBytes;
  final int? backgroundColorValue;
  final Map<String, dynamic>? style;
  final PostVisibility visibility;

  const ComposeStoryResult({
    this.text,
    this.imageBytes,
    this.backgroundColorValue,
    this.style,
    this.visibility = PostVisibility.everyone,
  });
}

/// Story backgrounds intentionally offer a broad creative palette. ARUCAD's
/// four brand colours lead the list; the extra shades are confined to user-
/// generated story art and do not leak into the app chrome.
const _colorSwatches = <Color>[
  ArucadColors.primary,
  ArucadColors.red,
  ArucadColors.yellow,
  ArucadColors.campusGreen,
  ArucadColors.paper,
  ArucadColors.canvas,
  ArucadColors.ink,
  Color(0xFF1A1A2E),
  Color(0xFF2D3436),
  Color(0xFFE91E63),
  Color(0xFF9C27B0),
  Color(0xFF673AB7),
  Color(0xFF3F51B5),
  Color(0xFF03A9F4),
  Color(0xFF00BCD4),
  Color(0xFF009688),
  Color(0xFF4CAF50),
  Color(0xFF8BC34A),
  Color(0xFFCDDC39),
  Color(0xFFFFEB3B),
  Color(0xFFFFC107),
  Color(0xFFFF9800),
  Color(0xFFFF5722),
  Color(0xFF795548),
  Color(0xFF607D8B),
  Color(0xFFB71C1C),
  Color(0xFF0D47A1),
  Color(0xFF1B5E20),
  Color(0xFF4A148C),
  Color(0xFFFCE4EC),
  Color(0xFFE3F2FD),
  Color(0xFFE8F5E9),
  Color(0xFFFFF8E1),
  Color(0xFFF3E5F5),
];

const _gradientPresets = <List<Color>>[
  [ArucadColors.primary, ArucadColors.red],
  [ArucadColors.primary, ArucadColors.campusGreen],
  [ArucadColors.primary, ArucadColors.yellow],
  [ArucadColors.red, ArucadColors.yellow],
  [ArucadColors.campusGreen, ArucadColors.yellow],
  [ArucadColors.ink, ArucadColors.primary],
  [Color(0xFF667EEA), Color(0xFF764BA2)],
  [Color(0xFFF093FB), Color(0xFFF5576C)],
  [Color(0xFF4FACFE), Color(0xFF00F2FE)],
  [Color(0xFF43E97B), Color(0xFF38F9D7)],
  [Color(0xFFFA709A), Color(0xFFFEE140)],
  [Color(0xFF30CFD0), Color(0xFF330867)],
  [Color(0xFFFF512F), Color(0xFFDD2476)],
  [Color(0xFF2193B0), Color(0xFF6DD5ED)],
  [Color(0xFFCC2B5E), Color(0xFF753A88)],
  [Color(0xFFEE9CA7), Color(0xFFFFDDE1)],
  [Color(0xFF11998E), Color(0xFF38EF7D)],
  [Color(0xFFFC5C7D), Color(0xFF6A82FB)],
  [Color(0xFFC6FFDD), Color(0xFFFBD786), Color(0xFFF7797D)],
  [Color(0xFF0F2027), Color(0xFF203A43), Color(0xFF2C5364)],
  [Color(0xFFFFE259), Color(0xFFFFA751)],
  [Color(0xFF8E2DE2), Color(0xFF4A00E0)],
  [Color(0xFF00B09B), Color(0xFF96C93D)],
  [Color(0xFFE52D27), Color(0xFFB31217)],
];

const _fontOptions = <(String, String)>[
  ('Montserrat', 'Montserrat'),
  ('Oswald', 'Oswald'),
];

enum _StoryTool { palette, gradient, textColor, font, people }

/// Opens the rich story composer. Returns null if dismissed.
Future<ComposeStoryResult?> showComposeStorySheet(
  BuildContext context, {
  CampusRepository? repository,
}) {
  return showModalBottomSheet<ComposeStoryResult>(
    context: context,
    isScrollControlled: true,
    builder: (ctx) => _ComposeStorySheet(repository: repository),
  );
}

class _ComposeStorySheet extends StatefulWidget {
  final CampusRepository? repository;

  const _ComposeStorySheet({this.repository});

  @override
  State<_ComposeStorySheet> createState() => _ComposeStorySheetState();
}

class _ComposeStorySheetState extends State<_ComposeStorySheet> {
  final _textController = TextEditingController();
  final _peopleQuery = TextEditingController();
  Uint8List? _pickedBytes;

  /// How the photo sits in the frame. Held as metadata rather than
  /// baked into the bytes, so the original photo is never destroyed and
  /// the author can change their mind.
  StoryFraming _framing = const StoryFraming();
  PostVisibility _visibility = PostVisibility.everyone;

  bool _useGradient = false;
  Color _solidColor = ArucadColors.primary;
  List<Color> _gradientColors = List.of(_gradientPresets.first);

  Color _textColor = Colors.white;
  String _fontFamily = 'Montserrat';
  static const double _fontSize = 26;

  _StoryTool? _activeTool = _StoryTool.palette;

  final List<String> _taggedPeople = [];
  List<LeaderboardEntry> _people = const [];
  bool _peopleLoading = false;

  @override
  void initState() {
    super.initState();
    unawaited(_loadPeople());
  }

  @override
  void dispose() {
    _textController.dispose();
    _peopleQuery.dispose();
    super.dispose();
  }

  Future<void> _loadPeople() async {
    final repo = widget.repository;
    if (repo == null) return;
    setState(() => _peopleLoading = true);
    try {
      final board = await repo.getLeaderboard();
      if (!mounted) return;
      setState(() {
        _people = board.where((p) => !p.isMe).toList();
        _peopleLoading = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() => _peopleLoading = false);
    }
  }

  Color get _activeSolid => _useGradient ? _gradientColors.first : _solidColor;

  Map<String, dynamic> _buildStyle() {
    return {
      if (_useGradient)
        'gradientColors':
            _gradientColors.map((c) => c.toARGB32()).toList(growable: false),
      'textColor': _textColor.toARGB32(),
      'fontFamily': _fontFamily,
      'fontSize': _fontSize,
      'fontWeight': 'w800',
      if (_taggedPeople.isNotEmpty)
        'taggedPeople': List<String>.from(_taggedPeople),
    };
  }

  TextStyle _previewTextStyle() => storyTextStyleFromMap(_buildStyle());

  BoxDecoration _previewDecoration() {
    if (_useGradient && _gradientColors.length >= 2) {
      return BoxDecoration(
        gradient: LinearGradient(
          colors: _gradientColors,
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
      );
    }
    return BoxDecoration(color: _solidColor);
  }

  Future<void> _share() async {
    final text = _textController.text.trim();
    if (text.isEmpty && _pickedBytes == null) return;
    final isPhoto = _pickedBytes != null;
    Navigator.of(context).pop(ComposeStoryResult(
      text: isPhoto ? null : text,
      imageBytes: _pickedBytes,
      backgroundColorValue: isPhoto ? null : _activeSolid.toARGB32(),
      style: isPhoto
          ? _framing.toStyle({
              if (_taggedPeople.isNotEmpty)
                'taggedPeople': List<String>.from(_taggedPeople),
            })
          : _buildStyle(),
      visibility: _visibility,
    ));
  }

  void _toggleTool(_StoryTool tool) {
    setState(() => _activeTool = _activeTool == tool ? null : tool);
  }

  List<LeaderboardEntry> get _filteredPeople {
    final q = _peopleQuery.text.trim().toLowerCase();
    if (q.isEmpty) return _people.take(24).toList();
    return _people
        .where((p) => p.name.toLowerCase().contains(q))
        .take(24)
        .toList();
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    final bottomInset = MediaQuery.of(context).viewInsets.bottom;
    final isPhoto = _pickedBytes != null;

    return Padding(
      padding: EdgeInsets.only(
        left: ArucadSpacing.md,
        right: ArucadSpacing.md,
        top: ArucadSpacing.md,
        bottom: bottomInset + ArucadSpacing.md,
      ),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(strings.t('social_new_story'),
                style:
                    const TextStyle(fontWeight: FontWeight.w900, fontSize: 18)),
            const SizedBox(height: 14),
            if (isPhoto) ...[
              // The editor *is* the preview, and it renders with the same
              // widget the viewer uses — so what is on screen here is what
              // gets published, rather than an approximation of it.
              SizedBox(
                height: 360,
                child: StoryFramingEditor(
                  bytes: _pickedBytes!,
                  framing: _framing,
                  onChanged: (next) => setState(() => _framing = next),
                ),
              ),
              const SizedBox(height: 8),
              Align(
                alignment: Alignment.centerRight,
                child: TextButton.icon(
                  onPressed: () => setState(() {
                    _pickedBytes = null;
                    _framing = const StoryFraming();
                  }),
                  icon: const Icon(Icons.close, size: 18),
                  label: Text(strings.t('social_remove_photo')),
                ),
              ),
            ] else ...[
              Align(
                alignment: Alignment.center,
                child: SizedBox(
                  height: 200,
                  width: 200 * MediaFrame.story,
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(ArucadRadius.card),
                    child: Container(
                      decoration: _previewDecoration(),
                      alignment: Alignment.center,
                      padding: const EdgeInsets.all(16),
                      child: Text(
                        _textController.text.trim().isEmpty
                            ? strings.t('social_or_write_text')
                            : _textController.text.trim(),
                        textAlign: TextAlign.center,
                        style: _previewTextStyle().copyWith(
                          color: _textController.text.trim().isEmpty
                              ? _textColor.withValues(alpha: 0.55)
                              : _textColor,
                        ),
                      ),
                    ),
                  ),
                ),
              ),
              const SizedBox(height: 10),
              OutlinedButton.icon(
                onPressed: () async {
                  final bytes = await PhotoPickerService.pick(context);
                  if (bytes == null) return;

                  // Deliberately NOT cropped here. The old code centre-cropped
                  // to 9:16 the moment a photo was chosen, which threw away
                  // the sides of every portrait and is the reason stories came
                  // out looking so much closer than the photo. Framing is now
                  // metadata the author controls and can undo.
                  setState(() {
                    _pickedBytes = bytes;
                    _framing = const StoryFraming();
                  });
                },
                icon: const Icon(Icons.add_a_photo_outlined),
                label: Text(strings.t('social_add_photo')),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: _textController,
                maxLines: 2,
                onChanged: (_) => setState(() {}),
                decoration: InputDecoration(
                    hintText: strings.t('social_or_write_text')),
              ),
            ],
            if (_taggedPeople.isNotEmpty) ...[
              const SizedBox(height: 10),
              Wrap(
                spacing: 6,
                runSpacing: 6,
                children: [
                  for (final name in _taggedPeople)
                    InputChip(
                      avatar: const Icon(Icons.person_outline, size: 16),
                      label: Text(name),
                      onDeleted: () =>
                          setState(() => _taggedPeople.remove(name)),
                    ),
                ],
              ),
            ],
            const SizedBox(height: 14),
            _ToolTabBar(
              active: _activeTool,
              showStyleTools: !isPhoto,
              onSelect: _toggleTool,
            ),
            if (_activeTool != null) ...[
              const SizedBox(height: 10),
              _ToolCard(
                child: switch (_activeTool!) {
                  _StoryTool.palette => _ColorGrid(
                      colors: _colorSwatches,
                      selected: !_useGradient ? _solidColor : null,
                      onPick: (c) => setState(() {
                        _useGradient = false;
                        _solidColor = c;
                      }),
                    ),
                  _StoryTool.gradient => _GradientGrid(
                      presets: _gradientPresets,
                      selected: _useGradient ? _gradientColors : null,
                      onPick: (preset) => setState(() {
                        _useGradient = true;
                        _gradientColors = List.of(preset);
                      }),
                    ),
                  _StoryTool.textColor => _ColorGrid(
                      colors: _colorSwatches,
                      selected: _textColor,
                      onPick: (c) => setState(() => _textColor = c),
                    ),
                  _StoryTool.font => _FontGrid(
                      options: _fontOptions,
                      selected: _fontFamily,
                      onPick: (key) => setState(() => _fontFamily = key),
                    ),
                  _StoryTool.people => _PeoplePanel(
                      loading: _peopleLoading,
                      queryController: _peopleQuery,
                      people: _filteredPeople,
                      tagged: _taggedPeople,
                      onQueryChanged: () => setState(() {}),
                      onToggle: (name) => setState(() {
                        if (_taggedPeople.contains(name)) {
                          _taggedPeople.remove(name);
                        } else {
                          _taggedPeople.add(name);
                        }
                      }),
                    ),
                },
              ),
            ],
            const SizedBox(height: 14),
            Text(strings.t('social_visibility'),
                style:
                    const TextStyle(fontWeight: FontWeight.w700, fontSize: 12)),
            const SizedBox(height: 6),
            AudienceChips(
              value: _visibility,
              onChanged: (v) => setState(() => _visibility = v),
            ),
            const SizedBox(height: 16),
            SizedBox(
              width: double.infinity,
              child: FilledButton(
                style: FilledButton.styleFrom(
                  backgroundColor: ArucadColors.primary,
                  foregroundColor: Colors.white,
                ),
                onPressed: _share,
                child: Text(strings.t('social_share')),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _ToolTabBar extends StatelessWidget {
  final _StoryTool? active;
  final bool showStyleTools;
  final ValueChanged<_StoryTool> onSelect;

  const _ToolTabBar({
    required this.active,
    required this.showStyleTools,
    required this.onSelect,
  });

  @override
  Widget build(BuildContext context) {
    final tabs = <(_StoryTool, IconData, String)>[
      if (showStyleTools) ...[
        (_StoryTool.palette, Icons.palette_outlined, 'Palet'),
        (_StoryTool.gradient, Icons.gradient, 'Gradient'),
        (_StoryTool.textColor, Icons.format_color_text, 'Yazı'),
        (_StoryTool.font, Icons.font_download_outlined, 'Font'),
      ],
      (_StoryTool.people, Icons.person_add_alt_1_outlined, 'Kişi'),
    ];
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Row(
        children: [
          for (final tab in tabs) ...[
            Padding(
              padding: const EdgeInsets.only(right: 6),
              child: ChoiceChip(
                avatar: Icon(tab.$2, size: 16),
                label: Text(tab.$3, style: const TextStyle(fontSize: 12)),
                selected: active == tab.$1,
                selectedColor: ArucadColors.blue.withValues(alpha: .18),
                onSelected: (_) => onSelect(tab.$1),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _ToolCard extends StatelessWidget {
  final Widget child;
  const _ToolCard({required this.child});

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Theme.of(context).colorScheme.surfaceContainerHighest,
      borderRadius: BorderRadius.circular(16),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: child,
      ),
    );
  }
}

class _ColorGrid extends StatelessWidget {
  final List<Color> colors;
  final Color? selected;
  final ValueChanged<Color> onPick;

  const _ColorGrid({
    required this.colors,
    required this.selected,
    required this.onPick,
  });

  @override
  Widget build(BuildContext context) {
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        for (final color in colors)
          GestureDetector(
            onTap: () => onPick(color),
            child: _SwatchCircle(
              color: color,
              selected:
                  selected != null && selected!.toARGB32() == color.toARGB32(),
            ),
          ),
      ],
    );
  }
}

class _GradientGrid extends StatelessWidget {
  final List<List<Color>> presets;
  final List<Color>? selected;
  final ValueChanged<List<Color>> onPick;

  const _GradientGrid({
    required this.presets,
    required this.selected,
    required this.onPick,
  });

  bool _isSelected(List<Color> preset) {
    final sel = selected;
    if (sel == null || sel.length != preset.length) return false;
    for (var i = 0; i < sel.length; i++) {
      if (sel[i].toARGB32() != preset[i].toARGB32()) return false;
    }
    return true;
  }

  @override
  Widget build(BuildContext context) {
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        for (final preset in presets)
          GestureDetector(
            onTap: () => onPick(preset),
            child: Container(
              width: 52,
              height: 36,
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(10),
                gradient: LinearGradient(colors: preset),
                border: Border.all(
                  color: _isSelected(preset)
                      ? ArucadColors.ink
                      : ArucadColors.border,
                  width: _isSelected(preset) ? 2.2 : 1,
                ),
              ),
            ),
          ),
      ],
    );
  }
}

class _FontGrid extends StatelessWidget {
  final List<(String, String)> options;
  final String selected;
  final ValueChanged<String> onPick;

  const _FontGrid({
    required this.options,
    required this.selected,
    required this.onPick,
  });

  @override
  Widget build(BuildContext context) {
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        for (final opt in options)
          ChoiceChip(
            label: Text(
              opt.$2,
              style: storyTextStyleFromMap({
                'fontFamily': opt.$1,
                'fontSize': 13,
                'fontWeight': 'w700',
                'textColor': ArucadColors.ink.toARGB32(),
              }),
            ),
            selected: selected == opt.$1,
            selectedColor: ArucadColors.blue.withValues(alpha: .18),
            onSelected: (_) => onPick(opt.$1),
          ),
      ],
    );
  }
}

class _PeoplePanel extends StatelessWidget {
  final bool loading;
  final TextEditingController queryController;
  final List<LeaderboardEntry> people;
  final List<String> tagged;
  final VoidCallback onQueryChanged;
  final ValueChanged<String> onToggle;

  const _PeoplePanel({
    required this.loading,
    required this.queryController,
    required this.people,
    required this.tagged,
    required this.onQueryChanged,
    required this.onToggle,
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          controller: queryController,
          onChanged: (_) => onQueryChanged(),
          decoration: const InputDecoration(
            hintText: 'Kişi ara…',
            prefixIcon: Icon(Icons.search, size: 18),
            isDense: true,
          ),
        ),
        const SizedBox(height: 10),
        if (loading)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 12),
            child: Center(child: CircularProgressIndicator(strokeWidth: 2)),
          )
        else if (people.isEmpty)
          const Text('Etiketlenecek kişi bulunamadı.',
              style: TextStyle(color: ArucadColors.muted, fontSize: 13))
        else
          ConstrainedBox(
            constraints: const BoxConstraints(maxHeight: 180),
            child: SingleChildScrollView(
              child: Wrap(
                spacing: 6,
                runSpacing: 6,
                children: [
                  for (final person in people)
                    FilterChip(
                      label: Text(person.name),
                      selected: tagged.contains(person.name),
                      onSelected: (_) => onToggle(person.name),
                    ),
                ],
              ),
            ),
          ),
      ],
    );
  }
}

class _SwatchCircle extends StatelessWidget {
  final Color color;
  final bool selected;

  const _SwatchCircle({required this.color, required this.selected});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 30,
      height: 30,
      decoration: BoxDecoration(
        color: color,
        shape: BoxShape.circle,
        border: Border.all(
          color: color.computeLuminance() > 0.85
              ? ArucadColors.border
              : (selected ? ArucadColors.ink : Colors.transparent),
          width: selected ? 2 : 1,
        ),
        boxShadow: selected
            ? const [
                BoxShadow(
                    color: Color(0x44000000), blurRadius: 4, spreadRadius: 1)
              ]
            : null,
      ),
      child: selected
          ? Icon(Icons.check,
              size: 14,
              color:
                  color.computeLuminance() > 0.55 ? Colors.black : Colors.white)
          : null,
    );
  }
}

/// Shared style → [TextStyle] mapping for composer preview and story viewer.
TextStyle storyTextStyleFromMap(Map<String, dynamic>? style) {
  final familyKey = style?['fontFamily'] as String? ?? 'Montserrat';
  final fontSize = (style?['fontSize'] as num?)?.toDouble() ?? 26;
  final textColor = style?['textColor'] is num
      ? Color((style!['textColor'] as num).toInt())
      : Colors.white;
  final weightKey = style?['fontWeight'] as String? ?? 'w800';
  final weight = switch (weightKey) {
    'w400' => FontWeight.w400,
    'w600' => FontWeight.w600,
    _ => FontWeight.w800,
  };
  // Older stories may still contain one of the retired style keys. They are
  // intentionally rendered with Montserrat so every page uses only the two
  // official ARUCAD families.
  final family = familyKey == 'Oswald' ? 'Oswald' : 'Montserrat';
  return TextStyle(
    color: textColor,
    fontSize: fontSize,
    fontWeight: weight,
    fontFamily: family,
    fontFamilyFallback: const ['Montserrat'],
    height: 1.25,
  );
}

/// Background decoration for text stories (solid or gradient from style).
BoxDecoration storyBackgroundDecoration({
  required int? backgroundColorValue,
  Map<String, dynamic>? style,
}) {
  final raw = style?['gradientColors'];
  if (raw is List && raw.length >= 2) {
    final colors = <Color>[];
    for (final item in raw) {
      if (item is num) colors.add(Color(item.toInt()));
    }
    if (colors.length >= 2) {
      return BoxDecoration(
        gradient: LinearGradient(
          colors: colors,
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
      );
    }
  }
  return BoxDecoration(
    color: Color(backgroundColorValue ?? ArucadColors.blue.toARGB32()),
  );
}
