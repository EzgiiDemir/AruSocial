import 'dart:async';

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

/// A short notice that slides in from the TOP of the screen (under the status
/// bar), on a white card with black text.
///
/// Used for moderation/validation feedback such as "içerik incelemeye alındı"
/// or a warning. A bottom snackbar was easy to miss and rendered dark; a top
/// white banner is where the eye lands when something is refused, and the
/// wording (which comes from the server) stays readable.
enum TopNoticeKind { info, warning, error, success }

void showTopNotice(
  BuildContext context, {
  required String message,
  TopNoticeKind kind = TopNoticeKind.info,
  Duration duration = const Duration(seconds: 6),
}) {
  final overlay = Overlay.maybeOf(context, rootOverlay: true);
  if (overlay == null) return;

  late OverlayEntry entry;
  entry = OverlayEntry(
    builder: (ctx) => _TopNotice(
      message: message,
      kind: kind,
      duration: duration,
      onDismiss: () {
        if (entry.mounted) entry.remove();
      },
    ),
  );
  overlay.insert(entry);
}

class _TopNotice extends StatefulWidget {
  const _TopNotice({
    required this.message,
    required this.kind,
    required this.duration,
    required this.onDismiss,
  });

  final String message;
  final TopNoticeKind kind;
  final Duration duration;
  final VoidCallback onDismiss;

  @override
  State<_TopNotice> createState() => _TopNoticeState();
}

class _TopNoticeState extends State<_TopNotice>
    with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 260),
  )..forward();
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _timer = Timer(widget.duration, _close);
  }

  Future<void> _close() async {
    _timer?.cancel();
    if (!mounted) {
      widget.onDismiss();
      return;
    }
    await _c.reverse();
    widget.onDismiss();
  }

  @override
  void dispose() {
    _timer?.cancel();
    _c.dispose();
    super.dispose();
  }

  (IconData, Color) get _visual => switch (widget.kind) {
        TopNoticeKind.error => (Icons.block_outlined, ArucadColors.red),
        TopNoticeKind.warning => (
            Icons.warning_amber_rounded,
            ArucadColors.yellow
          ),
        TopNoticeKind.success => (
            Icons.check_circle_outline,
            ArucadColors.campusGreen
          ),
        TopNoticeKind.info => (Icons.info_outline, ArucadColors.primary),
      };

  @override
  Widget build(BuildContext context) {
    final (icon, accent) = _visual;
    final slide = CurvedAnimation(parent: _c, curve: Curves.easeOutCubic);

    return Positioned(
      top: 0,
      left: 0,
      right: 0,
      child: SafeArea(
        bottom: false,
        child: SlideTransition(
          position: Tween<Offset>(
            begin: const Offset(0, -1),
            end: Offset.zero,
          ).animate(slide),
          child: FadeTransition(
            opacity: _c,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(12, 10, 12, 0),
              child: Material(
                color: Colors.white,
                elevation: 6,
                borderRadius: BorderRadius.circular(14),
                child: InkWell(
                  borderRadius: BorderRadius.circular(14),
                  onTap: _close,
                  child: Container(
                    decoration: BoxDecoration(
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(14),
                      border: Border.all(color: const Color(0xFFE0E0E0)),
                      // A thin accent bar keeps the card white while still
                      // signalling severity.
                      boxShadow: const [],
                    ),
                    child: IntrinsicHeight(
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Container(
                            width: 5,
                            decoration: BoxDecoration(
                              color: accent,
                              borderRadius: const BorderRadius.horizontal(
                                  left: Radius.circular(14)),
                            ),
                          ),
                          Padding(
                            padding: const EdgeInsets.all(12),
                            child: Icon(icon, color: accent, size: 22),
                          ),
                          Expanded(
                            child: Padding(
                              padding:
                                  const EdgeInsets.fromLTRB(0, 12, 12, 12),
                              child: Text(
                                widget.message,
                                style: const TextStyle(
                                  color: Colors.black,
                                  fontSize: 13.5,
                                  height: 1.4,
                                  fontWeight: FontWeight.w500,
                                ),
                              ),
                            ),
                          ),
                          IconButton(
                            icon: const Icon(Icons.close,
                                size: 18, color: Colors.black54),
                            onPressed: _close,
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
