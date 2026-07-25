import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_effects.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/members_repository.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_widgets.dart';
import '../more/module_scaffold.dart';
import 'access_notice.dart';
import 'invite_screen.dart';
import 'member_dialogs.dart';
import 'members_providers.dart';
import 'role_badge.dart';

/// Who is in this workspace, who has been invited, and what either can do.
///
/// The server refuses this whole screen to anyone below admin, so the failure
/// path is not an afterthought here — it is the view an ordinary member gets.
class MembersScreen extends ConsumerWidget {
  const MembersScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final members = ref.watch(workspaceMembersProvider);

    return ModulePage(
      title: t('members.title'),
      subtitle: t('members.subtitle'),
      child: members.when(
        loading: () => const ModuleLoading(),
        error: (error, _) => noticeForFailure(
          error,
          t: t,
          permissionTitle: t('members.noAccessTitle'),
          permissionBody: t('members.noAccessBody'),
        ),
        data: (list) => _MembersBody(members: list),
      ),
    );
  }
}

class _MembersBody extends ConsumerWidget {
  const _MembersBody({required this.members});

  final List<WorkspaceMember> members;

  Future<void> _changeRole(
    BuildContext context,
    WidgetRef ref,
    WorkspaceMember member,
  ) async {
    final role = await pickNewRole(context, member);
    if (role == null || !context.mounted) return;

    await _run(
      context,
      ref,
      () => ref.read(membersRepositoryProvider).changeRole(member.id, role),
    );
  }

  Future<void> _remove(
    BuildContext context,
    WidgetRef ref,
    WorkspaceMember member,
  ) async {
    final t = ref.read(translatorProvider);

    final confirmed = await confirmAction(
      context,
      title: t('members.removeTitle'),
      body: t('members.removeBody', args: {'name': member.name}),
      confirmLabel: t('members.remove'),
    );
    if (!confirmed || !context.mounted) return;

    await _run(
      context,
      ref,
      () => ref.read(membersRepositoryProvider).removeMember(member.id),
    );
  }

  /// Runs a write, then refetches. Refetching rather than patching the list in
  /// place is deliberate: the server is the authority on who is a member, and a
  /// locally edited copy would disagree with it the moment two admins act at
  /// once.
  static Future<void> _run(
    BuildContext context,
    WidgetRef ref,
    Future<void> Function() action,
  ) async {
    try {
      await action();
      ref.invalidate(workspaceMembersProvider);
    } on MembershipException catch (error) {
      if (!context.mounted) return;
      _report(context, ref.read(translatorProvider)(error.translationKey));
    }
  }

  static void _report(BuildContext context, String message) {
    ScaffoldMessenger.of(context)
      ..clearSnackBars()
      ..showSnackBar(SnackBar(content: Text(message)));
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);

    return ListView(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 40),
      children: [
        NeonButton(
          key: const ValueKey('members-invite'),
          label: t('members.invite'),
          icon: Icons.person_add_alt_1_rounded,
          expand: true,
          onPressed: () => Navigator.of(context).push(
            MaterialPageRoute<void>(
              builder: (_) => const InviteMemberScreen(),
            ),
          ),
        ),
        const SizedBox(height: 22),
        SectionHeader(title: t('members.title')),
        if (members.isEmpty)
          _EmptyLine(text: t('members.empty'))
        else
          for (final member in members) ...[
            _MemberRow(
              member: member,
              locale: locale,
              onChangeRole: () => _changeRole(context, ref, member),
              onRemove: () => _remove(context, ref, member),
            ),
            const SizedBox(height: 10),
          ],
        const SizedBox(height: 14),
        const _InvitationsSection(),
      ],
    );
  }
}

class _MemberRow extends ConsumerWidget {
  const _MemberRow({
    required this.member,
    required this.locale,
    required this.onChangeRole,
    required this.onRemove,
  });

  final WorkspaceMember member;
  final AppLocale locale;
  final VoidCallback onChangeRole;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final accent = roleAccent(member.role, isDark: isDark);

    return NeonCardShell(
      key: ValueKey('member-${member.id}'),
      accent: accent,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      member.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        fontSize: 14.5,
                        fontWeight: FontWeight.w700,
                        color: isDark
                            ? NeonPalette.textPrimary
                            : NeonPalette.lightTextPrimary,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      member.email,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        fontSize: 11.5,
                        color: isDark
                            ? NeonPalette.textMuted
                            : NeonPalette.lightTextSecondary,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 10),
              RoleBadge(role: member.role),
            ],
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              Expanded(
                child: Text(
                  member.joinedAt == null
                      ? t('members.joinedUnknown')
                      : t(
                          'members.joined',
                          args: {
                            'date': DateFormatter.short(
                              member.joinedAt!,
                              locale,
                            ),
                          },
                        ),
                  style: TextStyle(
                    fontSize: 11,
                    color: isDark
                        ? NeonPalette.textMuted
                        : NeonPalette.lightTextSecondary,
                  ),
                ),
              ),
              // The server refuses to demote or remove the owner, so the row
              // says so instead of offering buttons that would only fail.
              if (member.isProtected)
                _ProtectedNote(text: t('members.ownerProtected'))
              else ...[
                _RowAction(
                  actionKey: 'member-role-${member.id}',
                  icon: Icons.tune_rounded,
                  tooltip: t('members.changeRole'),
                  accent: isDark ? NeonPalette.cyan : NeonPalette.lightCyan,
                  onPressed: onChangeRole,
                ),
                const SizedBox(width: 6),
                _RowAction(
                  actionKey: 'member-remove-${member.id}',
                  icon: Icons.person_remove_rounded,
                  tooltip: t('members.remove'),
                  accent:
                      isDark ? NeonPalette.magenta : NeonPalette.lightMagenta,
                  onPressed: onRemove,
                ),
              ],
            ],
          ),
        ],
      ),
    );
  }
}

class _ProtectedNote extends StatelessWidget {
  const _ProtectedNote({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Flexible(
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            Icons.lock_rounded,
            size: 13,
            color: isDark
                ? NeonPalette.textMuted
                : NeonPalette.lightTextSecondary,
          ),
          const SizedBox(width: 6),
          Flexible(
            child: Text(
              text,
              textAlign: TextAlign.end,
              style: TextStyle(
                fontSize: 10.5,
                color: isDark
                    ? NeonPalette.textMuted
                    : NeonPalette.lightTextSecondary,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _RowAction extends StatelessWidget {
  const _RowAction({
    required this.actionKey,
    required this.icon,
    required this.tooltip,
    required this.accent,
    required this.onPressed,
  });

  final String actionKey;
  final IconData icon;
  final String tooltip;
  final Color accent;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label: tooltip,
      child: GestureDetector(
        key: ValueKey(actionKey),
        onTap: onPressed,
        child: Container(
          width: 34,
          height: 34,
          decoration: BoxDecoration(
            color: accent.withValues(alpha: 0.10),
            borderRadius: BorderRadius.circular(NeonEffects.radiusSm),
            border: NeonEffects.border(accent, alpha: 0.30),
          ),
          child: Icon(icon, size: 17, color: accent),
        ),
      ),
    );
  }
}

class _InvitationsSection extends ConsumerWidget {
  const _InvitationsSection();

  Future<void> _revoke(
    BuildContext context,
    WidgetRef ref,
    WorkspaceInvitation invitation,
  ) async {
    final t = ref.read(translatorProvider);

    final confirmed = await confirmAction(
      context,
      title: t('members.revokeTitle'),
      body: t('members.revokeBody', args: {'email': invitation.email}),
      confirmLabel: t('members.revoke'),
    );
    if (!confirmed || !context.mounted) return;

    try {
      await ref.read(membersRepositoryProvider).revokeInvitation(invitation.id);
      ref.invalidate(workspaceInvitationsProvider);
    } on MembershipException catch (error) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context)
        ..clearSnackBars()
        ..showSnackBar(SnackBar(content: Text(t(error.translationKey))));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final invitations = ref.watch(workspaceInvitationsProvider);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SectionHeader(title: t('members.invitations')),
        invitations.when(
          loading: () => const ModuleLoading(),
          error: (error, _) => _EmptyLine(
            text: error is MembershipException
                ? t(error.translationKey)
                : t('common.error'),
          ),
          data: (list) => list.isEmpty
              ? _EmptyLine(text: t('members.invitationsEmpty'))
              : Column(
                  children: [
                    for (final invitation in list) ...[
                      _InvitationRow(
                        invitation: invitation,
                        locale: locale,
                        onRevoke: () => _revoke(context, ref, invitation),
                      ),
                      const SizedBox(height: 10),
                    ],
                  ],
                ),
        ),
      ],
    );
  }
}

class _InvitationRow extends ConsumerWidget {
  const _InvitationRow({
    required this.invitation,
    required this.locale,
    required this.onRevoke,
  });

  final WorkspaceInvitation invitation;
  final AppLocale locale;
  final VoidCallback onRevoke;

  /// Amber for the one still waiting on a human, muted for the three that are
  /// already settled — pending is the only status anyone can still act on.
  static Color _statusAccent(InvitationStatus status, {required bool isDark}) =>
      switch (status) {
        InvitationStatus.pending => NeonPalette.amber,
        InvitationStatus.accepted =>
          isDark ? NeonPalette.textSecondary : NeonPalette.lightTextSecondary,
        InvitationStatus.revoked || InvitationStatus.expired =>
          isDark ? NeonPalette.textMuted : NeonPalette.lightTextSecondary,
      };

  static String _statusKey(InvitationStatus status) => switch (status) {
        InvitationStatus.pending => 'members.statusPending',
        InvitationStatus.accepted => 'members.statusAccepted',
        InvitationStatus.revoked => 'members.statusRevoked',
        InvitationStatus.expired => 'members.statusExpired',
      };

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final accent = _statusAccent(invitation.status, isDark: isDark);

    return NeonCardShell(
      key: ValueKey('invitation-${invitation.id}'),
      accent: accent,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Text(
                  invitation.email,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontSize: 13.5,
                    fontWeight: FontWeight.w700,
                    color: isDark
                        ? NeonPalette.textPrimary
                        : NeonPalette.lightTextPrimary,
                  ),
                ),
              ),
              const SizedBox(width: 10),
              NeonChip(
                label: t(_statusKey(invitation.status)),
                accent: accent,
                selected: invitation.isPending,
              ),
            ],
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              RoleBadge(role: invitation.role),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  invitation.expiresAt == null
                      ? ''
                      : t(
                          'members.expires',
                          args: {
                            'date': DateFormatter.short(
                              invitation.expiresAt!,
                              locale,
                            ),
                          },
                        ),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontSize: 11,
                    color: isDark
                        ? NeonPalette.textMuted
                        : NeonPalette.lightTextSecondary,
                  ),
                ),
              ),
              if (invitation.isPending)
                _RowAction(
                  actionKey: 'invitation-revoke-${invitation.id}',
                  icon: Icons.link_off_rounded,
                  tooltip: t('members.revoke'),
                  accent:
                      isDark ? NeonPalette.magenta : NeonPalette.lightMagenta,
                  onPressed: onRevoke,
                ),
            ],
          ),
        ],
      ),
    );
  }
}

class _EmptyLine extends StatelessWidget {
  const _EmptyLine({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Padding(
      padding: const EdgeInsetsDirectional.symmetric(vertical: 14),
      child: Text(
        text,
        style: TextStyle(
          fontSize: 12.5,
          color: isDark
              ? NeonPalette.textMuted
              : NeonPalette.lightTextSecondary,
        ),
      ),
    );
  }
}
