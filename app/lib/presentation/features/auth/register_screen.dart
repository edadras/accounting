import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import '../security/security_providers.dart';
import '../security/security_widgets.dart';
import 'auth_controller.dart';
import 'auth_form.dart';

/// A new account, which the server creates together with its first workspace —
/// an account with nowhere to record anything would be useless.
class RegisterScreen extends ConsumerStatefulWidget {
  const RegisterScreen({super.key});

  static Route<void> route() =>
      MaterialPageRoute<void>(builder: (_) => const RegisterScreen());

  @override
  ConsumerState<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends ConsumerState<RegisterScreen> {
  final _nameController = TextEditingController();
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  final _confirmController = TextEditingController();

  String? _localErrorKey;

  @override
  void dispose() {
    _nameController.dispose();
    _emailController.dispose();
    _passwordController.dispose();
    _confirmController.dispose();
    super.dispose();
  }

  static String? _problemWith({
    required String name,
    required String email,
    required String password,
    required String confirmation,
  }) {
    if (name.isEmpty) return 'auth.nameRequired';
    if (email.isEmpty) return 'auth.emailRequired';
    if (!emailLooksValid(email)) return 'auth.emailInvalid';
    if (password.isEmpty) return 'auth.passwordRequired';
    if (password.length < minimumPasswordLength) return 'auth.passwordTooShort';
    if (password != confirmation) return 'auth.passwordMismatch';
    return null;
  }

  Future<void> _submit() async {
    final name = _nameController.text.trim();
    final email = _emailController.text.trim();
    final password = _passwordController.text;

    final problem = _problemWith(
      name: name,
      email: email,
      password: password,
      confirmation: _confirmController.text,
    );
    if (problem != null) {
      setState(() => _localErrorKey = problem);
      return;
    }

    setState(() => _localErrorKey = null);
    await ref
        .read(authControllerProvider.notifier)
        .register(name: name, email: email, password: password);

    // The gate is already showing the app underneath; this screen was pushed
    // over it and has to take itself away.
    if (!mounted) return;
    if (ref.read(authControllerProvider).stage != AuthStage.signedOut) {
      unawaited(Navigator.of(context).maybePop());
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);
    final state = ref.watch(authControllerProvider);

    return SecurityScaffold(
      title: t('auth.createAccount'),
      children: [
        SectionHeader(title: t('auth.createAccount'), accent: NeonPalette.lime),
        NeonCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                t('auth.registerHint'),
                style: const TextStyle(
                  fontSize: 13,
                  height: 1.6,
                  color: NeonPalette.textSecondary,
                ),
              ),
              const SizedBox(height: 18),
              SecurityField(
                controller: _nameController,
                label: t('auth.name'),
              ),
              const SizedBox(height: 12),
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
              ),
              const SizedBox(height: 12),
              SecurityField(
                controller: _confirmController,
                label: t('auth.passwordConfirm'),
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
                label: t('auth.createAccount'),
                icon: Icons.person_add_alt_rounded,
                accent: NeonPalette.lime,
                expand: true,
                busy: state.busy,
                onPressed: _submit,
              ),
              const SizedBox(height: 10),
              NeonButton(
                label: t('auth.haveAccount'),
                variant: NeonButtonVariant.ghost,
                accent: NeonPalette.violet,
                expand: true,
                onPressed: () => Navigator.of(context).maybePop(),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
