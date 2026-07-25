import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_effects.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/members_repository.dart';

String roleLabel(Translator t, WorkspaceRole role) => t(switch (role) {
      WorkspaceRole.owner => 'members.roleOwner',
      WorkspaceRole.admin => 'members.roleAdmin',
      WorkspaceRole.accountant => 'members.roleAccountant',
      WorkspaceRole.member => 'members.roleMember',
      WorkspaceRole.viewer => 'members.roleViewer',
    },);

/// Colour marks the one distinction that changes what a person can do to the
/// workspace itself: whether they can add and remove other people. The rest of
/// the ladder is carried by the icon and the word, because five hues would be
/// decoration rather than information.
Color roleAccent(WorkspaceRole role, {required bool isDark}) => switch (role) {
      WorkspaceRole.owner || WorkspaceRole.admin =>
        isDark ? NeonPalette.cyan : NeonPalette.lightCyan,
      WorkspaceRole.accountant || WorkspaceRole.member =>
        isDark ? NeonPalette.textSecondary : NeonPalette.lightTextSecondary,
      WorkspaceRole.viewer =>
        isDark ? NeonPalette.textMuted : NeonPalette.lightTextSecondary,
    };

IconData roleIcon(WorkspaceRole role) => switch (role) {
      WorkspaceRole.owner => Icons.workspace_premium_rounded,
      WorkspaceRole.admin => Icons.shield_rounded,
      WorkspaceRole.accountant => Icons.calculate_rounded,
      WorkspaceRole.member => Icons.person_rounded,
      WorkspaceRole.viewer => Icons.visibility_rounded,
    };

/// The role, said once and legibly.
///
/// The owner's badge is the only one that glows: it is the single row on the
/// screen whose privileges cannot be taken away, and that is worth seeing
/// before it is read.
class RoleBadge extends ConsumerWidget {
  const RoleBadge({super.key, required this.role, this.selected = true});

  final WorkspaceRole role;

  /// False dims the badge for the unchosen options in a role picker.
  final bool selected;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final accent = roleAccent(role, isDark: isDark);
    final glows = selected && role == WorkspaceRole.owner && isDark;

    return Container(
      padding:
          const EdgeInsetsDirectional.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: accent.withValues(alpha: selected ? (isDark ? 0.12 : 0.08) : 0),
        borderRadius: BorderRadius.circular(999),
        border: NeonEffects.border(
          accent,
          alpha: glows ? 0.6 : (selected ? 0.32 : 0.16),
        ),
        boxShadow: glows ? NeonEffects.glowTight(accent, intensity: 0.5) : null,
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            roleIcon(role),
            size: 13,
            color: selected ? accent : accent.withValues(alpha: 0.55),
          ),
          const SizedBox(width: 6),
          Text(
            roleLabel(t, role),
            style: TextStyle(
              fontSize: 11.5,
              fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
              color: selected ? accent : accent.withValues(alpha: 0.7),
            ),
          ),
        ],
      ),
    );
  }
}
