import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import 'security_providers.dart';
import 'security_widgets.dart';

/// Spends a reset code on a new password.
///
/// The server answers the same way for a wrong, spent or expired code, so this
/// screen does too rather than inventing a distinction it cannot know.
class ResetPasswordScreen extends ConsumerStatefulWidget {
  const ResetPasswordScreen({super.key, this.email = '', this.token = ''});

  final String email;

  /// Prefilled when the reset link opened the app; typed by hand otherwise.
  final String token;

  static Route<void> route({String email = '', String token = ''}) =>
      MaterialPageRoute<void>(
        builder: (_) => ResetPasswordScreen(email: email, token: token),
      );

  @override
  ConsumerState<ResetPasswordScreen> createState() =>
      _ResetPasswordScreenState();
}

class _ResetPasswordScreenState extends ConsumerState<ResetPasswordScreen> {
  late final TextEditingController _emailController =
      TextEditingController(text: widget.email);
  late final TextEditingController _tokenController =
      TextEditingController(text: widget.token);
  final _passwordController = TextEditingController();

  static const _minimumPasswordLength = 8;

  bool _busy = false;
  bool _done = false;
  Object? _error;
  String? _localErrorKey;

  @override
  void dispose() {
    _emailController.dispose();
    _tokenController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final email = _emailController.text.trim();
    final token = _tokenController.text.trim();
    final password = _passwordController.text;

    final problem = switch (true) {
      _ when email.isEmpty => 'security.emailRequired',
      _ when token.isEmpty => 'security.tokenRequired',
      _ when password.length < _minimumPasswordLength =>
        'security.passwordTooShort',
      _ => null,
    };

    if (problem != null) {
      setState(() => _localErrorKey = problem);
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
      _localErrorKey = null;
    });

    try {
      await ref.read(securityRepositoryProvider).resetPassword(
            email: email,
            token: token,
            password: password,
          );
      if (!mounted) return;
      _passwordController.clear();
      setState(() {
        _busy = false;
        _done = true;
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
      title: t('security.resetTitle'),
      children: [
        if (_done)
          SecurityNotice.success(message: t('security.resetDone'))
        else ...[
          SectionHeader(title: t('security.resetTitle')),
          NeonCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  t('security.resetHint'),
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
                ),
                const SizedBox(height: 12),
                SecurityField(
                  controller: _tokenController,
                  label: t('security.resetCode'),
                  ltr: true,
                ),
                const SizedBox(height: 12),
                SecurityField(
                  controller: _passwordController,
                  label: t('security.newPassword'),
                  obscure: true,
                  ltr: true,
                  onSubmitted: _submit,
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
                  label: t('security.resetSubmit'),
                  icon: Icons.lock_reset_rounded,
                  expand: true,
                  busy: _busy,
                  onPressed: _submit,
                ),
              ],
            ),
          ),
        ],
      ],
    );
  }
}
