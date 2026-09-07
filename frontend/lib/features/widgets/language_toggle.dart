import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

/// Compact TR / EN / RU segmented control used on student, trainer and
/// admin chrome so every panel exposes the same three languages.
class LanguageToggle extends StatelessWidget {
  static const codes = ['TR', 'EN', 'RU'];

  final String code;
  final ValueChanged<String> onChanged;
  final bool onDark;

  const LanguageToggle({
    super.key,
    required this.code,
    required this.onChanged,
    this.onDark = false,
  });

  @override
  Widget build(BuildContext context) {
    final selected = code.toUpperCase();
    final scheme = Theme.of(context).colorScheme;
    return Container(
      padding: const EdgeInsets.all(3),
      decoration: BoxDecoration(
        color: onDark
            ? Colors.white.withValues(alpha: .1)
            : scheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(999),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          for (final lang in codes)
            _Option(
              label: lang,
              selected: selected == lang,
              onDark: onDark,
              onTap: () => onChanged(lang),
            ),
        ],
      ),
    );
  }
}

class _Option extends StatelessWidget {
  final String label;
  final bool selected;
  final bool onDark;
  final VoidCallback onTap;

  const _Option({
    required this.label,
    required this.selected,
    required this.onDark,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(999),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 150),
        padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
        decoration: BoxDecoration(
          color: selected
              ? (onDark ? Colors.white : ArucadColors.primary)
              : Colors.transparent,
          borderRadius: BorderRadius.circular(999),
        ),
        child: Text(
          label,
          style: TextStyle(
            color: selected
                ? (onDark ? ArucadColors.navy : Colors.white)
                : (onDark ? Colors.white70 : scheme.onSurfaceVariant),
            fontWeight: FontWeight.w800,
            fontSize: 11,
          ),
        ),
      ),
    );
  }
}
