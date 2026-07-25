import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import 'reset_password_screen.dart';
import 'security_providers.dart';
import 'security_widgets.dart';

/// Asks for a reset link.
///
/// The answer is the same whether or not the address has an account, because
/// the server's answer is too — anything finer is a membership oracle. The copy
/// says so out loud, otherwise "if that address has an account" reads like the
/// app being unsure whether it sent anything.
class ForgotPasswordScreen extends ConsumerStatefulWidget {
  const ForgotPasswordScreen({super.key});

  static Route<void> route() => MaterialPageRoute<void>(
        builder: (_) => const ForgotPasswordScreen(),
      );

  @override
  ConsumerState<ForgotPasswordScreen> createState() =>
      _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends ConsumerState<ForgotPasswordScreen> {
  final _emailController = TextEditingController();

  bool _busy = false;
  bool _sent = false;
  Object? _error;
  String? _localErrorKey;

  @override
  void dispose() {
    _emailController.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    final email = _emailController.text.trim();
    if (email.isEmpty) {
      setState(() => _localErrorKey = 'security.emailRequired');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
      _localErrorKey = null;
    });

    try {
      await ref.read(securityRepositoryProvider).forgotPassword(email);
      if (!mounted) return;
      setState(() {
        _busy = false;
        _sent = true;
      });
    } on Object catch (error) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = error;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);

    return SecurityScaffold(
      title: t('security.forgotTitle'),
      children: [
        if (_sent) ...[
          SecurityNotice.success(
            message: t('security.forgotSent'),
            detail: t('security.forgotSameAnswer'),
          ),
          const SizedBox(height: 20),
          NeonButton(
            label: t('security.haveCode'),
            icon: Icons.password_rounded,
            expand: true,
            onPressed: () => Navigator.of(context).push(
              ResetPasswordScreen.route(email: _emailController.text.trim()),
            ),
          ),
        ] else ...[
          SectionHeader(title: t('security.forgotTitle')),
          NeonCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  t('security.forgotHint'),
                  style: const TextStyle(
                    fontSize: 13,
                    height: 1.6,
                    color: NeonPalette.textSecondary,
                  ),
                ),
                const SizedBox(height: 18),
                SecurityField(
                  controller: _emailController,
                  label: t('auth.email'),
                  email: true,
                  onSubmitted: _send,
                ),
                if (_localErrorKey != null || _error != null) ...[
                  const SizedBox(height: 14),
                  SecurityNotice.failure(
                    message: _localErrorKey != null
                        ? t(_localErrorKey!)
                        : securityErrorText(t, _error),
                  ),
                ],
                const SizedBox(height: 18),
                NeonButton(
                  label: t('security.forgotSend'),
                  icon: Icons.mail_outline_rounded,
                  expand: true,
                  busy: _busy,
                  onPressed: _send,
                ),
                const SizedBox(height: 10),
                NeonButton(
                  label: t('security.haveCode'),
                  variant: NeonButtonVariant.ghost,
                  accent: NeonPalette.violet,
                  expand: true,
                  onPressed: () => Navigator.of(context).push(
                    ResetPasswordScreen.route(
                      email: _emailController.text.trim(),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ],
    );
  }
}
