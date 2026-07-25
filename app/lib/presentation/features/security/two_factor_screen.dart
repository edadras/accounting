import 'dart:async';

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
import 'two_factor_setup_screen.dart';

/// Where a signed-in user turns the second factor on and off.
///
/// The card is amber and lit while two-factor authentication is off, because
/// that is a live weakness in the account rather than a neutral setting.
class TwoFactorScreen extends ConsumerStatefulWidget {
  const TwoFactorScreen({super.key});

  static Route<void> route() => MaterialPageRoute<void>(
        builder: (_) => const TwoFactorScreen(),
      );

  @override
  ConsumerState<TwoFactorScreen> createState() => _TwoFactorScreenState();
}

class _TwoFactorScreenState extends ConsumerState<TwoFactorScreen> {
  final _passwordController = TextEditingController();
  final _codeController = TextEditingController();

  TwoFactorStatus? _status;
  Object? _error;
  bool _loading = true;
  bool _disabling = false;
  bool _busy = false;
  String? _noticeKey;

  @override
  void initState() {
    super.initState();
    unawaited(_load());
  }

  @override
  void dispose() {
    _passwordController.dispose();
    _codeController.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final status = await ref.read(securityRepositoryProvider).twoFactorStatus();
      if (!mounted) return;
      setState(() {
        _status = status;
        _loading = false;
      });
    } on Object catch (error) {
      if (!mounted) return;
      setState(() {
        _error = error;
        _loading = false;
      });
    }
  }

  Future<void> _openSetup() async {
    final enabled = await Navigator.of(context).push(
      TwoFactorSetupScreen.route(),
    );

    if (!mounted) return;
    if (enabled ?? false) setState(() => _noticeKey = 'security.twoFactorEnabled');
    await _load();
  }

  Future<void> _disable() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      await ref.read(securityRepositoryProvider).disableTwoFactor(
            password: _passwordController.text,
            code: _codeController.text,
          );
      if (!mounted) return;

      _passwordController.clear();
      _codeController.clear();
      setState(() {
        _busy = false;
        _disabling = false;
        _noticeKey = 'security.twoFactorDisabled';
      });
      await _load();
    } on Object catch (error) {
      if (!mounted) return;
      setState(() {
        _error = error;
        _busy = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider).code;
    final status = _status;

    return SecurityScaffold(
      title: t('security.title'),
      children: [
        SectionHeader(title: t('security.twoFactor')),
        if (_loading && status == null)
          const NeonCard(
            child: Center(
              child: Padding(
                padding: EdgeInsetsDirectional.all(12),
                child: CircularProgressIndicator(strokeWidth: 2),
              ),
            ),
          )
        else if (status == null)
          SecurityNotice.failure(message: securityErrorText(t, _error))
        else
          _StatusCard(
            status: status,
            locale: locale,
            onTurnOn: _openSetup,
            onTurnOff: () => setState(() {
              _disabling = true;
              _error = null;
              _noticeKey = null;
            }),
          ),
        if (_noticeKey != null && !_disabling) ...[
          const SizedBox(height: 14),
          SecurityNotice.success(message: t(_noticeKey!)),
        ],
        if (_disabling) ...[
          const SizedBox(height: 22),
          _DisableCard(
            passwordController: _passwordController,
            codeController: _codeController,
            busy: _busy,
            error: _error,
            onConfirm: _disable,
            onCancel: () => setState(() {
              _disabling = false;
              _error = null;
            }),
          ),
        ] else if (status != null && _error != null) ...[
          const SizedBox(height: 14),
          SecurityNotice.failure(message: securityErrorText(t, _error)),
        ],
      ],
    );
  }
}

class _StatusCard extends ConsumerWidget {
  const _StatusCard({
    required this.status,
    required this.locale,
    required this.onTurnOn,
    required this.onTurnOff,
  });

  final TwoFactorStatus status;
  final String locale;
  final VoidCallback onTurnOn;
  final VoidCallback onTurnOff;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final on = status.enabled;
    final accent = on ? NeonPalette.lime : NeonPalette.amber;

    final title = on
        ? t('security.twoFactorOn')
        : status.pendingConfirmation
            ? t('security.setupPending')
            : t('security.twoFactorOff');

    final hint = on
        ? t('security.twoFactorOnHint')
        : status.pendingConfirmation
            ? t('security.setupPendingHint')
            : t('security.twoFactorOffHint');

    return NeonCard(
      accent: accent,
      // Only the unprotected state glows: an account already carrying a second
      // factor is not news.
      glow: !on,
      glowIntensity: 0.6,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(
                on ? Icons.lock_rounded : Icons.lock_open_rounded,
                size: 18,
                color: accent,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  title,
                  style: TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.w800,
                    color: accent,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            hint,
            style: const TextStyle(
              fontSize: 13,
              height: 1.6,
              color: NeonPalette.textSecondary,
            ),
          ),
          if (on && status.recoveryCodesRemaining > 0) ...[
            const SizedBox(height: 10),
            Text(
              t(
                'security.recoveryRemaining',
                args: {
                  'count': DateFormatter.number(
                    status.recoveryCodesRemaining,
                    locale,
                  ),
                },
              ),
              style: const TextStyle(fontSize: 12, color: NeonPalette.textMuted),
            ),
          ],
          const SizedBox(height: 18),
          if (on)
            NeonButton(
              label: t('security.turnOff'),
              icon: Icons.lock_open_rounded,
              accent: NeonPalette.magenta,
              variant: NeonButtonVariant.outline,
              expand: true,
              onPressed: onTurnOff,
            )
          else
            NeonButton(
              label: status.pendingConfirmation
                  ? t('security.finishSetup')
                  : t('security.turnOn'),
              icon: Icons.shield_outlined,
              expand: true,
              onPressed: onTurnOn,
            ),
        ],
      ),
    );
  }
}

class _DisableCard extends ConsumerWidget {
  const _DisableCard({
    required this.passwordController,
    required this.codeController,
    required this.busy,
    required this.error,
    required this.onConfirm,
    required this.onCancel,
  });

  final TextEditingController passwordController;
  final TextEditingController codeController;
  final bool busy;
  final Object? error;
  final VoidCallback onConfirm;
  final VoidCallback onCancel;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);

    return NeonCard(
      accent: NeonPalette.magenta,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            t('security.disableTitle'),
            style: const TextStyle(
              fontSize: 15,
              fontWeight: FontWeight.w800,
              color: NeonPalette.magenta,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            t('security.disableHint'),
            style: const TextStyle(
              fontSize: 13,
              height: 1.6,
              color: NeonPalette.textSecondary,
            ),
          ),
          const SizedBox(height: 16),
          SecurityField(
            controller: passwordController,
            label: t('auth.password'),
            obscure: true,
            ltr: true,
          ),
          const SizedBox(height: 12),
          SecurityField(
            controller: codeController,
            label: t('security.currentCode'),
            ltr: true,
          ),
          if (error != null) ...[
            const SizedBox(height: 14),
            SecurityNotice.failure(message: securityErrorText(t, error)),
          ],
          const SizedBox(height: 16),
          Row(
            children: [
              Expanded(
                child: NeonButton(
                  label: t('security.disableConfirm'),
                  accent: NeonPalette.magenta,
                  expand: true,
                  busy: busy,
                  onPressed: onConfirm,
                ),
              ),
              const SizedBox(width: 10),
              NeonButton(
                label: t('common.cancel'),
                variant: NeonButtonVariant.ghost,
                onPressed: onCancel,
              ),
            ],
          ),
        ],
      ),
    );
  }
}
