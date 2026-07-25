import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/members_repository.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import '../more/module_scaffold.dart';
import 'members_providers.dart';
import 'role_badge.dart';

/// Email plus role, and then the one-time token.
class InviteMemberScreen extends ConsumerStatefulWidget {
  const InviteMemberScreen({super.key});

  @override
  ConsumerState<InviteMemberScreen> createState() => _InviteMemberScreenState();
}

class _InviteMemberScreenState extends ConsumerState<InviteMemberScreen> {
  final _email = TextEditingController();

  WorkspaceRole _role = WorkspaceRole.member;
  bool _busy = false;

  /// A translation key, never a server sentence.
  String? _errorKey;
  String? _validationKey;

  IssuedInvitation? _issued;

  // Deliberately loose. The server is the authority on what it will accept;
  // this only catches the obvious typo before spending a round trip on it.
  static final _emailShape = RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$');

  @override
  void dispose() {
    _email.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final email = _email.text.trim();

    final validation = switch (email) {
      '' => 'members.emailRequired',
      _ when !_emailShape.hasMatch(email) => 'members.emailInvalid',
      _ => null,
    };

    if (validation != null) {
      setState(() {
        _validationKey = validation;
        _errorKey = null;
      });
      return;
    }

    setState(() {
      _busy = true;
      _validationKey = null;
      _errorKey = null;
    });

    try {
      final issued = await ref
          .read(membersRepositoryProvider)
          .invite(email: email, role: _role);

      ref.invalidate(workspaceInvitationsProvider);

      if (!mounted) return;
      setState(() {
        _issued = issued;
        _busy = false;
      });
    } on MembershipException catch (error) {
      if (!mounted) return;
      setState(() {
        _errorKey = error.translationKey;
        _busy = false;
      });
    }
  }

  /// Clears the token from the screen once the user says they have it. Nothing
  /// keeps a copy — the server stored only a hash, and this widget is the last
  /// place the plaintext existed.
  void _acknowledge() {
    setState(() {
      _issued = null;
      _email.clear();
      _role = WorkspaceRole.member;
    });
  }

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);
    final issued = _issued;

    return ModulePage(
      title: t('members.inviteTitle'),
      subtitle: t('members.inviteSubtitle'),
      child: ListView(
        padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 40),
        children: [
          if (issued != null)
            _TokenPanel(issued: issued, onAcknowledge: _acknowledge)
          else ...[
            _Field(
              label: t('members.email'),
              child: TextField(
                key: const ValueKey('invite-email'),
                controller: _email,
                keyboardType: TextInputType.emailAddress,
                autocorrect: false,
                decoration: InputDecoration(hintText: t('members.emailHint')),
              ),
            ),
            if (_validationKey != null) _InlineError(message: t(_validationKey!)),
            const SizedBox(height: 20),
            SectionHeader(title: t('members.role')),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final role in WorkspaceRole.assignable)
                  GestureDetector(
                    key: ValueKey('invite-role-${role.name}'),
                    onTap: () => setState(() => _role = role),
                    child: RoleBadge(role: role, selected: role == _role),
                  ),
              ],
            ),
            const SizedBox(height: 24),
            NeonButton(
              key: const ValueKey('invite-send'),
              label: t('members.send'),
              icon: Icons.send_rounded,
              expand: true,
              busy: _busy,
              onPressed: _busy ? null : _submit,
            ),
            if (_errorKey != null) _InlineError(message: t(_errorKey!)),
          ],
        ],
      ),
    );
  }
}

/// The token, once.
///
/// Amber and glowing because this is a warning the user has one chance to act
/// on, not a success message — the invitation is already created either way.
class _TokenPanel extends ConsumerWidget {
  const _TokenPanel({required this.issued, required this.onAcknowledge});

  final IssuedInvitation issued;
  final VoidCallback onAcknowledge;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);

    return NeonCard(
      accent: NeonPalette.amber,
      glow: true,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            children: [
              const Icon(
                Icons.key_rounded,
                size: 18,
                color: NeonPalette.amber,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  t('members.tokenTitle'),
                  style: const TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.w800,
                    color: NeonPalette.textPrimary,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            t(
              'members.tokenIssuedFor',
              args: {'email': issued.invitation.email},
            ),
            style: const TextStyle(
              fontSize: 12,
              color: NeonPalette.textSecondary,
            ),
          ),
          const SizedBox(height: 14),
          Container(
            width: double.infinity,
            padding: const EdgeInsetsDirectional.all(14),
            decoration: BoxDecoration(
              color: NeonPalette.surfaceHigh,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(
                color: NeonPalette.amber.withValues(alpha: 0.3),
              ),
            ),
            child: SelectableText(
              issued.token,
              key: const ValueKey('invite-token'),
              // The token is ASCII and must stay readable as one run even on an
              // RTL page, where mixed content would otherwise be reordered.
              textDirection: TextDirection.ltr,
              style: const TextStyle(
                fontSize: 13,
                height: 1.5,
                letterSpacing: 0.6,
                fontWeight: FontWeight.w600,
                color: NeonPalette.textPrimary,
              ),
            ),
          ),
          const SizedBox(height: 12),
          Text(
            t('members.tokenWarning'),
            style: const TextStyle(
              fontSize: 12,
              height: 1.6,
              color: NeonPalette.amber,
            ),
          ),
          const SizedBox(height: 18),
          Row(
            children: [
              Expanded(
                child: NeonButton(
                  key: const ValueKey('invite-token-copy'),
                  label: t('members.tokenCopy'),
                  icon: Icons.copy_rounded,
                  accent: NeonPalette.amber,
                  variant: NeonButtonVariant.outline,
                  expand: true,
                  onPressed: () async {
                    await Clipboard.setData(ClipboardData(text: issued.token));
                    if (!context.mounted) return;
                    ScaffoldMessenger.of(context)
                      ..clearSnackBars()
                      ..showSnackBar(
                        SnackBar(content: Text(t('members.tokenCopied'))),
                      );
                  },
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: NeonButton(
                  key: const ValueKey('invite-token-ack'),
                  label: t('members.tokenAcknowledge'),
                  accent: NeonPalette.amber,
                  expand: true,
                  onPressed: onAcknowledge,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _Field extends StatelessWidget {
  const _Field({required this.label, required this.child});

  final String label;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: TextStyle(
            fontSize: 12,
            fontWeight: FontWeight.w600,
            color: isDark
                ? NeonPalette.textSecondary
                : NeonPalette.lightTextSecondary,
          ),
        ),
        const SizedBox(height: 8),
        child,
      ],
    );
  }
}

class _InlineError extends StatelessWidget {
  const _InlineError({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Padding(
      padding: const EdgeInsetsDirectional.only(top: 12),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            Icons.error_outline_rounded,
            size: 15,
            color: isDark ? NeonPalette.magenta : NeonPalette.lightMagenta,
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              message,
              style: TextStyle(
                fontSize: 12.5,
                height: 1.5,
                color:
                    isDark ? NeonPalette.magenta : NeonPalette.lightMagenta,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
