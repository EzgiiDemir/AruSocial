part of '../admin_panel_screen.dart';

// ------------------------------------------------------- Users & Roles

class _UsersTab extends StatefulWidget {
  final CampusRepository repository;
  final String adminName;
  const _UsersTab({required this.repository, required this.adminName});
  @override
  State<_UsersTab> createState() => _UsersTabState();
}

class _UsersTabState extends State<_UsersTab> {
  late Future<List<RoleAssignment>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getRoleAssignments();
  }

  void _reload() => setState(() { _future = widget.repository.getRoleAssignments(); });

  Future<void> _editAssignment([RoleAssignment? existing]) async {
    final strings = AdminLocale.of(context);
    final emailC = TextEditingController(text: existing?.email);
    var role = existing?.role ?? UserRole.student;

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(existing == null ? strings.t('admin_role_new') : strings.t('admin_role_edit')),
          content: _DialogShell(fields: [
            TextField(
              controller: emailC,
              enabled: existing == null,
              decoration: InputDecoration(labelText: strings.t('admin_role_email')),
            ),
            DropdownButtonFormField<UserRole>(
              initialValue: role,
              decoration: InputDecoration(labelText: strings.t('admin_field_role')),
              items: UserRole.values
                  .map((r) => DropdownMenuItem(value: r, child: Text(r.label)))
                  .toList(),
              onChanged: (v) => setDialogState(() => role = v ?? role),
            ),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(strings.t('admin_cancel'))),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(strings.t('admin_save'))),
          ],
        ),
      ),
    );
    if (saved != true || emailC.text.trim().isEmpty) return;
    await widget.repository
        .setRoleAssignment(emailC.text.trim(), role, assignedBy: widget.adminName);
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: 'role_change',
        targetType: 'user',
        targetLabel: '${emailC.text.trim()} → ${role.label}');
    _reload();
  }

  Future<void> _remove(RoleAssignment a) async {
    await widget.repository.deleteRoleAssignment(a.email);
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: 'role_change',
        targetType: 'user',
        targetLabel: '${a.email} → (kaldırıldı, varsayılana döner)');
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    return Scaffold(
      floatingActionButton: FloatingActionButton(
          onPressed: () => _editAssignment(), child: const Icon(Icons.person_add_outlined)),
      body: FutureBuilder<List<RoleAssignment>>(
        future: _future,
        builder: (context, snap) {
          if (snap.hasError) return _AdminLoadError(error: snap.error!);
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
          final assignments = snap.data!;
          return ListView(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 90),
            children: [
              Card(
                color: ArucadColors.mist,
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Text(
                    strings.t('admin_role_local_note'),
                    style: const TextStyle(color: ArucadColors.muted, fontSize: 12),
                  ),
                ),
              ),
              const SizedBox(height: 12),
              if (assignments.isEmpty)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 24),
                  child: Center(
                      child: Text(strings.t('admin_role_none_yet'),
                          style: const TextStyle(color: ArucadColors.muted))),
                )
              else
                for (final a in assignments)
                  Card(
                    child: ListTile(
                      leading: const Icon(Icons.person_outline, color: ArucadColors.primary),
                      title: Text(a.email,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontWeight: FontWeight.w700)),
                      subtitle: Text(
                          strings
                              .t('admin_role_assigned_by')
                              .replaceAll('{role}', a.role.label)
                              .replaceAll('{name}', a.assignedBy),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis),
                      onTap: () => _editAssignment(a),
                      trailing: IconButton(
                        icon: const Icon(Icons.delete_outline),
                        onPressed: () => _remove(a),
                      ),
                    ),
                  ),
            ],
          );
        },
      ),
    );
  }
}
