import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import '../security/forgot_password_screen.dart';
import '../security/security_providers.dart';
import '../security/security_widgets.dart';
import 'auth_controller.dart';
import 'auth_form.dart';
import 'register_screen.dart';

/// Email and password, and the three ways out of here: register, recover, or
/// the second factor the server may ask for next.
class SignInScreen extends ConsumerStatefulWidget {
  const SignInScreen({super.key});

  static Route<void> route() =>
      MaterialPageRoute<void>(builder: (_) => const SignInScreen());

  @override
  ConsumerState<SignInScreen> createState() => _SignInScreenState();
}

class _SignInScreenState extends ConsumerState<SignInScreen> {
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();

  /// A refusal we can make without asking the server. Kept apart from the
  /// server's coded failure so one never overwrites the other's wording.
  String? _localErrorKey;

  @override
  void dispose() {
    _emailController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  static String? _problemWith(String email, String password) {
    if (email.isEmpty) return 'auth.emailRequired';
    if (!emailLooksValid(email)) return 'auth.emailInvalid';
    if (password.isEmpty) return 'auth.passwordRequired';
    return null;
  }

  Future<void> _submit() async {
    final email = _emailController.text.trim();
    final password = _passwordController.text;

    final problem = _problemWith(email, password);
    if (problem != null) {
      setState(() => _localErrorKey = problem);
      return;
    }

    setState(() => _localErrorKey = null);
    await ref
        .read(authControllerProvider.notifier)
        .signIn(email: email, password: password);
  }

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);
    final state = ref.watch(authControllerProvider);

    return SecurityScaffold(
      title: t('auth.signIn'),
      children: [
        SectionHeader(title: t('auth.signIn')),
        NeonCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                t('auth.signInHint'),
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
                controller: _passwordController,
                label: t('auth.password'),
                obscure: true,
                onSubmitted: _submit,
              ),
              if (_localErrorKey != null || state.error != null) ...[
                const SizedBox(height: 14),
                SecurityNotice.failure(
                  message: _localErrorKey != null
                      ? t(_localErrorKey!)
                      : securityErrorText(t, state.error),
                ),
              ],
              const SizedBox(height: 18),
              NeonButton(
                label: t('auth.signIn'),
                icon: Icons.login_rounded,
                expand: true,
                busy: state.busy,
                onPressed: _submit,
              ),
              const SizedBox(height: 10),
              NeonButton(
                label: t('auth.forgotPassword'),
                variant: NeonButtonVariant.ghost,
                accent: NeonPalette.violet,
                expand: true,
                onPressed: () => Navigator.of(context).push(
                  ForgotPasswordScreen.route(),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 20),
        NeonCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                t('auth.noAccount'),
                style: const TextStyle(
                  fontSize: 13,
                  color: NeonPalette.textSecondary,
                ),
              ),
              const SizedBox(height: 12),
              NeonButton(
                label: t('auth.createAccount'),
                icon: Icons.person_add_alt_rounded,
                variant: NeonButtonVariant.outline,
                accent: NeonPalette.lime,
                expand: true,
                onPressed: () =>
                    Navigator.of(context).push(RegisterScreen.route()),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
