import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

/// Reference-faithful controls shared by student surfaces. These use named
/// tokens rather than one-off values so new pages inherit the supplied visual
/// language instead of drifting toward generic Material defaults.
class ArucadPrimaryButton extends StatelessWidget {
  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  const ArucadPrimaryButton(
      {super.key, required this.label, this.onPressed, this.icon});

  @override
  Widget build(BuildContext context) => FilledButton.icon(
        onPressed: onPressed,
        icon: icon == null ? const SizedBox.shrink() : Icon(icon, size: 19),
        label: Text(label),
        style: FilledButton.styleFrom(
          minimumSize: const Size(0, 46),
          padding: const EdgeInsets.symmetric(horizontal: 22, vertical: 12),
          shape: const StadiumBorder(),
        ),
      );
}

class ArucadSecondaryButton extends StatelessWidget {
  final String label;
  final Color color;
  final VoidCallback? onPressed;
  const ArucadSecondaryButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.color = ArucadColors.blue,
  });

  @override
  Widget build(BuildContext context) => FilledButton(
        onPressed: onPressed,
        style: FilledButton.styleFrom(
          minimumSize: const Size(0, 46),
          backgroundColor: color,
          foregroundColor: onAccent(color),
          shape: const StadiumBorder(),
        ),
        child: Text(label),
      );
}

class ArucadOutlineButton extends StatelessWidget {
  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  const ArucadOutlineButton(
      {super.key, required this.label, this.onPressed, this.icon});

  @override
  Widget build(BuildContext context) => OutlinedButton.icon(
        onPressed: onPressed,
        icon: icon == null ? const SizedBox.shrink() : Icon(icon, size: 19),
        label: Text(label),
        style: OutlinedButton.styleFrom(
          minimumSize: const Size(0, 46),
          shape: const StadiumBorder(),
          side: const BorderSide(color: ArucadColors.primary, width: 1.4),
        ),
      );
}

class ArucadStatusCard extends StatelessWidget {
  final Widget leading;
  final String title;
  final String subtitle;
  final Widget? trailing;
  final VoidCallback? onTap;
  const ArucadStatusCard({
    super.key,
    required this.leading,
    required this.title,
    required this.subtitle,
    this.trailing,
    this.onTap,
  });

  @override
  Widget build(BuildContext context) => Container(
        decoration: BoxDecoration(
          color: Theme.of(context).colorScheme.surface,
          borderRadius: BorderRadius.circular(ArucadRadius.feature),
          boxShadow: ArucadShadows.card,
        ),
        child: Material(
          color: Colors.transparent,
          child: InkWell(
            onTap: onTap,
            borderRadius: BorderRadius.circular(ArucadRadius.feature),
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Row(children: [
                leading,
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(title,
                            style: const TextStyle(
                                fontSize: 16, fontWeight: FontWeight.w900)),
                        const SizedBox(height: 4),
                        Text(subtitle,
                            style: TextStyle(
                                color: Theme.of(context)
                                    .colorScheme
                                    .onSurfaceVariant,
                                fontSize: 13)),
                      ]),
                ),
                if (trailing != null) ...[const SizedBox(width: 10), trailing!],
              ]),
            ),
          ),
        ),
      );
}

class ArucadIconBadge extends StatelessWidget {
  final IconData icon;
  final Color color;
  final double size;
  const ArucadIconBadge({
    super.key,
    required this.icon,
    this.color = ArucadColors.primary,
    this.size = 52,
  });

  @override
  Widget build(BuildContext context) => Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          color: color,
          borderRadius: BorderRadius.circular(ArucadRadius.compact),
        ),
        alignment: Alignment.center,
        child: Icon(icon, color: onAccent(color), size: size * .48),
      );
}

class ArucadSectionHeading extends StatelessWidget {
  final String title;
  final String? action;
  final IconData? actionIcon;
  final VoidCallback? onAction;
  const ArucadSectionHeading({
    super.key,
    required this.title,
    this.action,
    this.actionIcon,
    this.onAction,
  });

  @override
  Widget build(BuildContext context) => Row(children: [
        Expanded(
            child: Text(title,
                style: const TextStyle(
                    fontSize: 20, fontWeight: FontWeight.w900))),
        if (action != null)
          TextButton.icon(
            onPressed: onAction,
            icon: actionIcon == null
                ? const SizedBox.shrink()
                : Icon(actionIcon, size: 18),
            label: Text(action!),
          ),
      ]);
}

class ArucadInfoRow extends StatelessWidget {
  final IconData icon;
  final String label;
  final String value;
  final VoidCallback? onTap;
  const ArucadInfoRow({
    super.key,
    required this.icon,
    required this.label,
    required this.value,
    this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final muted = Theme.of(context).colorScheme.onSurfaceVariant;
    return ListTile(
        onTap: onTap,
        contentPadding: EdgeInsets.zero,
        leading: Icon(icon, color: ArucadColors.primary),
        title: Text(label, style: const TextStyle(fontWeight: FontWeight.w800)),
        subtitle: Text(value, style: TextStyle(color: muted)),
        trailing: onTap == null
            ? null
            : Icon(Icons.chevron_right, color: muted),
      );
  }
}

class ArucadSearchPill extends StatelessWidget {
  final String hint;
  final VoidCallback? onTap;
  const ArucadSearchPill({super.key, required this.hint, this.onTap});

  @override
  Widget build(BuildContext context) {
    final muted = Theme.of(context).colorScheme.onSurfaceVariant;
    return Material(
        color: Theme.of(context).colorScheme.surface,
        borderRadius: BorderRadius.circular(ArucadRadius.pill),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(ArucadRadius.pill),
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(ArucadRadius.pill),
              border: Border.all(color: Theme.of(context).colorScheme.outline),
            ),
            child: Row(children: [
              Icon(Icons.search, size: 19, color: muted),
              const SizedBox(width: 8),
              Expanded(
                  child: Text(hint,
                      style: TextStyle(color: muted, fontSize: 12.5))),
            ]),
          ),
        ),
      );
  }
}

class ArucadToggleRow extends StatelessWidget {
  final String title;
  final String? subtitle;
  final bool value;
  final ValueChanged<bool>? onChanged;
  const ArucadToggleRow({
    super.key,
    required this.title,
    required this.value,
    this.onChanged,
    this.subtitle,
  });

  @override
  Widget build(BuildContext context) => SwitchListTile.adaptive(
        contentPadding: EdgeInsets.zero,
        title: Text(title, style: const TextStyle(fontWeight: FontWeight.w800)),
        subtitle: subtitle == null
            ? null
            : Text(subtitle!,
                style: TextStyle(
                    fontSize: 12,
                    color: Theme.of(context).colorScheme.onSurfaceVariant)),
        value: value,
        onChanged: onChanged,
      );
}

class ArucadMessageRow extends StatelessWidget {
  final Widget avatar;
  final String name;
  final String preview;
  final String time;
  final VoidCallback? onTap;
  const ArucadMessageRow({
    super.key,
    required this.avatar,
    required this.name,
    required this.preview,
    required this.time,
    this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final muted = Theme.of(context).colorScheme.onSurfaceVariant;
    return ListTile(
        onTap: onTap,
        contentPadding: const EdgeInsets.symmetric(vertical: 4),
        leading: avatar,
        title: Text(name, style: const TextStyle(fontWeight: FontWeight.w900)),
        subtitle: Text(preview, maxLines: 1, overflow: TextOverflow.ellipsis),
        trailing: Column(mainAxisSize: MainAxisSize.min, children: [
          Text(time, style: TextStyle(fontSize: 11, color: muted)),
          const SizedBox(height: 4),
          Icon(Icons.chevron_right, size: 18, color: muted),
        ]),
      );
  }
}
