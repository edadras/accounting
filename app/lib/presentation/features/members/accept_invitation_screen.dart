import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/members_repository.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_card.dart';
import '../more/module_scaffold.dart';
import 'members_providers.dart';
import 'role_badge.dart';

/// Joining a workspace from a pasted token.
///
/// This is the one membership screen a non-member can reach: it runs before
/// membership exists, which is also why it does not touch the members list.
class AcceptInvitationScreen extends ConsumerStatefulWidget {
  const AcceptInvitationScreen({super.key});

  @override
  ConsumerState<AcceptInvitationScreen> createState() =>
      _AcceptInvitationScreenState();
}

class _AcceptInvitationScreenState
    extends ConsumerState<AcceptInvitationScreen> {
  final _token = TextEditingController();

  bool _busy = false;
  String? _errorKey;
  AcceptedInvitation? _accepted;

  @override
  void dispose() {
    _token.dispose();
    super.dispose();
  }

  Future<void> _accept() async {
    final token = _token.text.trim();

    if (token.isEmpty) {
      setState(() => _errorKey = 'members.acceptTokenRequired');
      return;
    }

    setState(() {
      _busy = true;
      _errorKey = null;
    });

    try {
      final accepted =
          await ref.read(membersRepositoryProvider).acceptInvitation(token);

      if (!mounted) return;
      setState(() {
        _accepted = accepted;
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

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);
    final accepted = _accepted;

    return ModulePage(
      title: t('members.acceptTitle'),
      subtitle: t('members.acceptSubtitle'),
      child: ListView(
        padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 40),
        children: [
          if (accepted != null)
            NeonCard(
              accent: NeonPalette.lime,
              child: Row(
                children: [
                  const Icon(
                    Icons.check_circle_rounded,
                    size: 20,
                    color: NeonPalette.lime,
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Text(
                      t(
                        'members.acceptDone',
                        args: {'role': roleLabel(t, accepted.role)},
                      ),
                      key: const ValueKey('accept-done'),
                      style: const TextStyle(
                        fontSize: 13,
                        height: 1.5,
                        color: NeonPalette.textPrimary,
                      ),
                    ),
                  ),
                ],
              ),
            )
          else ...[
            Text(
              t('members.acceptToken'),
              style: const TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w600,
                color: NeonPalette.textSecondary,
              ),
            ),
            const SizedBox(height: 8),
            TextField(
              key: const ValueKey('accept-token'),
              controller: _token,
              autocorrect: false,
              maxLines: 2,
              minLines: 1,
              // Tokens are ASCII; typing one into an RTL field would otherwise
              // display in an order that does not match what was pasted.
              textDirection: TextDirection.ltr,
              decoration: InputDecoration(
                hintText: t('members.acceptTokenHint'),
              ),
            ),
            const SizedBox(height: 20),
            NeonButton(
              key: const ValueKey('accept-submit'),
              label: t('members.acceptAction'),
              icon: Icons.login_rounded,
              expand: true,
              busy: _busy,
              onPressed: _busy ? null : _accept,
            ),
            if (_errorKey != null)
              Padding(
                padding: const EdgeInsetsDirectional.only(top: 14),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Icon(
                      Icons.error_outline_rounded,
                      size: 15,
                      color: NeonPalette.magenta,
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        t(_errorKey!),
                        style: const TextStyle(
                          fontSize: 12.5,
                          height: 1.5,
                          color: NeonPalette.magenta,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
          ],
        ],
      ),
    );
  }
}
