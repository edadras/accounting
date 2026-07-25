import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/security_repository.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import 'security_providers.dart';
import 'security_widgets.dart';

/// The second half of signing in.
///
/// The user arrives holding a challenge and nothing else: the password was
/// right, but `POST /auth/login` answered `two_factor_required` instead of a
/// token. Trading the challenge for a code produces the token here.
class TwoFactorChallengeScreen extends ConsumerStatefulWidget {
  const TwoFactorChallengeScreen({
    super.key,
    required this.challenge,
    this.onVerified,
    this.onRestart,
  });

  final LoginChallenge challenge;

  /// Called once with the token the challenge bought. Navigation after sign-in
  /// belongs to whoever pushed this screen, not to it.
  final void Function(TwoFactorVerification verification)? onVerified;

  /// Called when the challenge is dead and the only way forward is a fresh
  /// sign-in.
  final VoidCallback? onRestart;

  static Route<void> route(
    LoginChallenge challenge, {
    void Function(TwoFactorVerification verification)? onVerified,
    VoidCallback? onRestart,
  }) =>
      MaterialPageRoute<void>(
        builder: (_) => TwoFactorChallengeScreen(
          challenge: challenge,
          onVerified: onVerified,
          onRestart: onRestart,
        ),
      );

  @override
  ConsumerState<TwoFactorChallengeScreen> createState() =>
      _TwoFactorChallengeScreenState();
}

class _TwoFactorChallengeScreenState
    extends ConsumerState<TwoFactorChallengeScreen> {
  final _codeController = TextEditingController();

  bool _useRecoveryCode = false;
  bool _busy = false;
  bool _dead = false;
  int _attemptsLeft = LoginChallenge.maxAttempts;
  Object? _error;
  String? _localErrorKey;
  TwoFactorVerification? _verification;

  @override
  void dispose() {
    _codeController.dispose();
    super.dispose();
  }

  Future<void> _verify() async {
    final code = _codeController.text.trim();
    final minimum = _useRecoveryCode ? 1 : 6;

    if (code.length < minimum) {
      setState(() => _localErrorKey = 'security.codeRequired');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
      _localErrorKey = null;
    });

    try {
      final verification =
          await ref.read(securityRepositoryProvider).verifyChallenge(
                challenge: widget.challenge.token,
                code: code,
              );
      if (!mounted) return;

      setState(() {
        _verification = verification;
        _busy = false;
      });
      widget.onVerified?.call(verification);
    } on SecurityException catch (error) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = error;
        // The server never says how many tries are left — it would be a hint —
        // so the count is kept here, from the same ceiling the server uses.
        if (error.isRetryableCode) {
          _attemptsLeft = math.max(0, _attemptsLeft - 1);
        }
        _dead = error.isChallengeDead;
      });
    } on Object catch (error) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = error;
      });
    }
  }

  void _toggleMode() {
    setState(() {
      _useRecoveryCode = !_useRecoveryCode;
      _codeController.clear();
      _error = null;
      _localErrorKey = null;
    });
  }

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider).code;

    if (_verification != null) {
      return SecurityScaffold(
        title: t('security.challengeTitle'),
        children: [SecurityNotice.success(message: t('security.signedIn'))],
      );
    }

    if (_dead) {
      return SecurityScaffold(
        title: t('security.challengeTitle'),
        children: [
          SecurityNotice.failure(message: t('security.challengeDead')),
          const SizedBox(height: 20),
          NeonButton(
            label: t('security.backToSignIn'),
            icon: Icons.arrow_back_rounded,
            expand: true,
            onPressed: () {
              widget.onRestart?.call();
              Navigator.of(context).maybePop();
            },
          ),
        ],
      );
    }

    return SecurityScaffold(
      title: t('security.challengeTitle'),
      children: [
        SectionHeader(title: t('security.twoFactor')),
        NeonCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                _useRecoveryCode
                    ? t('security.recoveryCodeHint')
                    : t('security.challengeHint'),
                style: const TextStyle(
                  fontSize: 13,
                  height: 1.6,
                  color: NeonPalette.textSecondary,
                ),
              ),
              const SizedBox(height: 18),
              CodeField(
                controller: _codeController,
                label: _useRecoveryCode
                    ? t('security.recoveryCodeLabel')
                    : t('security.codeLabel'),
                digitsOnly: !_useRecoveryCode,
                maxLength: _useRecoveryCode ? 11 : 6,
                onSubmitted: _verify,
              ),
              if (_localErrorKey != null || _error != null) ...[
                const SizedBox(height: 14),
                SecurityNotice.failure(
                  message: _localErrorKey != null
                      ? t(_localErrorKey!)
                      : securityErrorText(t, _error),
                ),
              ],
              if (_attemptsLeft < LoginChallenge.maxAttempts) ...[
                const SizedBox(height: 10),
                Text(
                  t(
                    'security.attemptsLeft',
                    args: {
                      'count': DateFormatter.number(_attemptsLeft, locale),
                    },
                  ),
                  style: const TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                    color: NeonPalette.amber,
                  ),
                ),
              ],
              const SizedBox(height: 18),
              NeonButton(
                label: t('security.verify'),
                icon: Icons.login_rounded,
                expand: true,
                busy: _busy,
                onPressed: _verify,
              ),
              const SizedBox(height: 10),
              NeonButton(
                label: _useRecoveryCode
                    ? t('security.useAppCode')
                    : t('security.useRecoveryCode'),
                accent: NeonPalette.violet,
                variant: NeonButtonVariant.ghost,
                expand: true,
                onPressed: _toggleMode,
              ),
            ],
          ),
        ),
      ],
    );
  }
}
