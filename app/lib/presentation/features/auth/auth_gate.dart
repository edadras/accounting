import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../widgets/neon_widgets.dart';
import '../security/two_factor_challenge_screen.dart';
import 'auth_controller.dart';
import 'sign_in_screen.dart';
import 'workspace_picker_screen.dart';

/// Decides whether the app shows itself or asks who is asking.
///
/// Wrap the shell in it:
///
/// ```dart
/// home: const AuthGate(child: AppShell()),
/// ```
///
/// The question it asks first is *is there a backend at all*, not *is there a
/// token*. With no backend configured the app is running on demo data, there is
/// nothing to sign in to, and [child] is built immediately — the same first
/// frame the app has always had.
class AuthGate extends ConsumerWidget {
  const AuthGate({super.key, required this.child});

  /// What a signed-in person sees. Usually the shell.
  final Widget child;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (ref.watch(authBackendProvider) == null) return child;

    final state = ref.watch(authControllerProvider);
    final controller = ref.read(authControllerProvider.notifier);

    return switch (state.stage) {
      AuthStage.restoring => const AuthSplash(),
      AuthStage.signedOut => const SignInScreen(),
      // The challenge screen is handed the callbacks it was built to expect:
      // it owns the code, this owns where the app goes next.
      AuthStage.twoFactorRequired => TwoFactorChallengeScreen(
          challenge: state.challenge!,
          onVerified: (verification) {
            unawaited(controller.completeTwoFactor(verification));
          },
          onRestart: controller.restartSignIn,
        ),
      AuthStage.choosingWorkspace => const WorkspacePickerScreen(),
      AuthStage.signedIn => child,
    };
  }
}

/// The moment between "the app launched" and "we know who this is".
///
/// Reading a token off the keychain takes milliseconds, so this is usually one
/// frame; it exists so that frame is never the sign-in screen flashing at
/// someone who is already signed in.
class AuthSplash extends ConsumerWidget {
  const AuthSplash({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);

    return NeonBackdrop(
      child: Scaffold(
        backgroundColor: Colors.transparent,
        body: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const SizedBox(
                width: 28,
                height: 28,
                child: CircularProgressIndicator(strokeWidth: 2),
              ),
              const SizedBox(height: 18),
              Text(
                t('auth.restoring'),
                style: const TextStyle(
                  fontSize: 13,
                  color: NeonPalette.textSecondary,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
