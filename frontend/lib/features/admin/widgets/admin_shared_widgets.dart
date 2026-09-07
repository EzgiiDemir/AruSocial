part of '../admin_panel_screen.dart';

class _AdminListToolbar extends StatelessWidget {
  final ValueChanged<String> onQueryChanged;
  final String searchHint;
  final int selectedCount;
  final VoidCallback? onDeleteSelected;
  final VoidCallback? onCancelSelection;

  const _AdminListToolbar({
    required this.onQueryChanged,
    required this.searchHint,
    this.selectedCount = 0,
    this.onDeleteSelected,
    this.onCancelSelection,
  });

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    if (selectedCount > 0) {
      return Container(
        color: ArucadColors.primary.withValues(alpha: .08),
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
        child: Row(children: [
          IconButton(icon: const Icon(Icons.close), onPressed: onCancelSelection),
          Expanded(
              child: Text('$selectedCount ${strings.t('admin_toolbar_selected_suffix')}',
                  style: const TextStyle(fontWeight: FontWeight.w800))),
          TextButton.icon(
            onPressed: onDeleteSelected,
            icon: const Icon(Icons.delete_outline, color: ArucadColors.danger),
            label: Text(strings.t('admin_toolbar_delete'), style: const TextStyle(color: ArucadColors.danger)),
          ),
        ]),
      );
    }
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
      child: TextField(
        decoration: InputDecoration(
          hintText: searchHint,
          prefixIcon: const Icon(Icons.search, size: 20),
          isDense: true,
        ),
        onChanged: onQueryChanged,
      ),
    );
  }
}

/// Shared visual shell for every add/edit dialog in this panel: caps the
/// content at a comfortable reading width on wide/desktop screens while
/// still shrinking naturally on narrow ones (the [ConstrainedBox] only
/// bounds the *max*, so a small screen's own width constraint still wins),
/// and gives every field a consistent vertical rhythm instead of fields
/// butting up against each other.
class _DialogShell extends StatelessWidget {
  final List<Widget> fields;
  const _DialogShell({required this.fields});

  static const double maxWidth = 480;
  static const double gap = 14;

  @override
  Widget build(BuildContext context) => ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: maxWidth),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              for (int i = 0; i < fields.length; i++) ...[
                if (i > 0) const SizedBox(height: gap),
                fields[i],
              ],
            ],
          ),
        ),
      );
}

/// A small, muted, all-caps section label with a trailing rule — used to
/// separate logically distinct groups of fields inside a dialog (e.g.
/// "Basic Info" vs "Publishing & Audience"), in the same quiet register as
/// the sidebar's own [_SidebarGroupLabel].
class _DialogSection extends StatelessWidget {
  final String label;
  const _DialogSection(this.label);

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(top: 4, bottom: 2),
        child: Row(children: [
          Text(label.toUpperCase(),
              style: const TextStyle(
                  color: ArucadColors.primary,
                  fontSize: 11,
                  fontWeight: FontWeight.w800,
                  letterSpacing: .6)),
          const SizedBox(width: 8),
          const Expanded(child: Divider(color: ArucadColors.mist, thickness: 1.4, height: 1)),
        ]),
      );
}

