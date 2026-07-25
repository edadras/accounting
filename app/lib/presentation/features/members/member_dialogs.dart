import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/members_repository.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_card.dart';
import 'role_badge.dart';

/// Asks before something irreversible happens.
///
/// [accent] is the destructiveness of the action, not decoration: magenta for a
/// removal or a revocation, cyan for a change that can be made again.
Future<bool> confirmAction(
  BuildContext context, {
  required String title,
  required String body,
  required String confirmLabel,
  Color accent = NeonPalette.magenta,
}) async {
  final confirmed = await showDialog<bool>(
    context: context,
    builder: (context) => _ConfirmDialog(
      title: title,
      body: body,
      confirmLabel: confirmLabel,
      accent: accent,
    ),
  );

  return confirmed ?? false;
}

class _ConfirmDialog extends ConsumerWidget {
  const _ConfirmDialog({
    required this.title,
    required this.body,
    required this.confirmLabel,
    required this.accent,
  });

  final String title;
  final String body;
  final String confirmLabel;
  final Color accent;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);

    return _DialogShell(
      accent: accent,
      children: [
        _DialogTitle(title),
        const SizedBox(height: 10),
        _DialogBody(body),
        const SizedBox(height: 20),
        _DialogActions(
          accent: accent,
          cancelLabel: t('common.cancel'),
          confirmLabel: confirmLabel,
          onConfirm: () => Navigator.of(context).pop(true),
        ),
      ],
    );
  }
}

/// Picks the new role and confirms it in one step — a separate "are you sure"
/// after a picker asks the same question twice.
Future<WorkspaceRole?> pickNewRole(
  BuildContext context,
  WorkspaceMember member,
) =>
    showDialog<WorkspaceRole>(
      context: context,
      builder: (context) => _RoleDialog(member: member),
    );

class _RoleDialog extends ConsumerStatefulWidget {
  const _RoleDialog({required this.member});

  final WorkspaceMember member;

  @override
  ConsumerState<_RoleDialog> createState() => _RoleDialogState();
}

class _RoleDialogState extends ConsumerState<_RoleDialog> {
  late WorkspaceRole _selected = widget.member.role;

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);
    final unchanged = _selected == widget.member.role;

    return _DialogShell(
      accent: NeonPalette.cyan,
      children: [
        _DialogTitle(t('members.changeRoleTitle')),
        const SizedBox(height: 14),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final role in WorkspaceRole.assignable)
              GestureDetector(
                key: ValueKey('role-option-${role.name}'),
                onTap: () => setState(() => _selected = role),
                child: RoleBadge(role: role, selected: role == _selected),
              ),
          ],
        ),
        const SizedBox(height: 16),
        _DialogBody(
          t(
            'members.changeRoleBody',
            args: {
              'name': widget.member.name,
              'role': roleLabel(t, _selected),
            },
          ),
        ),
        const SizedBox(height: 20),
        _DialogActions(
          accent: NeonPalette.cyan,
          cancelLabel: t('common.cancel'),
          confirmLabel: t('members.confirm'),
          onConfirm:
              unchanged ? null : () => Navigator.of(context).pop(_selected),
        ),
      ],
    );
  }
}

class _DialogShell extends StatelessWidget {
  const _DialogShell({required this.accent, required this.children});

  final Color accent;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return Dialog(
      backgroundColor: Colors.transparent,
      elevation: 0,
      insetPadding: const EdgeInsets.symmetric(horizontal: 24, vertical: 40),
      child: SingleChildScrollView(
        child: NeonCard(
          accent: accent,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: children,
          ),
        ),
      ),
    );
  }
}

class _DialogTitle extends StatelessWidget {
  const _DialogTitle(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Text(
      text,
      style: TextStyle(
        fontSize: 16,
        fontWeight: FontWeight.w800,
        color:
            isDark ? NeonPalette.textPrimary : NeonPalette.lightTextPrimary,
      ),
    );
  }
}

class _DialogBody extends StatelessWidget {
  const _DialogBody(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Text(
      text,
      style: TextStyle(
        fontSize: 13,
        height: 1.6,
        color: isDark
            ? NeonPalette.textSecondary
            : NeonPalette.lightTextSecondary,
      ),
    );
  }
}

class _DialogActions extends StatelessWidget {
  const _DialogActions({
    required this.accent,
    required this.cancelLabel,
    required this.confirmLabel,
    required this.onConfirm,
  });

  final Color accent;
  final String cancelLabel;
  final String confirmLabel;
  final VoidCallback? onConfirm;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.end,
      children: [
        NeonButton(
          label: cancelLabel,
          variant: NeonButtonVariant.ghost,
          accent: NeonPalette.textSecondary,
          onPressed: () => Navigator.of(context).pop(),
        ),
        const SizedBox(width: 10),
        Flexible(
          child: NeonButton(
            label: confirmLabel,
            accent: accent,
            onPressed: onConfirm,
          ),
        ),
      ],
    );
  }
}
