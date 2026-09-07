import 'package:flutter/material.dart';

/// Shared back control: pops the current route when there is history,
/// otherwise runs [onFallback] or a safe no-op. Never dumps the user on
/// Home unless the caller says so.
class CampusBackButton extends StatelessWidget {
  final VoidCallback? onFallback;
  final Color? color;

  const CampusBackButton({super.key, this.onFallback, this.color});

  static void go(BuildContext context, {VoidCallback? onFallback}) {
    final nav = Navigator.of(context);
    if (nav.canPop()) {
      nav.pop();
      return;
    }
    onFallback?.call();
  }

  @override
  Widget build(BuildContext context) {
    return IconButton(
      tooltip: 'Geri',
      icon: Icon(Icons.arrow_back, color: color),
      onPressed: () => go(context, onFallback: onFallback),
    );
  }
}
